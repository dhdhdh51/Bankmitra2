<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rule-string validator.
 *
 *   $v = Validator::make($request->all(), [
 *       'name'     => 'required|max:150',
 *       'mobile'   => 'required|mobile',
 *       'amount'   => 'required|numeric|min_value:0',
 *       'status'   => 'required|in:active,inactive',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 *
 * Supported rules:
 *   required, nullable, string, integer, numeric, boolean, email, mobile,
 *   aadhaar, date, time, min:N, max:N, min_value:N, max_value:N, in:a,b,c,
 *   same:field, different:field, confirmed, regex:/.../, exists:table,column,
 *   unique:table,column[,ignoreId]
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,string> */
    private array $rules;
    /** @var array<string,list<string>> */
    private array $errors = [];
    /** @var array<string,string> Human-friendly field labels. */
    private array $labels = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    private function __construct(array $data, array $rules, array $labels = [])
    {
        $this->data   = $data;
        $this->rules  = $rules;
        $this->labels = $labels;
        $this->run();
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }
        return 'Validation failed';
    }

    /**
     * Only the keys that had rules, with empty strings normalised to null.
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            if (!array_key_exists($field, $this->data)) {
                continue;
            }
            $value = $this->data[$field];
            $out[$field] = ($value === '') ? null : $value;
        }
        return $out;
    }

    // -----------------------------------------------------------------------

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = array_filter(explode('|', $ruleString));
            $value = $this->data[$field] ?? null;

            $isRequired = in_array('required', $rules, true);
            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($isRequired && $isEmpty) {
                $this->addError($field, sprintf('%s is required.', $this->label($field)));
                continue;
            }

            // Nothing further to check on an absent optional field.
            if ($isEmpty) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                $this->applyRule($field, $value, $rule);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $name = $rule;
        $arg = '';
        if (str_contains($rule, ':')) {
            [$name, $arg] = explode(':', $rule, 2);
        }

        $label = $this->label($field);
        $stringValue = is_array($value) ? '' : (string) $value;

        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, "{$label} must be text.");
                }
                break;

            case 'integer':
                if (filter_var($stringValue, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, "{$label} must be a whole number.");
                }
                break;

            case 'numeric':
                if (!is_numeric(str_replace(',', '', $stringValue))) {
                    $this->addError($field, "{$label} must be a number.");
                }
                break;

            case 'boolean':
                if (!in_array(strtolower($stringValue), ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)) {
                    $this->addError($field, "{$label} must be true or false.");
                }
                break;

            case 'email':
                if (filter_var($stringValue, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, "{$label} must be a valid email address.");
                }
                break;

            case 'mobile':
                $digits = preg_replace('/\D+/', '', $stringValue) ?? '';
                if (strlen($digits) < 10 || strlen($digits) > 13) {
                    $this->addError($field, "{$label} must be a valid 10-digit mobile number.");
                }
                break;

            case 'aadhaar':
                $digits = preg_replace('/\D+/', '', $stringValue) ?? '';
                if (strlen($digits) !== 12) {
                    $this->addError($field, "{$label} must be exactly 12 digits.");
                }
                break;

            case 'date':
                if (!self::isValidDate($stringValue)) {
                    $this->addError($field, "{$label} must be a valid date (YYYY-MM-DD).");
                }
                break;

            case 'time':
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $stringValue) !== 1) {
                    $this->addError($field, "{$label} must be a valid time (HH:MM).");
                }
                break;

            case 'min':
                if (mb_strlen($stringValue) < (int) $arg) {
                    $this->addError($field, "{$label} must be at least {$arg} characters.");
                }
                break;

            case 'max':
                if (mb_strlen($stringValue) > (int) $arg) {
                    $this->addError($field, "{$label} may not exceed {$arg} characters.");
                }
                break;

            case 'min_value':
                if ((float) str_replace(',', '', $stringValue) < (float) $arg) {
                    $this->addError($field, "{$label} must be at least {$arg}.");
                }
                break;

            case 'max_value':
                if ((float) str_replace(',', '', $stringValue) > (float) $arg) {
                    $this->addError($field, "{$label} may not be greater than {$arg}.");
                }
                break;

            case 'in':
                $allowed = explode(',', $arg);
                if (!in_array($stringValue, $allowed, true)) {
                    $this->addError($field, "{$label} must be one of: " . implode(', ', $allowed) . '.');
                }
                break;

            case 'same':
                if ($stringValue !== (string) ($this->data[$arg] ?? '')) {
                    $this->addError($field, "{$label} must match {$this->label($arg)}.");
                }
                break;

            case 'different':
                if ($stringValue === (string) ($this->data[$arg] ?? '')) {
                    $this->addError($field, "{$label} must be different from {$this->label($arg)}.");
                }
                break;

            case 'confirmed':
                if ($stringValue !== (string) ($this->data[$field . '_confirmation'] ?? '')) {
                    $this->addError($field, "{$label} confirmation does not match.");
                }
                break;

            case 'regex':
                if (@preg_match($arg, $stringValue) !== 1) {
                    $this->addError($field, "{$label} format is invalid.");
                }
                break;

            case 'exists':
                [$table, $column] = array_pad(explode(',', $arg), 2, 'id');
                if (!self::recordExists($table, $column, $stringValue)) {
                    $this->addError($field, "The selected {$label} does not exist.");
                }
                break;

            case 'unique':
                $parts = explode(',', $arg);
                $table = $parts[0];
                $column = $parts[1] ?? $field;
                $ignoreId = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : null;
                if (self::recordExistsExcept($table, $column, $stringValue, $ignoreId)) {
                    $this->addError($field, "This {$label} is already in use.");
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }
        return ucfirst(str_replace('_', ' ', $field));
    }

    // -----------------------------------------------------------------------

    public static function isValidDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Table/column names come from developer-authored rule strings, never from
     * user input, but they are still whitelisted against the real schema before
     * being interpolated.
     */
    private static function recordExists(string $table, string $column, string $value): bool
    {
        if (!self::isSafeIdentifier($table) || !self::isSafeIdentifier($column)) {
            return false;
        }
        $sql = sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? LIMIT 1', $table, $column);
        return Database::instance()->scalar($sql, [$value]) !== null;
    }

    private static function recordExistsExcept(string $table, string $column, string $value, ?int $ignoreId): bool
    {
        if (!self::isSafeIdentifier($table) || !self::isSafeIdentifier($column)) {
            return false;
        }
        if ($ignoreId !== null) {
            $sql = sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? AND `id` <> ? LIMIT 1', $table, $column);
            return Database::instance()->scalar($sql, [$value, $ignoreId]) !== null;
        }
        $sql = sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? LIMIT 1', $table, $column);
        return Database::instance()->scalar($sql, [$value]) !== null;
    }

    private static function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[a-z_][a-z0-9_]*$/i', $name) === 1;
    }
}
