<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;

final class Promise
{
    public const STATUSES = ['pending', 'kept', 'broken', 'cancelled'];

    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT p.*, la.loan_account_number, c.name AS customer_name, c.village, c.address,
                    ag.name AS agent_name, b.name AS branch_name
               FROM promises p
               JOIN loan_accounts la ON la.id = p.loan_account_id
               JOIN customers c ON c.id = p.customer_id
               JOIN users ag ON ag.id = p.agent_id
               JOIN branches b ON b.id = p.branch_id
              WHERE p.id = ? LIMIT 1',
            [$id]
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::instance()->insert('promises', $data);
    }

    /** @return list<array<string,mixed>> */
    public static function forLoanAccount(int $loanAccountId): array
    {
        return Database::instance()->all(
            'SELECT p.*, ag.name AS agent_name
               FROM promises p
               JOIN users ag ON ag.id = p.agent_id
              WHERE p.loan_account_id = ?
              ORDER BY p.created_at DESC',
            [$loanAccountId]
        );
    }

    /**
     * @param array{branch_id?:int|null, agent_id?:int|null, status?:string,
     *              date_from?:string, date_to?:string, search?:string} $filters
     */
    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        [$clause, $params] = self::buildWhere($filters);

        return Paginator::fromQuery(
            "SELECT COUNT(*)
               FROM promises p
               JOIN loan_accounts la ON la.id = p.loan_account_id
               JOIN customers c ON c.id = p.customer_id
              WHERE {$clause}",
            "SELECT p.*, la.loan_account_number, la.outstanding_amount, la.overdue_amount,
                    c.name AS customer_name, c.village, c.address, c.mobile_masked,
                    ag.name AS agent_name, b.name AS branch_name,
                    DATEDIFF(CURDATE(), p.promise_date) AS days_overdue
               FROM promises p
               JOIN loan_accounts la ON la.id = p.loan_account_id
               JOIN customers c ON c.id = p.customer_id
               JOIN users ag ON ag.id = p.agent_id
               JOIN branches b ON b.id = p.branch_id
              WHERE {$clause}
              ORDER BY p.promise_date ASC, p.id DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    public static function buildWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (!empty($filters['branch_id'])) {
            $where[] = 'p.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['agent_id'])) {
            $where[] = 'p.agent_id = ?';
            $params[] = (int) $filters['agent_id'];
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }

        $from = trim((string) ($filters['date_from'] ?? ''));
        if ($from !== '') {
            $where[] = 'p.promise_date >= ?';
            $params[] = $from;
        }

        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($to !== '') {
            $where[] = 'p.promise_date <= ?';
            $params[] = $to;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(la.loan_account_number LIKE ? OR c.name LIKE ?)';
            array_push($params, $like, $like);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Marks a promise kept/broken/cancelled and appends the matching timeline
     * event. Returns false when the promise does not exist.
     */
    public static function settle(int $id, string $status, ?int $actorId, ?string $actorName, ?string $notes): bool
    {
        if (!in_array($status, ['kept', 'broken', 'cancelled'], true)) {
            return false;
        }

        $promise = self::find($id);
        if ($promise === null) {
            return false;
        }

        $db = Database::instance();

        $db->transaction(static function () use ($db, $promise, $id, $status, $actorId, $actorName, $notes): void {
            $db->update('promises', [
                'status'     => $status,
                'settled_at' => date('Y-m-d H:i:s'),
                'settled_by' => $actorId,
                'notes'      => $notes,
            ], ['id' => $id]);

            $eventType = match ($status) {
                'kept'   => 'promise_kept',
                'broken' => 'promise_broken',
                default  => 'status_changed',
            };

            $title = match ($status) {
                'kept'   => 'Promise kept',
                'broken' => 'Promise broken',
                default  => 'Promise cancelled',
            };

            Timeline::record(
                (int) $promise['loan_account_id'],
                $eventType,
                $title,
                sprintf(
                    'Promise of %s due %s marked %s.',
                    number_format((float) $promise['promise_amount'], 2),
                    (string) $promise['promise_date'],
                    $status
                ),
                $actorId,
                $actorName,
                null,
                $id,
                ['notes' => $notes]
            );

            // A broken promise pushes the lead back into follow-up so it
            // resurfaces in the agent's queue instead of looking settled.
            if ($status === 'broken') {
                $db->query(
                    "UPDATE loan_accounts SET current_status = 'followup' WHERE id = ? AND current_status <> 'closed'",
                    [(int) $promise['loan_account_id']]
                );
            }
        });

        return true;
    }

    /**
     * Promises whose date has passed while still pending. Used by the dashboard
     * and by the reminder cron.
     *
     * @return list<array<string,mixed>>
     */
    public static function overdue(?int $branchId, int $limit = 50): array
    {
        $sql = "SELECT p.*, la.loan_account_number, c.name AS customer_name, ag.name AS agent_name,
                       DATEDIFF(CURDATE(), p.promise_date) AS days_overdue
                  FROM promises p
                  JOIN loan_accounts la ON la.id = p.loan_account_id
                  JOIN customers c ON c.id = p.customer_id
                  JOIN users ag ON ag.id = p.agent_id
                 WHERE p.status = 'pending' AND p.promise_date < CURDATE()";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND p.branch_id = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY p.promise_date ASC LIMIT ' . max(1, min(200, $limit));

        return Database::instance()->all($sql, $params);
    }

    /** @return array<string,int> */
    public static function statusCounts(?int $branchId): array
    {
        $sql = 'SELECT status, COUNT(*) AS total FROM promises';
        $params = [];

        if ($branchId !== null) {
            $sql .= ' WHERE branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' GROUP BY status';

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Database::instance()->all($sql, $params) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
