<?php
namespace Route;

use Exception;
use Route\RouteHelper;

class Route
{
    /** @var array<string, array> Registered routes */
    protected static array $routes = [];

    /** @var array<string, array> Route-specific middlewares */
    protected static array $routeMiddleware = [];

    /** @var array<string, callable|string> Middleware registry */
    protected static array $middlewareRegistry = [];

    /** @var callable|null Custom 404 handler */
    protected static $notFound = null;

    /** @var string Current group prefix */
    protected static string $groupPrefix = '';

    /** @var array Current group middlewares */
    protected static array $groupMiddleware = [];

    /** @var string Application base path (for subfolder hosting) */
    protected static string $basePath = '';

    /** @var array<string,string> Cached regex patterns */
    protected static array $regexCache = [];

    /** @var array<string, string> */
    protected static array $namedRoutes = [];

    // --------------------------------------------------
    // Route Registration
    // --------------------------------------------------

    public static function get(string $uri, $handler, array $data = []): RouteHelper
    {
        return self::addRoute('GET', $uri, $handler, $data);
    }

    public static function post(string $uri, $handler, array $data = []): RouteHelper
    {
        return self::addRoute('POST', $uri, $handler, $data);
    }

    public static function put(string $uri, $handler, array $data = []): RouteHelper
    {
        return self::addRoute('PUT', $uri, $handler, $data);
    }

    public static function delete(string $uri, $handler, array $data = []): RouteHelper
    {
        return self::addRoute('DELETE', $uri, $handler, $data);
    }

    public static function match(array $methods, string $uri, $handler, array $data = []): RouteHelper
    {
        $helper = null;
        foreach ($methods as $method) {
            $helper = self::addRoute(strtoupper($method), $uri, $handler, $data);
        }
        return $helper;
    }

    protected static function addRoute(string $method, string $uri, $handler, array $data = []): RouteHelper
    {
        $uri = self::normalizeUri($uri);

        self::$routes[$method][] = [
            'uri'     => $uri,
            'handler' => $handler,
            'data'    => $data,
        ];

        // Apply group middleware automatically
        if (! empty(self::$groupMiddleware)) {
            self::attachMiddleware($method, $uri, self::$groupMiddleware);
        }

        return new RouteHelper($method, $uri, $handler, $data);
    }

    public static function addNamedRoute(string $name, string $uri): void
    {
        self::$namedRoutes[$name] = $uri;
    }

    /**
     * Get a named route's URI path.
     *
     * @param string $name
     * @return string|null
     */
    public static function view(string $name): ?string
    {
        return self::$namedRoutes[$name] ?? null;
    }

    // --------------------------------------------------
    // Grouping
    // --------------------------------------------------

    public static function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix     = self::$groupPrefix;
        $previousMiddleware = self::$groupMiddleware;

        self::$groupPrefix     = trim($previousPrefix . '/' . trim($prefix, '/'), '/');
        self::$groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback();

        self::$groupPrefix     = $previousPrefix;
        self::$groupMiddleware = $previousMiddleware;
    }

    // --------------------------------------------------
    // Middleware
    // --------------------------------------------------

    public static function registerMiddleware(string $name, $handler): void
    {
        self::$middlewareRegistry[$name] = $handler;
    }

    public static function attachMiddleware(string $method, string $uri, array $middleware): void
    {
        self::$routeMiddleware["$method:$uri"] = $middleware;
    }

    // --------------------------------------------------
    // Dispatch
    // --------------------------------------------------

    public static function dispatch(?string $method = null, ?string $uri = null): void
    {
        $method = $method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = self::normalizeRequestUri($uri ?? ($_SERVER['REQUEST_URI'] ?? '/'));

        $routes = self::$routes[$method] ?? [];

        foreach ($routes as $route) {
            $pattern = self::getRegexPattern($route['uri']);

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $params = self::extractParams($route['uri'], $matches);

                // Run middlewares
                $routeKey    = "$method:{$route['uri']}";
                $middlewares = self::$routeMiddleware[$routeKey] ?? [];

                foreach ($middlewares as $mw) {
                    $handler = self::$middlewareRegistry[$mw] ?? null;
                    if (! $handler) {
                        continue;
                    }

                    $callable = self::resolveCallable($handler);
                    $result   = call_user_func($callable);

                    if ($result === false) {
                        return; // Middleware blocked execution
                    }
                }

                self::handleRoute($route['handler'], $params, $route['data']);
                return;
            }
        }

        self::handleNotFound();
    }

    // --------------------------------------------------
    // Utilities
    // --------------------------------------------------

    protected static function normalizeUri(string $uri): string
    {
        $uri = '/' . trim($uri, '/');
        if (self::$groupPrefix) {
            $uri = '/' . trim(self::$groupPrefix . $uri, '/');
        }
        return $uri ?: '/';
    }

    protected static function normalizeRequestUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = '/' . trim($path, '/');

        if (self::$basePath && str_starts_with($path, self::$basePath)) {
            $path = substr($path, strlen(self::$basePath));
        }

        return $path ?: '/';
    }

    protected static function getRegexPattern(string $uri): string
    {
        if (isset(self::$regexCache[$uri])) {
            return self::$regexCache[$uri];
        }

        $pattern                       = preg_replace('#\{([^}]+)\}#', '([^/]+)', $uri);
        return self::$regexCache[$uri] = "#^" . rtrim($pattern, '/') . "/?$#";
    }

    protected static function extractParams(string $uri, array $matches): array
    {
        $params = [];
        if (preg_match_all('#\{([^}]+)\}#', $uri, $paramNames)) {
            foreach ($paramNames[1] as $i => $name) {
                $params[$name] = $matches[$i] ?? null;
            }
        }
        return $params;
    }

    protected static function resolveCallable($handler)
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            if (! class_exists($class)) {
                throw new Exception("Handler class '$class' not found.");
            }
            return [new $class, $method];
        }

        if (is_array($handler) && isset($handler[0], $handler[1]) && class_exists($handler[0])) {
            return [new $handler[0], $handler[1]];
        }

        return $handler;
    }

    protected static function handleRoute($handler, array $params, array $data = []): void
    {
        $callable = self::resolveCallable($handler);

        if (is_callable($callable)) {
            echo call_user_func_array($callable, $params);
            return;
        }

        throw new Exception("Invalid route handler for route.");
    }

    protected static function handleNotFound(): void
    {
        http_response_code(404);
        if (is_callable(self::$notFound)) {
            call_user_func(self::$notFound);
        } else {
            echo '<h1>404 Not Found</h1>';
        }
    }

    // --------------------------------------------------
    // Helpers
    // --------------------------------------------------

    public static function notFound(callable $callback): void
    {
        self::$notFound = $callback;
    }

    public static function redirect(string $url, int $code = 302): void
    {
        header("Location: $url", true, $code);
        exit;
    }

    public static function setBasePath(string $basePath): void
    {
        self::$basePath = '/' . trim($basePath, '/');
    }

    public static function segments(): array
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return array_values(array_filter(explode('/', trim($uri, '/'))));
    }

    public static function segment(int $index): ?string
    {
        $segments = self::segments();
        return $segments[$index - 1] ?? null;
    }

    public static function fullUrl(): string
    {
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return "$scheme://$host$uri";
    }
}
