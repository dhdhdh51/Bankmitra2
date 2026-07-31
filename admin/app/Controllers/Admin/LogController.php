<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Paginator;
use App\Core\Request;

final class LogController extends Controller
{
    /** Entity-level changes with old/new values. */
    public function audit(Request $request): void
    {
        $this->guard($request, 'logs.audit');

        $where = ['1 = 1'];
        $params = [];

        $search = $request->str('search');
        if ($search !== '') {
            $where[] = '(al.user_name LIKE ? OR al.entity_type LIKE ? OR al.entity_id LIKE ? OR al.summary LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $action = $request->str('action');
        if ($action !== '') {
            $where[] = 'al.action = ?';
            $params[] = $action;
        }

        $entityType = $request->str('entity_type');
        if ($entityType !== '') {
            $where[] = 'al.entity_type = ?';
            $params[] = $entityType;
        }

        $userId = $request->nullableInt('user_id');
        if ($userId !== null && $userId > 0) {
            $where[] = 'al.user_id = ?';
            $params[] = $userId;
        }

        $from = $request->str('date_from');
        if ($from !== '') {
            $where[] = 'al.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = $request->str('date_to');
        if ($to !== '') {
            $where[] = 'al.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $clause = implode(' AND ', $where);

        $logs = Paginator::fromQuery(
            "SELECT COUNT(*) FROM audit_logs al WHERE {$clause}",
            "SELECT al.* FROM audit_logs al WHERE {$clause} ORDER BY al.created_at DESC, al.id DESC",
            $params,
            $request->page(),
            $this->perPage($request)
        );

        $this->view($request, 'logs/audit', [
            'title'       => 'Audit Logs',
            'logs'        => $logs,
            'actions'     => ['create', 'update', 'delete', 'import', 'assign', 'reassign', 'transfer', 'restore', 'backup', 'login_reset'],
            'entityTypes' => $this->distinct('audit_logs', 'entity_type'),
            'users'       => $this->logUsers('audit_logs'),
            'filters'     => [
                'search'      => $search,
                'action'      => $action,
                'entity_type' => $entityType,
                'user_id'     => $userId,
                'date_from'   => $from,
                'date_to'     => $to,
            ],
        ]);
    }

    /** Login/logout, exports and page-level actions. */
    public function activity(Request $request): void
    {
        $this->guard($request, 'logs.activity');

        $where = ['1 = 1'];
        $params = [];

        $search = $request->str('search');
        if ($search !== '') {
            $where[] = '(al.user_name LIKE ? OR al.description LIKE ? OR al.url LIKE ? OR al.ip LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $activity = $request->str('activity');
        if ($activity !== '') {
            $where[] = 'al.activity = ?';
            $params[] = $activity;
        }

        $userId = $request->nullableInt('user_id');
        if ($userId !== null && $userId > 0) {
            $where[] = 'al.user_id = ?';
            $params[] = $userId;
        }

        $from = $request->str('date_from');
        if ($from !== '') {
            $where[] = 'al.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = $request->str('date_to');
        if ($to !== '') {
            $where[] = 'al.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $clause = implode(' AND ', $where);

        $logs = Paginator::fromQuery(
            "SELECT COUNT(*) FROM activity_logs al WHERE {$clause}",
            "SELECT al.* FROM activity_logs al WHERE {$clause} ORDER BY al.created_at DESC, al.id DESC",
            $params,
            $request->page(),
            $this->perPage($request)
        );

        $this->view($request, 'logs/activity', [
            'title'      => 'Activity Logs',
            'logs'       => $logs,
            'activities' => $this->distinct('activity_logs', 'activity'),
            'users'      => $this->logUsers('activity_logs'),
            'filters'    => [
                'search'    => $search,
                'activity'  => $activity,
                'user_id'   => $userId,
                'date_from' => $from,
                'date_to'   => $to,
            ],
        ]);
    }

    // -----------------------------------------------------------------------

    /**
     * Distinct values for a filter dropdown.
     * The table/column pair is a literal in this class, never user input.
     *
     * @return list<string>
     */
    private function distinct(string $table, string $column): array
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1 || preg_match('/^[a-z_]+$/', $column) !== 1) {
            return [];
        }

        $rows = Database::instance()->all(
            "SELECT DISTINCT `{$column}` AS value FROM `{$table}`
              WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''
              ORDER BY value ASC LIMIT 100"
        );

        return array_map(static fn (array $r): string => (string) $r['value'], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function logUsers(string $table): array
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            return [];
        }

        return Database::instance()->all(
            "SELECT DISTINCT al.user_id AS id, al.user_name AS name
               FROM `{$table}` al
              WHERE al.user_id IS NOT NULL
              ORDER BY al.user_name ASC LIMIT 200"
        );
    }
}
