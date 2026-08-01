<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Central error handling. Details are logged; users see a generic message
 * unless app.debug is on.
 */
final class ErrorHandler
{
    public static function handleException(\Throwable $e): void
    {
        self::log($e);

        $debug = (bool) Config::get('app.debug', false);
        $isApi = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'data'    => $debug ? [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => explode("\n", $e->getTraceAsString()),
                ] : null,
                'message' => $debug ? $e->getMessage() : 'An unexpected server error occurred.',
            ]);
            exit;
        }

        echo self::htmlPage($e, $debug);
        exit;
    }

    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::handleException(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }

    private static function log(\Throwable $e): void
    {
        error_log(sprintf(
            '[D2R] %s: %s in %s:%d%s%s',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString()
        ));
    }

    private static function htmlPage(\Throwable $e, bool $debug): string
    {
        $detail = '';
        if ($debug) {
            $detail = '<pre style="margin:20px 0 0;padding:16px;background:#1c2128;color:#e6edf3;border-radius:8px;'
                . 'overflow:auto;font-size:12.5px;line-height:1.55">'
                . htmlspecialchars(
                    $e::class . ': ' . $e->getMessage() . "\n\n"
                    . $e->getFile() . ':' . $e->getLine() . "\n\n"
                    . $e->getTraceAsString(),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</pre>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Something went wrong</title></head>'
            . '<body style="margin:0;font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f7fa;color:#1c2128">'
            . '<div style="max-width:760px;margin:12vh auto;padding:32px;background:#fff;border:1px solid #e2e5ea;'
            . 'border-radius:10px;box-shadow:0 1px 3px rgba(28,33,40,.06)">'
            . '<h1 style="margin:0 0 8px;font-size:19px;color:#b3261e">Something went wrong</h1>'
            . '<p style="margin:0;color:#4b5563">The request could not be completed. '
            . 'The details have been recorded in the error log.</p>'
            . $detail
            . '<p style="margin:22px 0 0"><a href="' . htmlspecialchars(Url::path('/dashboard'), ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;background:#0b2a5b;color:#fff;text-decoration:none;padding:9px 16px;'
            . 'border-radius:8px;font-weight:600">Back to dashboard</a></p>'
            . '</div></body></html>';
    }
}
