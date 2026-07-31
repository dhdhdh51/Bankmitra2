<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper with hardened cookie flags and flash messages.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        // Cron jobs and CLI tools reuse these classes but have no HTTP session.
        // Starting one there only produces warnings, so fall back to an
        // in-memory array that keeps get()/set()/flash() working.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            self::$started = true;
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;

        session_name((string) Config::get('session.name', 'lrms_session'));
        session_set_cookie_params([
            'lifetime' => (int) Config::get('session.lifetime', 7200),
            'path'     => '/',
            'domain'   => '',
            // Only advertise Secure when the request really is HTTPS, otherwise
            // the browser drops the cookie and login silently fails on http hosts.
            'secure'   => $https && (bool) Config::get('session.secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        self::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::$started = false;
            return;
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }

    // -----------------------------------------------------------------------
    // Flash messages
    // -----------------------------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string,list<string>> */
    public static function takeFlash(): array
    {
        self::start();
        /** @var array<string,list<string>> $flash */
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /**
     * Remembers submitted form values so a failed validation can repopulate
     * the form instead of making the user retype everything.
     *
     * @param array<string,mixed>        $input
     * @param array<string,list<string>> $errors
     */
    public static function flashInput(array $input, array $errors): void
    {
        self::start();
        unset($input['password'], $input['password_confirmation'], $input['_csrf'], $input['current_password']);
        $_SESSION['_old_input'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    /** @return array<string,mixed> */
    public static function takeOldInput(): array
    {
        self::start();
        /** @var array<string,mixed> $old */
        $old = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);
        return $old;
    }

    /** @return array<string,list<string>> */
    public static function takeErrors(): array
    {
        self::start();
        /** @var array<string,list<string>> $errors */
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $errors;
    }
}
