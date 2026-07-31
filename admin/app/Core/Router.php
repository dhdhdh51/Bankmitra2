<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small pattern router.
 *
 * Patterns support named placeholders:  /customers/{id}  /reports/{type}/export
 * A placeholder matches a single path segment.
 */
final class Router
{
    /**
     * @var array<string, list<array{regex:string, keys:list<string>, handler:callable|array{0:class-string,1:string}}>>
     */
    private array $routes = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'PATCH'  => [],
        'DELETE' => [],
    ];

    /** @var callable|null */
    private $notFoundHandler = null;

    /** @param callable|array{0:class-string,1:string} $handler */
    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /** Register the same handler for GET and POST (form pages). */
    public function form(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    public function notFound(callable $handler): void
    {
        $this->notFoundHandler = $handler;
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $keys = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        ) ?? $pattern;

        $this->routes[$method][] = [
            'regex'   => '#^' . $regex . '$#',
            'keys'    => $keys,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path   = $request->path();

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['keys'] as $index => $key) {
                $params[$key] = $matches[$index] ?? '';
            }
            $request->setRouteParams($params);

            $this->invoke($route['handler'], $request);
            return;
        }

        // Path exists under a different verb -> 405 rather than a confusing 404.
        foreach ($this->routes as $verb => $routes) {
            if ($verb === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path) === 1) {
                    if ($request->wantsJson()) {
                        Response::error('Method not allowed', 405);
                    }
                    http_response_code(405);
                    echo 'Method Not Allowed';
                    return;
                }
            }
        }

        if ($this->notFoundHandler !== null) {
            ($this->notFoundHandler)($request);
            return;
        }

        if ($request->wantsJson()) {
            Response::notFound('Endpoint not found');
        }
        http_response_code(404);
        echo 'Not Found';
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function invoke(callable|array $handler, Request $request): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            $controller->{$method}($request);
            return;
        }
        $handler($request);
    }
}
