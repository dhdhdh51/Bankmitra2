<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Models\LoanAccount;
use App\Models\Notification;
use App\Models\Timeline;

/**
 * Bulk assign / reassign / transfer / close for leads.
 *
 * Every operation:
 *   - enforces branch isolation (a branch manager cannot touch other branches)
 *   - validates that the target agent belongs to the lead's branch
 *   - appends a timeline event per lead
 *   - writes one audit row for the batch
 *   - notifies the receiving agent
 */
final class AssignmentService
{
    /**
     * @param list<int> $leadIds
     * @return array{updated:int, skipped:int, messages:list<string>}
     */
    public static function assign(array $leadIds, int $agentId, bool $isReassign = false): array
    {
        $db = Database::instance();

        $agent = $db->first(
            "SELECT u.id, u.name, u.branch_id, u.employee_code
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? AND r.slug = 'agent' AND u.status = 'active' LIMIT 1",
            [$agentId]
        );

        if ($agent === null) {
            return ['updated' => 0, 'skipped' => count($leadIds), 'messages' => ['The selected agent is not an active BC/DC agent.']];
        }

        $agentBranchId = $agent['branch_id'] === null ? null : (int) $agent['branch_id'];

        // A branch manager may only assign within their own branch.
        if (!Auth::canAccessBranch($agentBranchId)) {
            return ['updated' => 0, 'skipped' => count($leadIds), 'messages' => ['That agent belongs to another branch.']];
        }

        $leads = LoanAccount::findManyForAction($leadIds);
        $actorId = Auth::id();
        $actorName = (string) (Auth::user()['name'] ?? 'System');

        $updated = 0;
        $skipped = 0;
        $messages = [];
        $notify = [];

        $db->transaction(static function () use (
            $db,
            $leads,
            $agent,
            $agentBranchId,
            $actorId,
            $actorName,
            $isReassign,
            &$updated,
            &$skipped,
            &$messages,
            &$notify
        ): void {
            foreach ($leads as $lead) {
                $leadId = (int) $lead['id'];
                $leadBranchId = (int) $lead['branch_id'];
                $previousAgentId = $lead['assigned_agent_id'] === null ? null : (int) $lead['assigned_agent_id'];

                if (!Auth::canAccessBranch($leadBranchId)) {
                    $skipped++;
                    $messages[] = sprintf('%s belongs to another branch.', (string) $lead['loan_account_number']);
                    continue;
                }

                // Cross-branch assignment is a transfer, not an assignment.
                if ($agentBranchId !== null && $agentBranchId !== $leadBranchId) {
                    $skipped++;
                    $messages[] = sprintf(
                        '%s is in a different branch to %s. Use Transfer instead.',
                        (string) $lead['loan_account_number'],
                        (string) $agent['name']
                    );
                    continue;
                }

                if ($previousAgentId === (int) $agent['id']) {
                    $skipped++;
                    continue; // already assigned to this agent - no-op, no timeline noise
                }

                if ((string) $lead['current_status'] === 'closed') {
                    $skipped++;
                    $messages[] = sprintf('%s is closed and was not reassigned.', (string) $lead['loan_account_number']);
                    continue;
                }

                $db->update('loan_accounts', [
                    'assigned_agent_id' => (int) $agent['id'],
                    'assigned_at'       => date('Y-m-d H:i:s'),
                    'assigned_by'       => $actorId,
                ], ['id' => $leadId]);

                Timeline::record(
                    $leadId,
                    $previousAgentId === null ? 'assigned' : 'reassigned',
                    $previousAgentId === null ? 'Assigned to agent' : 'Reassigned to another agent',
                    sprintf('Now with %s (%s).', (string) $agent['name'], (string) $agent['employee_code']),
                    $actorId,
                    $actorName,
                    null,
                    null,
                    ['agent_id' => (int) $agent['id'], 'previous_agent_id' => $previousAgentId]
                );

                $notify[] = ['id' => $leadId, 'account' => (string) $lead['loan_account_number']];
                $updated++;
            }
        });

        if ($notify !== []) {
            $count = count($notify);
            Notification::send(
                (int) $agent['id'],
                'new_lead_assigned',
                $count === 1 ? 'New lead assigned' : "{$count} leads assigned",
                $count === 1
                    ? sprintf('Loan account %s has been assigned to you.', $notify[0]['account'])
                    : sprintf('%d leads have been assigned to you.', $count),
                $count === 1 ? $notify[0]['id'] : null,
                ['count' => $count],
                $actorId
            );
        }

        if ($updated > 0) {
            Logger::audit(
                $isReassign ? 'reassign' : 'assign',
                'loan_account',
                null,
                null,
                ['agent_id' => (int) $agent['id'], 'lead_count' => $updated],
                sprintf('%s %d lead(s) to %s', $isReassign ? 'Reassigned' : 'Assigned', $updated, (string) $agent['name'])
            );
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'messages' => array_values(array_unique($messages))];
    }

