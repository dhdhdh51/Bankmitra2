<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;

final class Branch
{
    /** Columns a user may sort by. Anything else falls back to the default. */
    public const SORTABLE = ['branch_code', 'name', 'district', 'state', 'status', 'created_at'];

    public static function find(int $id): ?array
    {
        return Database::instance()->first('SELECT * FROM branches WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByCode(string $code): ?array
    {
        return Database::instance()->first('SELECT * FROM branches WHERE branch_code = ? LIMIT 1', [$code]);
    }

    /** @return list<array<string,mixed>> */
    public static function allActive(): array
    {
        return Database::instance()->all(
            "SELECT id, branch_code, name FROM branches WHERE status = 'active' ORDER BY name ASC"
        );
    }

    /**
     * Options for a branch <select>. A branch manager only ever sees their own.
     *
     * @return list<array<string,mixed>>
     */
    public static function options(?int $scopedBranchId = null): array
    {
        if ($scopedBranchId !== null) {
            return Database::instance()->all(
                'SELECT id, branch_code, name FROM branches WHERE id = ? ORDER BY name ASC',
                [$scopedBranchId]
            );
        }
        return self::allActive();
    }

    public static function paginate(string $search, string $status, string $sortBy, string $sortDir, int $page, int $perPage): Paginator
    {
        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(b.branch_code LIKE ? OR b.name LIKE ? OR b.district LIKE ? OR b.state LIKE ? OR b.pincode LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }

        $clause = implode(' AND ', $where);
        $orderColumn = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'name';
        $direction = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return Paginator::fromQuery(
            "SELECT COUNT(*) FROM branches b WHERE {$clause}",
            "SELECT b.*,
                    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.status = 'active') AS user_count,
                    (SELECT COUNT(*) FROM loan_accounts la WHERE la.branch_id = b.id) AS lead_count
               FROM branches b
              WHERE {$clause}
              ORDER BY b.`{$orderColumn}` {$direction}, b.id DESC",
            $params,
            $page,
            $perPage
        );
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::instance()->insert('branches', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::instance()->update('branches', $data, ['id' => $id]);
    }

    /**
     * A branch is only removable when nothing references it, otherwise the FK
     * would fail with an opaque database error.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function deletable(int $id): array
    {
        $db = Database::instance();

        $users = (int) $db->scalar('SELECT COUNT(*) FROM users WHERE branch_id = ?', [$id]);
        if ($users > 0) {
            return ['ok' => false, 'reason' => "{$users} user(s) are still assigned to this branch."];
        }

        $leads = (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts WHERE branch_id = ?', [$id]);
        if ($leads > 0) {
            return ['ok' => false, 'reason' => "{$leads} loan account(s) belong to this branch."];
        }

        $customers = (int) $db->scalar('SELECT COUNT(*) FROM customers WHERE branch_id = ?', [$id]);
        if ($customers > 0) {
            return ['ok' => false, 'reason' => "{$customers} customer(s) belong to this branch."];
        }

        return ['ok' => true, 'reason' => ''];
    }

    public static function delete(int $id): void
    {
        Database::instance()->delete('branches', ['id' => $id]);
    }

    public static function countAll(): int
    {
        return (int) Database::instance()->scalar('SELECT COUNT(*) FROM branches');
    }
}
