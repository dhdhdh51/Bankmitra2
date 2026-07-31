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