    /**
     * The active agents of a branch and how much each is already carrying.
     *
     * Returned as `[agentId => ['name' => ..., 'open' => int]]`, ordered by id so the
     * result is stable. Agents with nothing assigned are included with a zero, which is
     * the whole point - they are exactly who the next lead should go to.
     *
     * "Carrying" counts open leads, not every lead they have ever touched. Closed
     * accounts are finished work, and counting them would keep punishing whoever
     * recovered the most.
     *
     * @return array<int,array{name:string,open:int}>
     */
    public static function agentWorkload(int $branchId): array
    {
        $db = Database::instance();

        $agents = $db->all(
            "SELECT u.id, u.name
               FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.branch_id = ? AND r.slug = 'agent' AND u.status = 'active'
              ORDER BY u.id",
            [$branchId]
        );

        if ($agents === []) {
            return [];
        }

        $workload = [];
        foreach ($agents as $agent) {
            $workload[(int) $agent['id']] = ['name' => (string) $agent['name'], 'open' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($workload), '?'));
        $counts = $db->all(
            "SELECT assigned_agent_id, COUNT(*) AS open_leads
               FROM loan_accounts
              WHERE assigned_agent_id IN ({$placeholders})
                AND current_status <> 'closed'
              GROUP BY assigned_agent_id",
            array_keys($workload)
        );

        foreach ($counts as $row) {
            $id = (int) $row['assigned_agent_id'];
            if (isset($workload[$id])) {
                $workload[$id]['open'] = (int) $row['open_leads'];
            }
        }

        return $workload;
    }

    /**
     * Whoever in this workload is carrying the least, or null if there is nobody.
     *
     * Ties break on the lowest agent id, which makes a distribution run reproducible -
     * important when somebody re-imports the same file to check what it would do.
     *
     * @param array<int,array{name:string,open:int}> $workload
     */
    public static function lightestAgent(array $workload): ?int
    {
        $pick = null;
        $lowest = PHP_INT_MAX;

        foreach ($workload as $agentId => $agent) {
            if ($agent['open'] < $lowest) {
                $lowest = $agent['open'];
                $pick = $agentId;
            }
        }

        return $pick;
    }

