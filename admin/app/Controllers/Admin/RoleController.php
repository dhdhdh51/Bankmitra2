<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;

final class RoleController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'roles.view');

        $db = Database::instance();

        $roles = $db->all(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r
              ORDER BY r.id ASC'
        );

        $permissions = $db->all('SELECT * FROM permissions ORDER BY module ASC, id ASC');

        // module => permissions, for the matrix layout.
        $grouped = [];
        foreach ($permissions as $permission) {
            $grouped[(string) $permission['module']][] = $permission;
        }

        // role_id => [permission_id => true]
        $assigned = [];
        foreach ($db->all('SELECT role_id, permission_id FROM role_permissions') as $row) {
            $assigned[(int) $row['role_id']][(int) $row['permission_id']] = true;
        }

        $selectedRoleId = $request->nullableInt('role_id') ?? (int) ($roles[0]['id'] ?? 0);

        $this->view($request, 'roles/index', [
            'title'          => 'Roles & Permissions',
            'roles'          => $roles,
            'grouped'        => $grouped,
            'assigned'       => $assigned,
            'selectedRoleId' => $selectedRoleId,
            'canManage'      => \App\Core\Auth::can('roles.manage'),
        ]);
    }

    public function update(Request $request): void
    {
        $this->guard($request, 'roles.manage');

        $roleId = $request->paramInt('id');
        $db = Database::instance();

        $role = $db->first('SELECT * FROM roles WHERE id = ? LIMIT 1', [$roleId]);
        if ($role === null) {
            $this->back('/roles', 'danger', 'That role could not be found.');
        }

        // The super admin role is intentionally immutable: revoking a permission
        // there could lock every administrator out of the system.
        if ((string) $role['slug'] === 'super_admin') {
            $this->back('/roles?role_id=' . $roleId, 'warning',
                'The Super Admin role always holds every permission and cannot be edited.');
        }

        $submitted = $request->intArr('permissions');

        // Only accept ids that actually exist.
        $valid = [];
        if ($submitted !== []) {
            $placeholders = implode(',', array_fill(0, count($submitted), '?'));
            foreach ($db->all("SELECT id FROM permissions WHERE id IN ({$placeholders})", $submitted) as $row) {
                $valid[] = (int) $row['id'];
            }
        }

        $before = array_map(
            static fn (array $r): int => (int) $r['permission_id'],
            $db->all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId])
        );

        $db->transaction(static function () use ($db, $roleId, $valid): void {
            $db->delete('role_permissions', ['role_id' => $roleId]);
            foreach ($valid as $permissionId) {
                $db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        });

        sort($before);
        $after = $valid;
        sort($after);

        Logger::audit(
            'update',
            'role',
            $roleId,
            ['permission_count' => count($before)],
            ['permission_count' => count($after)],
            sprintf('Updated permissions for %s (%d granted)', (string) $role['display_name'], count($after))
        );

        $this->back('/roles?role_id=' . $roleId, 'success', sprintf(
            'Permissions for "%s" updated &mdash; %d granted.',
            e((string) $role['display_name']),
            count($after)
        ));
    }
}
