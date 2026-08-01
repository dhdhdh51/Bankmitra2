<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Fields the operator adds without a code change.
 *
 * Definitions live apart from values, so renaming a label touches no stored answer and
 * "which borrowers have no PAN recorded" is a query rather than a full scan of a JSON
 * column.
 *
 * WHAT THIS IS NOT FOR. Anything the core banking export owns belongs in a real column
 * the importer knows about. A custom field holding an outstanding balance would be a
 * second answer to the question this whole system exists to answer once, and the
 * import would not know to update it.
 */
final class CustomField
{
    public const ENTITIES = [
        'customer'     => 'Borrower',
        'loan_account' => 'Loan account',
        'visit_report' => 'Visit report',
    ];

    public const TYPES = [
        'text'     => 'Short text',
        'textarea' => 'Long text',
        'number'   => 'Number',
        'money'    => 'Amount',
        'date'     => 'Date',
        'select'   => 'Choose from a list',
        'toggle'   => 'Yes / No',
    ];

    /**
     * Active definitions for an entity, in display order.
     *
     * @return list<array<string,mixed>>
     */
    public static function definitions(string $entity, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM custom_field_definitions WHERE entity = ?';
        $params = [$entity];

        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        return Database::instance()->all($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::instance()->all(
            'SELECT d.*, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM custom_field_values v WHERE v.definition_id = d.id) AS answer_count
               FROM custom_field_definitions d
               LEFT JOIN users u ON u.id = d.created_by
              ORDER BY d.entity ASC, d.sort_order ASC, d.id ASC'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT * FROM custom_field_definitions WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Turns a label into a machine name.
     *
     * The key is immutable once created because stored values point at the definition,
     * so this only ever runs on creation. Kept readable rather than a hash: it appears
     * in exports and in the report, and "pan_number" is worth more to whoever reads a
     * CSV six months from now than "f_7".
     */
    public static function keyFrom(string $label): string
    {
        $key = strtolower(trim($label));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
        $key = trim($key, '_');

        if ($key === '') {
            $key = 'field';
        }

        return substr($key, 0, 60);
    }

    /** A key not already taken for this entity. */
    public static function uniqueKey(string $entity, string $label): string
    {
        $base = self::keyFrom($label);
        $key = $base;
        $suffix = 2;

        while (self::keyExists($entity, $key)) {
            // Truncate the base so the suffix always fits inside the column.
            $key = substr($base, 0, 57) . '_' . $suffix;
            $suffix++;
        }

        return $key;
    }

    public static function keyExists(string $entity, string $key): bool
    {
        return (int) (Database::instance()->scalar(
            'SELECT COUNT(*) FROM custom_field_definitions WHERE entity = ? AND field_key = ?',
            [$entity, $key]
        ) ?? 0) > 0;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::instance()->insert('custom_field_definitions', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::instance()->update('custom_field_definitions', $data, ['id' => $id]);
    }

    /**
     * Removes a definition and every answer to it.
     *
     * Only correct when the field was a mistake. "We stopped collecting this" is a
     * different decision and is served by setting the status to inactive, which keeps
     * the answers readable - which is why the delete confirmation says how many
     * answers it is about to destroy.
     */
    public static function delete(int $id): void
    {
        Database::instance()->delete('custom_field_definitions', ['id' => $id]);
    }

    public static function answerCount(int $id): int
    {
        return (int) (Database::instance()->scalar(
            'SELECT COUNT(*) FROM custom_field_values WHERE definition_id = ?',
            [$id]
        ) ?? 0);
    }

    // =======================================================================
    // Values
    // =======================================================================

    /**
     * Every answer for one record, keyed by field_key.
     *
     * @return array<string,string|null>
     */
    public static function valuesFor(string $entity, int $entityId): array
    {
        $rows = Database::instance()->all(
            'SELECT d.field_key, v.value
               FROM custom_field_values v
               JOIN custom_field_definitions d ON d.id = v.definition_id
              WHERE v.entity = ? AND v.entity_id = ?',
            [$entity, $entityId]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['field_key']] = $row['value'];
        }

        return $values;
    }

    /**
     * Definitions joined to this record's answers, ready to render.
     *
     * @return list<array<string,mixed>>
     */
    public static function withValues(string $entity, int $entityId, bool $activeOnly = true): array
    {
        $definitions = self::definitions($entity, $activeOnly);
        if ($definitions === []) {
            return [];
        }

        $values = self::valuesFor($entity, $entityId);

        foreach ($definitions as $index => $definition) {
            $definitions[$index]['value'] = $values[(string) $definition['field_key']] ?? null;
        }

        return $definitions;
    }

    /**
     * Saves the answers submitted for one record.
     *
     * A blank answer deletes the row rather than storing an empty string, so
     * "not recorded" and "recorded as empty" stay distinguishable - the difference
     * matters the moment somebody asks how many borrowers are missing a PAN.
     *
     * Returns the labels that changed, for the audit summary.
     *
     * @param  array<string,mixed>  $submitted  field_key => value
     * @return list<string>
     */
    public static function saveValues(string $entity, int $entityId, array $submitted, ?int $userId): array
    {
        $definitions = self::definitions($entity);
        if ($definitions === []) {
            return [];
        }

        $db = Database::instance();
        $existing = self::valuesFor($entity, $entityId);
        $changed = [];

        foreach ($definitions as $definition) {
            $key = (string) $definition['field_key'];

            // A field absent from the submission was not on the form - leave it alone.
            if (!array_key_exists($key, $submitted)) {
                continue;
            }

            $value = self::normalise((string) $definition['field_type'], $submitted[$key]);
            $before = $existing[$key] ?? null;

            if ((string) ($before ?? '') === (string) ($value ?? '')) {
                continue;
            }

            $changed[] = (string) $definition['label'];

            if ($value === null) {
                $db->delete('custom_field_values', [
                    'definition_id' => (int) $definition['id'],
                    'entity_id'     => $entityId,
                ]);

                continue;
            }

            // Upsert on the unique key: a double-submitted form must not give one
            // field two answers.
            $db->query(
                'INSERT INTO custom_field_values (definition_id, entity, entity_id, value, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)',
                [(int) $definition['id'], $entity, $entityId, $value, $userId]
            );
        }

        return $changed;
    }

    /**
     * Coerces a submitted answer into what the column should hold, or null for blank.
     *
     * One text column carries every type, so the definition is the only thing that
     * says how to read it - which means normalising on the way in, or a date typed as
     * "next tuesday" is stored verbatim and sorts as nonsense forever.
     */
    private static function normalise(string $type, mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : $raw;

        if ($type === 'toggle') {
            // A toggle is never null: an unchecked box is a real answer of "no", and
            // treating it as "not recorded" would make every unchecked field look
            // unanswered.
            return in_array((string) $value, ['1', 'on', 'true', 'yes'], true) ? '1' : '0';
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($type) {
            'number' => (string) (int) $value,
            'money'  => number_format((float) $value, 2, '.', ''),
            'date'   => ($timestamp = strtotime((string) $value)) === false
                ? null
                : date('Y-m-d', $timestamp),
            default  => mb_substr((string) $value, 0, 5000),
        };
    }

    /** How a stored answer should read on screen and in print. */
    public static function display(array $definition): string
    {
        $value = $definition['value'] ?? null;

        if ($value === null || $value === '') {
            return '';
        }

        return match ((string) $definition['field_type']) {
            'toggle' => (string) $value === '1' ? 'Yes' : 'No',
            'money'  => rupees($value),
            'date'   => fmt_date((string) $value),
            default  => (string) $value,
        };
    }

    /** @return list<string> */
    public static function optionsOf(array $definition): array
    {
        $options = trim((string) ($definition['options'] ?? ''));
        if ($options === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $options)), static fn (string $o): bool => $o !== ''));
    }
}