    /**
     * Spreads leads evenly across the active agents of each lead's own branch.
     *
     * Balances TOTAL open workload rather than dealing the selected rows out in turn.
     * Round-robin within one batch looks fair and is not: import fifty leads on Monday
     * and fifty on Tuesday and both batches start at the same agent, so the first agent
     * in the branch ends up carrying every other lead in the branch. Starting from what
     * each agent already holds is the only version of "equally" that is still true after
     * the second import.
     *
     * Leads are grouped by branch because a branch's agents can only take that branch's
     * leads - a mixed selection is several independent distributions, not one.
     *
     * @param  list<int> $leadIds
     * @return array{updated:int,skipped:int,messages:list<string>}
     */
    public static function distribute(array $leadIds): array
    {
        $db = Database::instance();
        $leads = LoanAccount::findManyForAction($leadIds);
        $actorId = Auth::id();
        $actorName = (string) (Auth::user()['name'] ?? 'System');

        $updated = 0;
        $skipped = 0;
        $messages = [];
        $notify = [];

        /** @var array<int,array<int,array{name:string,open:int}>> $workloadByBranch */
        $workloadByBranch = [];

        $db->transaction(static function () use (
            $db,
            $leads,
            $actorId,
            $actorName,
            &$updated,
            &$skipped,
            &$messages,
            &$notify,
            &$workloadByBranch
        ): void {
            foreach ($leads as $lead) {
                $leadId = (int) $lead['id'];
                $branchId = (int) $lead['branch_id'];

                if (!Auth::canAccessBranch($branchId)) {
                    $skipped++;
                    continue;
                }

                if ((string) $lead['current_status'] === 'closed') {
                    $skipped++;
                    $messages[] = sprintf('%s is closed and was not distributed.', (string) $lead['loan_account_number']);
                    continue;
                }

                if (!isset($workloadByBranch[$branchId])) {
                    $workloadByBranch[$branchId] = self::agentWorkload($branchId);

                    // The leads about to be placed are already inside that count, and
                    // leaving them there double-counts every one that stays where it is:
                    // the holder looks busier than they are, the run pushes the next lead
                    // to somebody else, and it overshoots. Taking them out first makes
                    // the tally mean "everything except what we are placing", which is
                    // the only reading that is consistent for a lead that moves AND a
                    // lead that keeps its agent.
                    foreach ($leads as $pending) {
                        if ((int) $pending['branch_id'] !== $branchId
                            || (string) $pending['current_status'] === 'closed') {
                            continue;
                        }

                        $holder = $pending['assigned_agent_id'] === null
                            ? null
                            : (int) $pending['assigned_agent_id'];

                        if ($holder !== null && isset($workloadByBranch[$branchId][$holder])) {
                            $workloadByBranch[$branchId][$holder]['open'] = max(
                                0,
                                $workloadByBranch[$branchId][$holder]['open'] - 1
                            );
                        }
                    }
                }

                if ($workloadByBranch[$branchId] === []) {
                    $skipped++;
                    $messages[] = sprintf(
                        '%s was not distributed: its branch has no active BC/DC agent.',
                        (string) $lead['loan_account_number']
                    );
                    continue;
                }

                $agentId = self::lightestAgent($workloadByBranch[$branchId]);
                if ($agentId === null) {
                    $skipped++;
                    continue;
                }

                $previousAgentId = $lead['assigned_agent_id'] === null ? null : (int) $lead['assigned_agent_id'];

                // Every placement is a plain increment now, because the lead was taken
                // out of the tally before the run started.
                $workloadByBranch[$branchId][$agentId]['open']++;

                if ($previousAgentId === $agentId) {
                    // Already with the right person. Counted as done rather than skipped:
                    // the caller asked for an even spread, and this lead is part of one.
                    $updated++;
                    continue;
                }

                $db->update('loan_accounts', [
                    'assigned_agent_id' => $agentId,
                    'assigned_at'       => date('Y-m-d H:i:s'),
                    'assigned_by'       => $actorId,
                ], ['id' => $leadId]);

                Timeline::record(
                    $leadId,
                    $previousAgentId === null ? 'assigned' : 'reassigned',
                    $previousAgentId === null ? 'Assigned to agent' : 'Reassigned to another agent',
                    sprintf(
                        'Now with %s, by even distribution across the branch.',
                        $workloadByBranch[$branchId][$agentId]['name']
                    ),
                    $actorId,
                    $actorName,
                    null,
                    null,
                    ['agent_id' => $agentId, 'previous_agent_id' => $previousAgentId, 'mode' => 'even_distribution']
                );

                $notify[$agentId][] = (string) $lead['loan_account_number'];
                $updated++;
            }
        });

        foreach ($notify as $agentId => $accounts) {
            $count = count($accounts);
            Notification::send(
                (int) $agentId,
                'new_lead_assigned',
                $count === 1 ? 'New lead assigned' : "{$count} leads assigned",
                $count === 1
                    ? sprintf('Loan account %s has been assigned to you.', $accounts[0])
                    : sprintf('%d leads have been assigned to you.', $count),
                null,
                ['count' => $count],
                $actorId
            );
        }

        if ($updated > 0) {
            $spread = [];
            foreach ($workloadByBranch as $branchWorkload) {
                foreach ($branchWorkload as $agent) {
                    $spread[] = $agent['name'] . ': ' . $agent['open'];
                }
            }

            $messages[] = 'Open leads per agent after distribution - ' . implode(', ', $spread) . '.';

            Logger::audit(
                'assign',
                'loan_account',
                null,
                null,
                ['lead_count' => $updated, 'mode' => 'even_distribution'],
                sprintf('Distributed %d lead(s) evenly across their branch agents', $updated)
            );
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'messages' => array_values(array_unique($messages))];
    }

