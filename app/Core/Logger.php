<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Writes the two log streams required by the spec.
 *
 *   audit()    -> entity-level changes with old/new values
 *   activity() -> login/logout, exports, page-level actions
 *
 * Logging never breaks the request: a failure here is swallowed and sent to the
 * PHP error log instead, because losing an audit row must not roll back a
 * legitimate business transaction.
 */
final class Logger
{
    /** Setting keys and fields that must never be persisted in a log payload. */
    private const REDACT = [
        'password', 'password_hash', 'password_confirmation', 'current_password',
        'new_password', 'token', 'remember_token', 'otp', 'otp_hash',
        'smtp_password', 'sms_api_key', 'firebase_server_key',
        'mobile_enc', 'aadhaar_enc', 'pan_enc', 'app_key', 'data_key', 'hash_pepper',
    ];

    /**
     * @param array<string,mixed>|null $oldValues
     * @param array<string,mixed>|null $newValues
     */
    public static function audit(
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $summary = null
    ): void {
        try {
            $user = Auth::user();

            Database::instance()->insert('audit_logs', [
                'user_id'     => $user['id'] ?? null,
                'user_name'   => $user['name'] ?? null,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : (string) $entityId,
                'old_values'  => self::encode($oldValues),
                'new_values'  => self::encode($newValues),
                'summary'     => $summary === null ? null : mb_substr($summary, 0, 500),
                'ip'          => self::ip(),
                'user_agent'  => self::userAgent(),
            ]);
        } catch (\Throwable $e) {
            error_log('[D2R audit] ' . $e->getMessage());
        }
    }

    /**
     * Records only the fields that actually changed, so the audit trail stays
     * readable instead of dumping whole rows.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public static function auditDiff(
        string $entityType,
        int|string $entityId,
        array $before,
        array $after,
        ?string $summary = null
    ): void {
        $oldChanged = [];
        $newChanged = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ((string) $oldValue !== (string) $newValue) {
                $oldChanged[$key] = $oldValue;
                $newChanged[$key] = $newValue;
            }
        }

        if ($newChanged === []) {
            return; // nothing actually changed
        }

        self::audit('update', $entityType, $entityId, $oldChanged, $newChanged, $summary);
    }

    public static function activity(
        string $activity,
        ?string $module = null,
        ?string $description = null,
        ?int $userIdOverride = null
    ): void {
        try {
            $user = Auth::user();

            Database::instance()->insert('activity_logs', [
                'user_id'     => $userIdOverride ?? ($user['id'] ?? null),
                'user_name'   => $user['name'] ?? null,
                'activity'    => $activity,
                'module'      => $module,
                'description' => $description === null ? null : mb_substr($description, 0, 500),
                'method'      => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : null,
                'url'         => isset($_SERVER['REQUEST_URI']) ? mb_substr((string) $_SERVER['REQUEST_URI'], 0, 500) : null,
                'ip'          => self::ip(),
                'user_agent'  => self::userAgent(),
            ]);
        } catch (\Throwable $e) {
            error_log('[D2R activity] ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------

    /** @param array<string,mixed>|null $values */
    private static function encode(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        $safe = [];
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $safe[$key] = '***';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = json_encode($value);
            }
        }

        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
    }

    private static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $candidate = trim(explode(',', (string) $value)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return '0.0.0.0';
    }

    private static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua === null ? null : mb_substr((string) $ua, 0, 255);
    }
}
