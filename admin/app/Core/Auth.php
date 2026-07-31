<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Unified authentication for both surfaces:
 *   - Admin panel: PHP session
 *   - REST API:    Bearer JWT (Authorization header)
 *
 * Permissions come from role_permissions and are cached per request.
 */
final class Auth
{
    private const SESSION_USER_ID = '_auth_user_id';

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    /** @var list<string>|null */
    private static ?array $permissions = null;
    private static bool $resolved = false;

    // -----------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------

    /**
     * Resolves the current user from the JWT (API) or session (panel).
     * Safe to call repeatedly.
     */
    public static function resolve(Request $request): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        // API first: a Bearer token always wins over an ambient session cookie.
        $token = $request->bearerToken();
        if ($token !== null) {
            $claims = Jwt::decode($token);
            if ($claims !== null && isset($claims['sub'])) {
                self::$user = self::loadActiveUser((int) $claims['sub']);
                return self::$user;
            }
            return null;
        }

        $userId = Session::get(self::SESSION_USER_ID);
        if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
            self::$user = self::loadActiveUser((int) $userId);
        }

        return self::$user;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::$user['id']) ? (int) self::$user['id'] : null;
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function role(): ?string
    {
        return isset(self::$user['role_slug']) ? (string) self::$user['role_slug'] : null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::role() === 'super_admin';
    }

    public static function isAgent(): bool
    {
        return self::role() === 'agent';
    }

    public static function isBranchManager(): bool
    {
        return self::role() === 'branch_manager';
    }

    /** Branch the current user is limited to, or null for unrestricted. */
    public static function scopedBranchId(): ?int
    {
        if (self::isSuperAdmin()) {
            return null;
        }
        $branchId = self::$user['branch_id'] ?? null;
        return $branchId === null ? null : (int) $branchId;
    }

    // -----------------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------------

    /** @return list<string> */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        if (self::$user === null) {
            return self::$permissions = [];
        }

        $rows = Database::instance()->all(
            'SELECT p.code
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ?',
            [(int) self::$user['role_id']]
        );

        return self::$permissions = array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    public static function can(string $permission): bool
    {
        if (self::$user === null) {
            return false;
        }
        // Super admin bypass keeps new modules working without a migration.
        if (self::isSuperAdmin()) {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    /** @param list<string> $permissions */
    public static function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::can($permission)) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Login / logout
    // -----------------------------------------------------------------------

    /**
     * Verifies credentials without creating a session.
     *
     * @return array{user:array<string,mixed>|null, error:string|null}
     */
    public static function attempt(string $identifier, string $password, string $ip): array
    {
        $user = self::findByIdentifier($identifier);

        if ($user === null) {
            // Constant-ish work factor so a missing user is not obviously faster.
            password_verify($password, '$2y$12$usesomesillystringfore.HAoi7bIRTBBnLzOZ4vXsFCNPGz4pO');
            self::logFailure($identifier, $ip, 'unknown identifier');
            return ['user' => null, 'error' => 'Invalid employee code or password.'];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
            return ['user' => null, 'error' => "Account temporarily locked. Try again in {$minutes} minute(s)."];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::registerFailedAttempt($user);
            self::logFailure($identifier, $ip, 'bad password');
            return ['user' => null, 'error' => 'Invalid employee code or password.'];
        }

        if ((string) $user['status'] !== 'active') {
            return ['user' => null, 'error' => 'This account is ' . $user['status'] . '. Contact your administrator.'];
        }

        // Transparent bcrypt cost upgrade on successful login.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
            Database::instance()->update(
                'users',
                ['password_hash' => password_hash($password, PASSWORD_BCRYPT)],
                ['id' => (int) $user['id']]
            );
        }

        Database::instance()->query(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [$ip, (int) $user['id']]
        );

        $fresh = self::loadActiveUser((int) $user['id']);
        return ['user' => $fresh, 'error' => null];
    }

    /** Establishes the admin-panel session. */
    public static function loginSession(array $user): void
    {
        Session::regenerate();
        Session::set(self::SESSION_USER_ID, (int) $user['id']);
        self::$user = $user;
        self::$permissions = null;
        self::$resolved = true;
    }

    /**
     * Marks a user as current for the remainder of this request without creating
     * a session. Used by the API right after issuing a JWT, so helpers such as
     * can() and permissions() work while building the login response.
     *
     * @param array<string,mixed> $user
     */
    public static function loginSessionless(array $user): void
    {
        self::$user = $user;
        self::$permissions = null;
        self::$resolved = true;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$permissions = null;
        self::$resolved = true;
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /** Admin panel guard: redirect to login when unauthenticated. */
    public static function requirePanel(Request $request): void
    {
        self::resolve($request);

        if (!self::check()) {
            Session::flash('warning', 'Please sign in to continue.');
            Response::redirect('/login');
        }

        // Agents have no admin panel surface at all.
        if (self::isAgent()) {
            Response::html(self::agentBlockedPage(), 403);
        }

        // Force the first-login password change before anything else loads.
        if ((int) (self::$user['must_change_password'] ?? 0) === 1
            && !in_array($request->path(), ['/change-password', '/logout'], true)) {
            Response::redirect('/change-password');
        }
    }

    /** API guard: 401 JSON when the JWT is missing/invalid. */
    public static function requireApi(Request $request): array
    {
        self::resolve($request);

        if (!self::check()) {
            Response::unauthorized('Session expired. Please sign in again.');
        }

        /** @var array<string,mixed> $user */
        $user = self::$user;
        return $user;
    }

    public static function requirePermission(string $permission): void
    {
        if (self::can($permission)) {
            return;
        }
        Response::forbidden('You do not have permission to perform this action.');
    }

    /** Panel variant: flash + redirect instead of a bare JSON 403. */
    public static function requirePermissionPanel(string $permission, string $redirectTo = '/dashboard'): void
    {
        if (self::can($permission)) {
            return;
        }
        Session::flash('danger', 'You do not have permission to access that section.');
        Response::redirect($redirectTo);
    }

    /**
     * Branch isolation: a branch manager may only touch their own branch.
     */
    public static function assertBranchAccess(?int $branchId): void
    {
        $scoped = self::scopedBranchId();
        if ($scoped === null) {
            return;
        }
        if ($branchId === null || $branchId !== $scoped) {
            Response::forbidden('This record belongs to another branch.');
        }
    }

    public static function canAccessBranch(?int $branchId): bool
    {
        $scoped = self::scopedBranchId();
        return $scoped === null || ($branchId !== null && $branchId === $scoped);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function loadActiveUser(int $id): ?array
    {
        $row = Database::instance()->first(
            'SELECT u.*, r.slug AS role_slug, r.display_name AS role_name,
                    b.name AS branch_name, b.branch_code
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN branches b ON b.id = u.branch_id
              WHERE u.id = ? AND u.status = ?
              LIMIT 1',
            [$id, 'active']
        );

        return $row;
    }

    /** Looks up by employee code first, then by hashed mobile. */
    private static function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $row = Database::instance()->first(
            'SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.employee_code = ? LIMIT 1',
            [$identifier]
        );
        if ($row !== null) {
            return $row;
        }

        $hash = Crypto::searchHash($identifier);
        if ($hash === null) {
            return null;
        }

        return Database::instance()->first(
            'SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.mobile_hash = ? LIMIT 1',
            [$hash]
        );
    }

    private static function registerFailedAttempt(array $user): void
    {
        $max = max(3, Settings::int('max_login_attempts', 5));
        $lockMinutes = max(1, Settings::int('lockout_minutes', 15));
        $attempts = (int) $user['failed_attempts'] + 1;

        if ($attempts >= $max) {
            Database::instance()->query(
                'UPDATE users SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
                [$attempts, $lockMinutes, (int) $user['id']]
            );
            return;
        }

        Database::instance()->query(
            'UPDATE users SET failed_attempts = ? WHERE id = ?',
            [$attempts, (int) $user['id']]
        );
    }

    private static function logFailure(string $identifier, string $ip, string $reason): void
    {
        Logger::activity(
            'failed_login',
            'Auth',
            sprintf('Failed sign-in for "%s" from %s (%s)', mb_substr($identifier, 0, 60), $ip, $reason)
        );
    }

    private static function agentBlockedPage(): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Mobile app only</title></head><body style="margin:0;font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fa;color:#1c2128">'
            . '<div style="max-width:520px;margin:14vh auto;padding:32px;background:#fff;border:1px solid #e2e5ea;border-radius:10px;box-shadow:0 1px 3px rgba(28,33,40,.06)">'
            . '<h1 style="margin:0 0 10px;font-size:19px;color:#123f8f">Use the LRMS mobile app</h1>'
            . '<p style="margin:0 0 16px;color:#4b5563">BC/DC Agent accounts work in the Android app only. '
            . 'The web admin panel is reserved for administrators and branch managers.</p>'
            . '<a href="' . htmlspecialchars(Url::path('/logout'), ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#1957c2;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600">Sign out</a>'
            . '</div></body></html>';
    }
}