    /**
     * Moves leads to another branch. Only a super admin can do this, because it
     * crosses the branch isolation boundary by definition.
     *
     * @param list<int> $leadIds
     * @return array{updated:int, skipped:int, messages:list<string>}
     */
    public static function transfer(array $leadIds, int $targetBranchId, bool $clearAgent = true): array
    {
        $db = Database::instance();

        $branch = $db->first('SELECT id, name, branch_code FROM branches WHERE id = ? LIMIT 1', [$targetBranchId]);
        if ($branch === null) {
            return ['updated' => 0, 'skipped' => count($leadIds), 'messages' => ['The destination branch does not exist.']];
        }

        $leads = LoanAccount::findManyForAction($leadIds);
        $actorId = Auth::id();
        $actorName = (string) (Auth::user()['name'] ?? 'System');

        $updated = 0;
        $skipped = 0;
        $messages = [];

        $db->transaction(static function () use (
            $db,
            $leads,
            $branch,
            $targetBranchId,
            $clearAgent,
            $actorId,
            $actorName,
            &$updated,
            &$skipped,
            &$messages
        ): void {
            foreach ($leads as $lead) {
                $leadId = (int) $lead['id'];

                if ((int) $lead['branch_id'] === $targetBranchId) {
                    $skipped++;
                    continue;
                }

                $data = ['branch_id' => $targetBranchId];

                // The current agent belongs to the old branch, so keeping the
                // assignment would leave the lead invisible to both branches.
                if ($clearAgent) {
                    $data['assigned_agent_id'] = null;
                    $data['assigned_at'] = null;
                    $data['assigned_by'] = null;
                }

                $db->update('loan_accounts', $data, ['id' => $leadId]);

                // The borrower record follows the loan so branch scoping on
                // customers stays consistent with the lead.
                $db->query(
                    'UPDATE customers SET branch_id = ? WHERE id = ?',
                    [$targetBranchId, (int) $lead['customer_id']]
                );

                Timeline::record(
                    $leadId,
                    'transferred',
                    'Transferred to another branch',
                    sprintf('Moved to %s (%s).%s', (string) $branch['name'], (string) $branch['branch_code'], $clearAgent ? ' Agent assignment cleared.' : ''),
                    $actorId,
                    $actorName,
                    null,
                    null,
                    ['from_branch_id' => (int) $lead['branch_id'], 'to_branch_id' => $targetBranchId]
                );

                $updated++;
            }
        });

        if ($updated > 0) {
            Logger::audit(
                'transfer',
                'loan_account',
                null,
                null,
                ['to_branch_id' => $targetBranchId, 'lead_count' => $updated],
                sprintf('Transferred %d lead(s) to %s', $updated, (string) $branch['name'])
            );
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'messages' => array_values(array_unique($messages))];
    }

