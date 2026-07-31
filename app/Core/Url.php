<?php

declare(strict_types=1);

namespace App\Core;

/**
 * URL generation that respects an optional sub-directory install.
 */
final class Url
{
    public static function base(): string
    {
        $configured = trim((string) Config::get('app.url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $basePath = trim((string) Config::get('app.base_path', ''), '/');
        return $scheme . '://' . $host . ($basePath !== '' ? '/' . $basePath : '');
    }

    /** Absolute URL for an app-relative path. */
    public static function to(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        return self::base() . '/' . ltrim($path, '/');
    }

    /** Root-relative path (preferred in HTML to keep pages portable). */
    public static function path(string $path): string
    {
        $basePath = trim((string) Config::get('app.base_path', ''), '/');
        $clean = '/' . ltrim($path, '/');
        return $basePath !== '' ? '/' . $basePath . $clean : $clean;
    }

    public static function asset(string $path): string
    {
        return self::path('assets/' . ltrim($path, '/'));
    }

    /**
     * URL for a stored upload.
     *
     * Uploads are deliberately NOT served straight off disk: customer photos and
     * Aadhaar copies are personal data, so they stream through the application
     * (GET /media?f=...) which verifies the session and the caller's branch
     * scope first. uploads/.htaccess denies direct access.
     */
    public static function media(string $relativePath): string
    {
        return self::path('media?f=' . rawurlencode(ltrim($relativePath, '/')));
    }

    /**
     * Current path with merged/overridden query parameters. Used by table
     * headers, filters and pagination links so state is preserved.
     *
     * @param array<string,string|int|null> $params null removes a key.
     */
    public static function withQuery(array $params): string
    {
        $current = [];
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $current);

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                unset($current[$key]);
            } else {
                $current[$key] = (string) $value;
            }
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $qs = http_build_query($current);
        return $path . ($qs !== '' ? '?' . $qs : '');
    }
}
