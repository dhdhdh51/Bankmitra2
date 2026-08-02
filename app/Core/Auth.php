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

    /**
     * The agent the current user is limited to their own records of, or null.
     *
     * Branch scope is not enough for an agent. A branch has several of them, and one
     * agent reading - let alone editing - a colleague's borrower is not something the
     * job needs. Returns null for everyone else, so callers can use it as "add this
     * predicate if there is one".
     */
    public static function scopedAgentId(): ?int
    {
        if (!self::isAgent()) {
            return null;
        }

        $id = self::$user['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * Where this user lands after signing in.
     *
     * An agent has no dashboard in the panel - theirs is in the app - so sending them
     * to /dashboard would show them the refusal page immediately after a successful
     * login, which reads as a broken sign-in rather than a deliberate boundary.
     */
    public static function panelHome(): string
    {
        return self::isAgent() ? '/customers' : '/dashboard';
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
            return ['user' => null, 'error' => 'Invalid credentials. Check your employee code or email address and password.'];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $minutes = (int) ceil((strtotime((string) $user['locked_until']) - time()) / 60);
            return ['user' => null, 'error' => "Account temporarily locked. Try again in {$minutes} minute(s)."];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            self::registerFailedAttempt($user);
            self::logFailure($identifier, $ip, 'bad password');
            return ['user' => null, 'error' => 'Invalid credentials. Check your employee code or email address and password.'];
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

    /**
     * Admin panel guard: redirect to login when unauthenticated.
     *
     * @param bool $allowAgent Whether a BC/DC agent may reach this route.
     *
     * Agents used to be refused the whole panel. They now have a deliberately narrow
     * surface - their own leads, and the custom fields they collect against them -
     * because the alternative was worse: an agent who spots a wrong father's name at a
     * doorstep either gets it corrected by ringing somebody at the branch, or it stays
     * wrong. Neither of those is better than letting them fix their own record.
     *
     * The flag defaults to false so a new route is closed to agents until somebody
     * decides otherwise. Fail-closed is the only safe default here: forgetting the flag
     * hides a screen, forgetting to *add* a check would expose one.
     */
    public static function requirePanel(Request $request, bool $allowAgent = false): void
    {
        self::resolve($request);

        if (!self::check()) {
            Session::flash('warning', 'Please sign in to continue.');
            Response::redirect('/login');
        }

        if (self::isAgent() && !$allowAgent) {
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
    public static function requirePermissionPanel(string $permission, ?string $redirectTo = null): void
    {
        if (self::can($permission)) {
            return;
        }
        Session::flash('danger', 'You do not have permission to access that section.');
        // Defaults to wherever this user is allowed to be. Hard-coding /dashboard sent
        // a refused agent to a page that refuses agents.
        Response::redirect($redirectTo ?? self::panelHome());
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

        // Email is a first-class login identifier: office staff know their email
        // address, not their employee code. The column collation is
        // case-insensitive, so no normalisation is needed here.
        if (str_contains($identifier, '@')) {
            $matches = Database::instance()->all(
                'SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.email = ? LIMIT 2',
                [$identifier]
            );

            // The schema makes email unique, but a database restored from before
            // that constraint could still hold duplicates. Refusing is the only
            // safe answer - signing somebody into whichever row sorted first would
            // be an authentication bug, not an inconvenience.
            if (count($matches) > 1) {
                error_log(sprintf(
                    '[D2R] refusing email login: %d accounts share the address %s',
                    count($matches),
                    $identifier
                ));
                return null;
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
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

    /**
     * Issues a password-reset OTP and delivers it.
     *
     * Email is tried first and SMS is the fallback. Email is the better channel
     * here: it costs nothing, it is not silently dropped by a DND registry the way
     * transactional SMS can be, and office staff have an address on file more
     * often than a verified mobile.
     *
     * Any earlier unused OTP for the user is invalidated first, so a stale code
     * from a previous request cannot be replayed.
     *
     * Shared by the panel and the API deliberately - the two had a copy each, and
     * a fix to one would have quietly missed the other.
     *
     * @param  array<string,mixed> $user
     * @return array{sent:bool, channel:string, destination:string|null, expires_in:int}
     *         `destination` is already masked and safe to show a user.
     */
    public static function issuePasswordOtp(array $user, string $ip): array
    {
        $db = Database::instance();
        $expiryMinutes = max(2, Settings::int('otp_expiry_minutes', 10));
        $otp = Crypto::numericOtp(6);

        $email = trim((string) ($user['email'] ?? ''));
        $mobile = Crypto::decrypt($user['mobile_enc'] ?? null);

        $useEmail = $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && Notifier::smtpConfigured();
        $useSms = !$useEmail && $mobile !== null && Notifier::smsConfigured();

        if (!$useEmail && !$useSms) {
            Logger::activity(
                'password_reset_request',
                'Auth',
                sprintf(
                    'Reset requested for user #%d but no usable channel (email=%s, sms=%s) - needs an admin reset',
                    (int) $user['id'],
                    $email === '' ? 'none' : (Notifier::smtpConfigured() ? 'invalid' : 'smtp not configured'),
                    $mobile === null ? 'no mobile' : 'gateway not configured'
                )
            );
            return ['sent' => false, 'channel' => 'admin', 'destination' => null, 'expires_in' => $expiryMinutes];
        }

        $db->query(
            'UPDATE password_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL',
            [(int) $user['id']]
        );

        $channel = $useEmail ? 'email' : 'sms';

        $db->insert('password_otps', [
            'user_id'    => (int) $user['id'],
            'otp_hash'   => hash('sha256', $otp),
            'channel'    => $channel,
            'expires_at' => date('Y-m-d H:i:s', time() + ($expiryMinutes * 60)),
            'ip'         => $ip,
        ]);

        $sent = $useEmail
            ? Notifier::sendOtpEmail($email, $otp, $expiryMinutes)
            : Notifier::sendOtpSms((string) $mobile, $otp);

        Logger::activity(
            'password_reset_otp',
            'Auth',
            sprintf(
                'OTP issued for user #%d over %s (%s)',
                (int) $user['id'],
                $channel,
                $sent ? 'delivered' : 'delivery failed'
            )
        );

        return [
            'sent'        => $sent,
            'channel'     => $channel,
            'destination' => $useEmail ? self::maskEmail($email) : Crypto::maskMobile((string) $mobile),
            'expires_in'  => $expiryMinutes,
        ];
    }

    /**
     * Masks an address for display: `shivam@example.com` -> `sh****@example.com`.
     *
     * Enough for the user to recognise which of their addresses received the code,
     * without confirming a full address to somebody probing the reset form.
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '****';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $keep = $local === '' ? '' : substr($local, 0, min(2, strlen($local)));

        return $keep . str_repeat('*', max(2, strlen($local) - strlen($keep))) . $domain;
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
            . '<h1 style="margin:0 0 10px;font-size:19px;color:#071d40">Use the D2 Recovery Solutions & Services mobile app</h1>'
            . '<p style="margin:0 0 16px;color:#4b5563">This section is for administrators and branch '
            . 'managers. As a BC/DC agent you can open <strong>your own borrowers</strong> here to correct '
            . 'their details and add fields, and everything else - visits, photographs, signatures, '
            . 'promises - is in the Android app.</p>'
            . '<a href="' . htmlspecialchars(Url::path('/customers'), ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#0b2a5b;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600;margin-right:8px">My borrowers</a>'
            . '<a href="' . htmlspecialchars(Url::path('/logout'), ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#0b2a5b;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600">Sign out</a>'
            . '</div></body></html>';
    }
}
