<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * One-click database backup.
 *
 * Shared hosting frequently disables exec()/shell_exec(), so this tries
 * mysqldump first and transparently falls back to a pure-PHP dump that produces
 * an equivalent, importable .sql file. Old backups are pruned by retention.
 */
final class BackupService
{
    /**
     * @return array{file:string,path:string,size:int,method:string}
     */
    public static function create(): array
    {
        $dir = self::directory();

        $database = (string) Config::require('db.name');
        $filename = sprintf('lrms_backup_%s_%s.sql', $database, date('Ymd_His'));
        $path = $dir . '/' . $filename;

        $method = 'php';
        if (self::mysqldumpAvailable()) {
            if (self::viaMysqldump($path)) {
                $method = 'mysqldump';
            } else {
                self::viaPhp($path);
            }
        } else {
            self::viaPhp($path);
        }

        if (!is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new \RuntimeException('The backup file could not be created. Check that the storage directory is writable.');
        }

        @chmod($path, 0600);

        $size = (int) filesize($path);
        self::prune();

        Logger::audit(
            'backup',
            'database',
            null,
            null,
            ['file' => $filename, 'size' => $size, 'method' => $method],
            sprintf('Database backup created (%s, %s)', $method, self::humanBytes($size))
        );

        return ['file' => $filename, 'path' => $path, 'size' => $size, 'method' => $method];
    }

    /**
     * Existing backup files, newest first.
     *
     * @return list<array{file:string,size:int,created_at:int}>
     */
    public static function list(): array
    {
        $dir = self::directory();
        $files = glob($dir . '/*.sql') ?: [];

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'file'       => basename($file),
                'size'       => (int) filesize($file),
                'created_at' => (int) filemtime($file),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        return $out;
    }

    /**
     * Resolves a user-supplied filename to a real backup path.
     * Rejects anything that escapes the backup directory.
     */
    public static function resolve(string $filename): ?string
    {
        // Defence in depth: strip any path component, then verify containment.
        $safe = basename($filename);
        if ($safe === '' || !str_ends_with($safe, '.sql')) {
            return null;
        }

        $path = self::directory() . '/' . $safe;
        $real = realpath($path);
        $root = realpath(self::directory());

        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    public static function delete(string $filename): bool
    {
        $path = self::resolve($filename);
        if ($path === null) {
            return false;
        }

        $deleted = @unlink($path);
        if ($deleted) {
            Logger::audit('delete', 'database_backup', null, ['file' => basename($filename)], null, 'Deleted backup ' . basename($filename));
        }
        return $deleted;
    }

    // -----------------------------------------------------------------------

    private static function directory(): string
    {
        $dir = rtrim((string) Config::get('paths.storage', ROOT_PATH . '/storage'), '/') . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the backup directory.');
        }
        return $dir;
    }

    private static function mysqldumpAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    private static function viaMysqldump(string $path): bool
    {
        $binary = (string) Settings::get('mysqldump_path', 'mysqldump');

        // The password goes through an environment variable, never argv, so it
        // does not appear in the process list.
        $command = sprintf(
            '%s --host=%s --port=%d --user=%s --single-transaction --quick --skip-lock-tables '
            . '--routines --events --default-character-set=utf8mb4 %s > %s 2>&1',
            escapeshellcmd($binary),
            escapeshellarg((string) Config::get('db.host', 'localhost')),
            (int) Config::get('db.port', 3306),
            escapeshellarg((string) Config::require('db.user')),
            escapeshellarg((string) Config::require('db.name')),
            escapeshellarg($path)
        );

        $previous = getenv('MYSQL_PWD');
        putenv('MYSQL_PWD=' . (string) Config::get('db.pass', ''));

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);

        if ($previous === false) {
            putenv('MYSQL_PWD');
        } else {
            putenv('MYSQL_PWD=' . $previous);
        }

        if ($exitCode !== 0 || !is_file($path) || filesize($path) === 0) {
            @unlink($path);
            return false;
        }

        return true;
    }

    /**
     * Pure-PHP dump: schema via SHOW CREATE TABLE, data in batched INSERTs.
     * Streams row-by-row so a large table cannot exhaust memory.
     */
    private static function viaPhp(string $path): void
    {
        $db = Database::instance();
        $pdo = $db->pdo();

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the backup file for writing.');
        }

        $database = (string) Config::require('db.name');

        fwrite($handle, "-- LRMS database backup\n");
        fwrite($handle, '-- Database: ' . $database . "\n");
        fwrite($handle, '-- Generated: ' . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Method: pure PHP (mysqldump unavailable)\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $db->all('SHOW TABLES')
        );

        foreach ($tables as $table) {
            $createRow = $db->first('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $createSql = $createRow === null ? null : (string) (array_values($createRow)[1] ?? '');

            fwrite($handle, "\n-- ----------------------------\n");
            fwrite($handle, '-- Table: ' . $table . "\n");
            fwrite($handle, "-- ----------------------------\n");
            fwrite($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n");
            if ($createSql !== null && $createSql !== '') {
                fwrite($handle, $createSql . ";\n\n");
            }

            $statement = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
            if ($statement === false) {
                continue;
            }

            $batch = [];
            $columns = null;
            $batchSize = 200;

            while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($columns === null) {
                    $columns = array_map(static fn (string $c): string => '`' . $c . '`', array_keys($row));
                }

                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $values[] = (string) $value;
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }

                $batch[] = '(' . implode(',', $values) . ')';

                if (count($batch) >= $batchSize) {
                    fwrite($handle, 'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ") VALUES\n"
                        . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }

            if ($batch !== [] && $columns !== null) {
                fwrite($handle, 'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ") VALUES\n"
                    . implode(",\n", $batch) . ";\n");
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);
    }

    /** Deletes backups older than the retention window. */
    private static function prune(): void
    {
        $days = max(1, Settings::int('backup_retention_days', 14));
        $cutoff = time() - ($days * 86400);

        foreach (glob(self::directory() . '/*.sql') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
