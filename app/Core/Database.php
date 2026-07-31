<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Prepared statements only - there is no string-concatenation
 * query path anywhere in this codebase.
 *
 * Identifiers that must be dynamic (ORDER BY columns) are resolved through
 * whitelists in the calling model, never interpolated from raw input.
 */
final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    private int $txDepth = 0;

    private function __construct()
    {
        $host    = (string) Config::get('db.host', 'localhost');
        $port    = (int) Config::get('db.port', 3306);
        $name    = (string) Config::require('db.name');
        $user    = (string) Config::require('db.user');
        $pass    = (string) Config::get('db.pass', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        $this->alignTimezone();
    }

    /**
     * Pins the MySQL session timezone to the application timezone.
     *
     * Dates are written from PHP (date('Y-m-d')) but compared in SQL with
     * CURDATE() / NOW() - for example "visits today" on the dashboard and every
     * daily/weekly/monthly report. If the database server runs in UTC while the
     * app runs in Asia/Kolkata, those two disagree for the 5.5 hours after
     * 18:30 UTC and today's visits vanish from today's report.
     *
     * A numeric offset is used rather than a named zone because shared hosts
     * frequently have not loaded the MySQL timezone tables, which makes
     * `SET time_zone = 'Asia/Kolkata'` fail outright.
     */
    private function alignTimezone(): void
    {
        try {
            $timezone = (string) Config::get('app.timezone', 'Asia/Kolkata');
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('P');
            $this->pdo->exec("SET time_zone = '{$offset}'");
        } catch (\Throwable $e) {
            // Non-fatal: log and continue with the server default.
            error_log('[LRMS] could not align MySQL session timezone: ' . $e->getMessage());
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string|int,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? $key + 1 : $key;
            $stmt->bindValue($placeholder, $value, self::typeOf($value));
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->query($sql, $params)->fetchAll();
        return $rows;
    }

    /** @param array<string|int,mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return int last insert id
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );

        $this->query($sql, self::namedParams($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     * @return int affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new \InvalidArgumentException('update() requires both data and a where clause');
        }

        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = :set_{$c}", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn (string $c): string => "`{$c}` = :where_{$c}", array_keys($where)));

        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        foreach ($where as $k => $v) {
            $params['where_' . $k] = $v;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, $set, $cond);
        return $this->query($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('delete() requires a where clause');
        }
        $cond = implode(' AND ', array_map(static fn (string $c): string => "`{$c}` = :{$c}", array_keys($where)));
        return $this->query(sprintf('DELETE FROM `%s` WHERE %s', $table, $cond), self::namedParams($where))->rowCount();
    }

    // -----------------------------------------------------------------------
    // Transactions (nesting-safe via savepoints)
    // -----------------------------------------------------------------------

    public function begin(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT lrms_sp' . $this->txDepth);
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth === 0) {
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT lrms_sp' . $this->txDepth);
        }
    }

    public function rollback(): void
    {
        if ($this->txDepth === 0) {
            return;
        }
        $this->txDepth--;
        if ($this->txDepth === 0) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT lrms_sp' . $this->txDepth);
        }
    }

    /**
     * Run a closure inside a transaction, rolling back on any exception.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function namedParams(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }

    private static function typeOf(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_INT,
            is_null($value) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }
}
