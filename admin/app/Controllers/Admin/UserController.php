<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Models\Branch;
use App\Models\User;

final class UserController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'users.view');

        [$sortBy, $sortDir] = $request->sort(User::SORTABLE, 'name', 'ASC');
        $scoped = Auth::scopedBranchId();

        $users = User::paginate(
            $request->str('search'),
            $request->nullableInt('role_id'),
            $this->branchFilter($request),
            $request->str('status'),
            $sortBy,
            $sortDir,
            $request->page(),
            $this->perPage($request)
        );

        $this->view($request, 'users/index', [
            'title'    => 'Managers & Agents',
            'users'    => $users,
            'roles'    => User::roleOptions(Auth::isSuperAdmin()),
            'branches' => Branch::options($scoped),
            'filters'  => [
                'search'    => $request->str('search'),
                'role_id'   => $request->nullableInt('role_id'),
                'branch_id' => $this->branchFilter($request),
                'status'    => $request->str('status'),
            ],
            'sortBy'  => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function create(Request $request): void
    {
        $this->guard($request, 'users.create');

        $scoped = Auth::scopedBranchId();

        if (!$request->isPost()) {
            $this->view($request, 'users/form', [
                'title'         => 'Add user',
                'user'          => null,
                'roles'         => User::roleOptions(Auth::isSuperAdmin()),
                'branches'      => Branch::options($scoped),
                'generatedPass' => $this->suggestPassword(),
            ]);
        }

        $validator = $this->validate($request, null);
        if ($validator->fails()) {
            $this->backWithErrors('/users/create', $validator->errors(), $request->all());
        }

        $roleId = $request->int('role_id');
        $branchId = $this->resolveBranch($request, $roleId);

        if ($branchId === false) {
            $this->back('/users/create', 'danger', 'Choose a branch for this user.');
        }

        $password = (string) $request->input('password', '');
        if ($password === '') {
            $password = $this->suggestPassword();
        }

        $data = [
            'employee_code'        => strtoupper($request->str('employee_code')),
            'name'                => $request->str('name'),
            'email'               => $request->nullableStr('email'),
            'role_id'             => $roleId,
            'branch_id'           => $branchId,
            'bc_code'             => $request->nullableStr('bc_code'),
            'designation'         => $request->nullableStr('designation'),
            'status'              => $request->str('status') === 'suspended' ? 'suspended' : 'active',
            // New accounts must change the password given to them at first login.
            'must_change_password' => 1,
            'created_by'          => Auth::id(),
        ];

        $id = User::create($data, $password, $request->nullableStr('mobile'));

        Logger::audit('create', 'user', $id, null, $data, sprintf('Created user %s (%s)', $data['name'], $data['employee_code']));

        // Shown once so the administrator can hand it over; never stored in plain text.
        Session::flash('success', sprintf(
            'User <strong>%s</strong> created. Employee code <code>%s</code>, temporary password <code>%s</code>. '
            . 'They will be asked to change it at first sign-in.',
            e($data['name']),
            e($data['employee_code']),
            e($password)
        ));

        \App\Core\Response::redirect('/users');
    }

    public function edit(Request $request): void
    {
        $this->guard($request, 'users.update');

        $id = $request->paramInt('id');
        $user = User::find($id);

        if ($user === null) {
            $this->back('/users', 'danger', 'That user could not be found.');
        }
        $this->assertManageable($user);

        if (!$request->isPost()) {
            $this->view($request, 'users/form', [
                'title'    => 'Edit user',
                'user'     => $user,
                'roles'    => User::roleOptions(Auth::isSuperAdmin()),
                'branches' => Branch::options(Auth::scopedBranchId()),
                'mobile'   => User::decryptMobile($user),
            ]);
        }

        $validator = $this->validate($request, $id);
        if ($validator->fails()) {
            $this->backWithErrors('/users/' . $id . '/edit', $validator->errors(), $request->all());
        }

        $roleId = $request->int('role_id');
        $branchId = $this->resolveBranch($request, $roleId);
        if ($branchId === false) {
            $this->back('/users/' . $id . '/edit', 'danger', 'Choose a branch for this user.');
        }

        $data = [
            'employee_code' => strtoupper($request->str('employee_code')),
            'name'          => $request->str('name'),
            'email'         => $request->nullableStr('email'),
            'role_id'       => $roleId,
            'branch_id'     => $branchId,
            'bc_code'       => $request->nullableStr('bc_code'),
            'designation'   => $request->nullableStr('designation'),
            'status'        => $request->str('status') === 'suspended' ? 'suspended' : 'active',
        ];

        User::update($id, $data, $request->nullableStr('mobile'), true);

        Logger::auditDiff('user', $id, $user, $data, sprintf('Updated user %s', $data['name']));

        $this->back('/users', 'success', sprintf('User "%s" updated.', e($data['name'])));
    }

    public function resetPassword(Request $request): void
    {
        $this->guard($request, 'users.reset_password');

        $id = $request->paramInt('id');
        $user = User::find($id);

        if ($user === null) {
            $this->back('/users', 'danger', 'That user could not be found.');
        }
        $this->assertManageable($user);

        $password = trim((string) $request->input('password', ''));
        if ($password === '') {
            $password = $this->suggestPassword();
        }

        $minLength = max(6, Settings::int('password_min_length', 8));
        if (strlen($password) < $minLength) {
            $this->back('/users', 'danger', "The password must be at least {$minLength} characters.");
        }

        // Forces a change at next sign-in so the administrator never keeps a
        // working password for someone else's account.
        User::setPassword($id, $password, true);

        Logger::audit('login_reset', 'user', $id, null, null, sprintf('Reset password for %s', (string) $user['name']));

        Session::flash('success', sprintf(
            'Password reset for <strong>%s</strong>. New temporary password: <code>%s</code>. '
            . 'They must change it at next sign-in.',
            e((string) $user['name']),
            e($password)
        ));

        \App\Core\Response::redirect('/users');
    }

    public function status(Request $request): void
    {
        $this->guard($request, 'users.toggle_status');

        $id = $request->paramInt('id');
        $user = User::find($id);

        if ($user === null) {
            $this->back('/users', 'danger', 'That user could not be found.');
        }
        $this->assertManageable($user);

        if ($id === Auth::id()) {
            $this->back('/users', 'danger', 'You cannot change the status of your own account.');
        }

        $status = $request->str('status');
        if (!in_array($status, ['active', 'suspended', 'inactive'], true)) {
            $this->back('/users', 'danger', 'Choose a valid status.');
        }

        User::setStatus($id, $status);
        Logger::audit('update', 'user', $id, ['status' => $user['status']], ['status' => $status],
            sprintf('Set %s to %s', (string) $user['name'], $status));

        $this->back('/users', 'success', sprintf('%s is now %s.', e((string) $user['name']), e($status)));
    }

    public function delete(Request $request): void
    {
        $this->guard($request, 'users.delete');

        $id = $request->paramInt('id');
        $user = User::find($id);

        if ($user === null) {
            $this->back('/users', 'danger', 'That user could not be found.');
        }
        $this->assertManageable($user);

        if ($id === Auth::id()) {
            $this->back('/users', 'danger', 'You cannot delete your own account.');
        }

        // Visit history is append-only, so a user with reports is suspended, not
        // deleted - otherwise the audit trail would lose its author.
        $check = User::deletable($id);
        if (!$check['ok']) {
            $this->back('/users', 'danger', 'Cannot delete this user: ' . e($check['reason']));
        }

        User::delete($id);
        Logger::audit('delete', 'user', $id, $user, null, sprintf('Deleted user %s', (string) $user['name']));

        $this->back('/users', 'success', sprintf('User "%s" deleted.', e((string) $user['name'])));
    }

    // -----------------------------------------------------------------------

    private function validate(Request $request, ?int $ignoreId): Validator
    {
        $minLength = max(6, Settings::int('password_min_length', 8));
        $unique = 'required|max:40|unique:users,employee_code' . ($ignoreId === null ? '' : ',' . $ignoreId);

        $rules = [
            'employee_code' => $unique,
            'name'          => 'required|max:150',
            'email'         => 'nullable|email|max:190',
            'mobile'        => 'nullable|mobile',
            'role_id'       => 'required|integer|exists:roles,id',
            'bc_code'       => 'nullable|max:40',
            'designation'   => 'nullable|max:100',
            'status'        => 'required|in:active,suspended,inactive',
        ];

        // Password is optional on create (auto-generated) and on edit (unchanged).
        if (trim((string) $request->input('password', '')) !== '') {
            $rules['password'] = "min:{$minLength}";
        }

        return Validator::make($request->all(), $rules, [
            'employee_code' => 'Employee code',
            'role_id'       => 'Role',
            'bc_code'       => 'BC/DC code',
        ]);
    }

    /**
     * Super admins have no branch; everyone else must have one.
     *
     * @return int|null|false false signals a validation failure
     */
    private function resolveBranch(Request $request, int $roleId): int|null|false
    {
        $role = Database::instance()->first('SELECT slug FROM roles WHERE id = ? LIMIT 1', [$roleId]);
        $slug = $role === null ? '' : (string) $role['slug'];

        if ($slug === 'super_admin') {
            return null;
        }

        $scoped = Auth::scopedBranchId();
        if ($scoped !== null) {
            return $scoped;
        }

        $branchId = $request->nullableInt('branch_id');
        if ($branchId === null || $branchId <= 0) {
            return false;
        }

        return $branchId;
    }

    /**
     * A branch manager may only manage users inside their own branch, and may
     * never touch a super admin account.
     *
     * @param array<string,mixed> $user
     */
    private function assertManageable(array $user): void
    {
        if (!Auth::isSuperAdmin() && (string) ($user['role_slug'] ?? '') === 'super_admin') {
            $this->back('/users', 'danger', 'You cannot manage a Super Admin account.');
        }

        $scoped = Auth::scopedBranchId();
        if ($scoped !== null && (int) ($user['branch_id'] ?? 0) !== $scoped) {
            $this->back('/users', 'danger', 'That user belongs to another branch.');
        }
    }

    /** Readable but random temporary password. */
    private function suggestPassword(): string
    {
        $words = ['Field', 'Ledger', 'Branch', 'Recovery', 'Village', 'Account', 'Visit', 'Credit'];
        return $words[random_int(0, count($words) - 1)] . '@' . random_int(1000, 9999);
    }
}
