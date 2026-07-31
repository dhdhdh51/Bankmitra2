<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Response helpers. Every API response uses the same envelope:
 *   { "success": bool, "data": mixed, "message": string }
 */
final class Response
{
    /**
     * @param array<string,mixed>|null $extra Merged at the top level (e.g. pagination meta).
     */
    public static function json(
        bool $success,
        mixed $data = null,
        string $message = '',
        int $status = 200,
        ?array $extra = null
    ): never {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        $payload = [
            'success' => $success,
            'data'    => $data,
            'message' => $message,
        ];

        if ($extra !== null) {
            $payload = array_merge($payload, $extra);
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** @param array<string,mixed>|null $extra */
    public static function success(mixed $data = null, string $message = '', ?array $extra = null): never
    {
        self::json(true, $data, $message, 200, $extra);
    }

    public static function created(mixed $data = null, string $message = 'Created'): never
    {
        self::json(true, $data, $message, 201);
    }

    /** @param array<string,list<string>>|null $errors */
    public static function error(string $message, int $status = 400, ?array $errors = null): never
    {
        self::json(false, $errors === null ? null : ['errors' => $errors], $message, $status);
    }

    /** @param array<string,list<string>> $errors */
    public static function validationError(array $errors, string $message = 'Validation failed'): never
    {
        self::json(false, ['errors' => $errors], $message, 422);
    }

    public static function unauthorized(string $message = 'Unauthenticated'): never
    {
        self::json(false, null, $message, 401);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action'): never
    {
        self::json(false, null, $message, 403);
    }

    public static function notFound(string $message = 'Not found'): never
    {
        self::json(false, null, $message, 404);
    }

    public static function serverError(string $message = 'Server error'): never
    {
        self::json(false, null, $message, 500);
    }

    // -----------------------------------------------------------------------
    // Non-JSON
    // -----------------------------------------------------------------------

    public static function redirect(string $path, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . Url::to($path), true, $status);
        }
        exit;
    }

    /** Redirect back with a flash message. */
    public static function redirectWith(string $path, string $type, string $message): never
    {
        Session::flash($type, $message);
        self::redirect($path);
    }

    /**
     * Streams a generated file as a download.
     */
    public static function download(string $content, string $filename, string $mime): never
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . self::sanitizeFilename($filename) . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-store, must-revalidate');
            header('Pragma: no-cache');
        }
        echo $content;
        exit;
    }

    /** Inline HTML, used by the print-friendly report view. */
    public static function html(string $html, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
        exit;
    }

    private static function sanitizeFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'download';
        return trim($clean, '_') ?: 'download';
    }
}