    /**
     * Unassigns leads without deleting anything.
     *
     * @param list<int> $leadIds
     * @return array{updated:int, skipped:int, messages:list<string>}
     */
    public static function unassign(array $leadIds): array
    {
        $db = Database::instance();
        $leads = LoanAccount::findManyForAction($leadIds);
        $actorId = Auth::id();
        $actorName = (string) (Auth::user()['name'] ?? 'System');

        $updated = 0;
        $skipped = 0;

        $db->transaction(static function () use ($db, $leads, $actorId, $actorName, &$updated, &$skipped): void {
            foreach ($leads as $lead) {
                if (!Auth::canAccessBranch((int) $lead['branch_id']) || $lead['assigned_agent_id'] === null) {
                    $skipped++;
                    continue;
                }

                $db->update('loan_accounts', [
                    'assigned_agent_id' => null,
                    'assigned_at'       => null,
                    'assigned_by'       => null,
                ], ['id' => (int) $lead['id']]);

                Timeline::record(
                    (int) $lead['id'],
                    'status_changed',
                    'Assignment removed',
                    'The lead is back in the unassigned pool.',
                    $actorId,
                    $actorName
                );

                $updated++;
            }
        });

        if ($updated > 0) {
            Logger::audit('update', 'loan_account', null, null, ['lead_count' => $updated], "Unassigned {$updated} lead(s)");
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'messages' => []];
    }

    /**
     * Closes or reopens leads.
     *
     * @param list<int> $leadIds
     * @return array{updated:int, skipped:int, messages:list<string>}
     */
    public static function setStatus(array $leadIds, string $status, ?string $note = null): array
    {
        if (!in_array($status, LoanAccount::STATUSES, true)) {
            return ['updated' => 0, 'skipped' => count($leadIds), 'messages' => ['Unknown status.']];
        }

        $db = Database::instance();
        $leads = LoanAccount::findManyForAction($leadIds);
        $actorId = Auth::id();
        $actorName = (string) (Auth::user()['name'] ?? 'System');

        $updated = 0;
        $skipped = 0;
        $messages = [];

        $db->transaction(static function () use ($db, $leads, $status, $note, $actorId, $actorName, &$updated, &$skipped, &$messages): void {
            foreach ($leads as $lead) {
                if (!Auth::canAccessBranch((int) $lead['branch_id'])) {
                    $skipped++;
                    $messages[] = sprintf('%s belongs to another branch.', (string) $lead['loan_account_number']);
                    continue;
                }
                if ((string) $lead['current_status'] === $status) {
                    $skipped++;
                    continue;
                }

                $db->update('loan_accounts', [
                    'current_status' => $status,
                    'closed_at'      => $status === 'closed' ? date('Y-m-d H:i:s') : null,
                ], ['id' => (int) $lead['id']]);

                $wasClosed = (string) $lead['current_status'] === 'closed';

                Timeline::record(
                    (int) $lead['id'],
                    $status === 'closed' ? 'closed' : ($wasClosed ? 'reopened' : 'status_changed'),
                    $status === 'closed' ? 'Lead closed' : ($wasClosed ? 'Lead reopened' : 'Status changed'),
                    $note !== null && $note !== ''
                        ? $note
                        : sprintf('Status changed from %s to %s.', (string) $lead['current_status'], $status),
                    $actorId,
                    $actorName,
                    null,
                    null,
                    ['from' => (string) $lead['current_status'], 'to' => $status]
                );

                $updated++;
            }
        });

        if ($updated > 0) {
            Logger::audit('update', 'loan_account', null, null, ['status' => $status, 'lead_count' => $updated], "Set {$updated} lead(s) to {$status}");
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'messages' => array_values(array_unique($messages))];
    }
}
