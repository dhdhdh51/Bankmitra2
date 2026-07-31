<?php

declare(strict_types=1);

namespace App\Core;

/**
 * DB-driven settings with a per-request cache.
 *
 * Changes take effect on the next request with no file edit and no re-upload,
 * which is the requirement for SMTP / SMS / Maps / Firebase / app version.
 */
final class Settings
{
    /** @var array<string,string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $value = self::$cache[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string,string|null> */
    public static function all(): array
    {
        self::load();
        return self::$cache ?? [];
    }

    /**
     * Full setting rows for the Settings screen, grouped for rendering.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function grouped(): array
    {
        $rows = Database::instance()->all(
            'SELECT * FROM settings ORDER BY group_name ASC, sort_order ASC, id ASC'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['group_name']][] = $row;
        }
        return $grouped;
    }

    /**
     * @param array<string,string> $values key => value
     */
    public static function updateMany(array $values, ?int $userId = null): void
    {
        $db = Database::instance();

        $db->transaction(static function () use ($db, $values, $userId): void {
            foreach ($values as $key => $value) {
                $db->query(
                    'UPDATE settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?',
                    [$value, $userId, $key]
                );
            }
        });

        self::$cache = null;
    }

    /**
     * Required settings that are still blank. Drives the dashboard
     * "Missing Configuration" banner.
     *
     * @return list<array{key:string,label:string,group:string}>
     */
    public static function missingRequired(): array
    {
        $rows = Database::instance()->all(
            "SELECT setting_key, label, group_name
               FROM settings
              WHERE is_required = 1
                AND (setting_value IS NULL OR setting_value = '')
              ORDER BY group_name, sort_order"
        );

        return array_map(
            static fn (array $r): array => [
                'key'   => (string) $r['setting_key'],
                'label' => (string) $r['label'],
                'group' => (string) $r['group_name'],
            ],
            $rows
        );
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        try {
            $rows = Database::instance()->all('SELECT setting_key, setting_value FROM settings');
        } catch (\Throwable) {
            // Table may not exist yet during first-run setup.
            self::$cache = [];
            return;
        }

        $cache = [];
        foreach ($rows as $row) {
            $cache[(string) $row['setting_key']] = $row['setting_value'] === null
                ? null
                : (string) $row['setting_value'];
        }
        self::$cache = $cache;
    }
}
