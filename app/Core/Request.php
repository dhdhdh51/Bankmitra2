<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish view over the current HTTP request.
 */
final class Request
{
    /** @var array<string,mixed> */
    private array $query;
    /** @var array<string,mixed> */
    private array $body;
    /** @var array<string,mixed> */
    private array $json;
    /** @var array<string,string> */
    private array $routeParams = [];

    private string $method;
    private string $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Support method override for HTML forms (PUT/PATCH/DELETE).
        if ($this->method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $this->method = $override;
            }
        }

        $this->query = $_GET;
        $this->body  = $_POST;
        $this->json  = $this->parseJsonBody();
        $this->path  = $this->resolvePath();
    }

    // -----------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        if ($this->isApi()) {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    // -----------------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------------

    /**
     * Reads from JSON body, then form body, then query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (is_array($value)) {
            return $default;
        }
        return trim((string) $value);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, null);
        if ($value === null || $value === '' || is_array($value)) {
            return $default;
        }
        return (int) $value;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->input($key, null);
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        return (int) $value;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, null);
        if ($value === null || $value === '' || is_array($value)) {
            return $default;
        }
        return (float) str_replace(',', '', (string) $value);
    }

    /** Checkbox / switch semantics: "1", "on", "true", "yes", true all mean true. */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'on', 'true', 'yes'], true);
    }

    /** Returns null for an empty string so optional DATE columns stay NULL. */
    public function nullableStr(string $key): ?string
    {
        $value = $this->str($key);
        return $value === '' ? null : $value;
    }

    public function nullableFloat(string $key): ?float
    {
        $value = $this->input($key, null);
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        return (float) str_replace(',', '', (string) $value);
    }

    /** @return list<string> */
    public function arr(string $key): array
    {
        $value = $this->input($key, []);
        if (is_string($value)) {
            // Support "1,2,3" as well as name[] form arrays.
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn ($v): string => trim((string) $v), $value));
    }

    /** @return list<int> Positive integers only, de-duplicated. */
    public function intArr(string $key): array
    {
        $ids = [];
        foreach ($this->arr($key) as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body, $this->json);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->json)
            || array_key_exists($key, $this->body)
            || array_key_exists($key, $this->query);
    }

    // -----------------------------------------------------------------------
    // Route params
    // -----------------------------------------------------------------------

    /** @param array<string,string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function paramInt(string $key): int
    {
        return (int) ($this->routeParams[$key] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Headers / client
    // -----------------------------------------------------------------------

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return $value === null ? null : (string) $value;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');

        // Apache does not forward Authorization to a FastCGI/CGI/LSAPI backend,
        // so .htaccess re-injects it as an environment variable. After the
        // front-controller rewrite that arrives prefixed with REDIRECT_.
        if ($header === null) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        // Doubly prefixed when more than one internal redirect happened.
        if ($header === null) {
            $header = $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        if ($header === null) {
            foreach (['apache_request_headers', 'getallheaders'] as $fn) {
                if (!function_exists($fn)) {
                    continue;
                }
                /** @var array<string,string> $headers */
                $headers = (array) $fn();
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break 2;
                    }
                }
            }
        }

        if ($header === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    public function ip(): string
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

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function fullUrl(): string
    {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        return substr($this->path . ($qs !== '' ? '?' . $qs : ''), 0, 500);
    }

    // -----------------------------------------------------------------------
    // Pagination helpers shared by every list endpoint
    // -----------------------------------------------------------------------

    public function page(): int
    {
        return max(1, $this->int('page', 1));
    }

    public function perPage(?int $default = null, int $max = 200): int
    {
        $fallback = $default ?? (int) Settings::get('records_per_page', '25');
        $value = $this->int('per_page', $fallback);
        return max(1, min($max, $value));
    }

    /**
     * @param list<string> $allowed Whitelist of sortable columns.
     * @return array{0:string,1:string} [column, ASC|DESC]
     */
    public function sort(array $allowed, string $defaultColumn, string $defaultDirection = 'DESC'): array
    {
        $column = $this->str('sort_by', $defaultColumn);
        if (!in_array($column, $allowed, true)) {
            $column = $defaultColumn;
        }
        $direction = strtoupper($this->str('sort_dir', $defaultDirection)) === 'ASC' ? 'ASC' : 'DESC';
        return [$column, $direction];
    }

    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function parseJsonBody(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (!str_contains(strtolower($contentType), 'application/json')) {
            return [];
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalises the request path and strips the configured base path so the
     * app works from a domain root or a sub-directory without route changes.
     */
    private function resolvePath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $basePath = trim((string) Config::get('app.base_path', ''), '/');
        if ($basePath !== '' && str_starts_with(ltrim($path, '/'), $basePath)) {
            $path = '/' . ltrim(substr(ltrim($path, '/'), strlen($basePath)), '/');
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
