<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable access to config/config.php using dot notation.
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    /** @param array<string,mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function require(string $key): mixed
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required configuration: {$key}");
        }
        return $value;
    }
}
