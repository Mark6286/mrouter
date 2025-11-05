<?php
namespace Route;

use Blad\Blad as View;
use Route\RouteHelper;
use Exception;

class Route
{
    protected static array $routes             = [];
    protected static array $routeMiddleware    = []; 
    protected static array $middlewareRegistry = [];
    protected static array $namedRoutes        = [];
    protected static $notFound                 = null;

    protected static string $groupPrefix    = '';
    protected static array $groupMiddleware = [];

    // ----------------------------------------
    // Route Definitions
    // ----------------------------------------
    public static function uri(string $uri, array $data = []): RouteHelper
    {
        return self::addRoute('GET', $uri, null, $data);
    }

    public static function get(string $uri, $handler, array $data = []): RouteHelper
    {
        return self::addRoute('GET', $uri, $handler, $data);
    }

    public static function post(string $uri, $handler, array $data = []): RouteHelper
    {
        return self::addRoute('POST', $uri, $handler, $data);
    }

    public static function match(array $methods, string $uri, $handler, array $data = []): RouteHelper
    {
        $routeHelper = null;
        foreach ($methods as $method) {
            $routeHelper = self::addRoute(strtoupper($method), $uri, $handler, $data);
        }
        return $routeHelper;
    }

    protected static function addRoute(string $method, string $uri, $handler, array $data = []): RouteHelper
    {
        $uri = self::normalizeUri($uri);

        $route = [
            'uri'     => $uri,
            'handler' => $handler,
            'data'    => $data,
        ];

        self::$routes[$method][] = $route;

        // Automatically attach middleware from group
        if (! empty(self::$groupMiddleware)) {
            self::attachMiddleware($method, $uri, self::$groupMiddleware);
        }

        return new RouteHelper($method, $uri, $handler);
    }

    // ----------------------------------------
    // Grouping
    // ----------------------------------------

    public static function group(string $prefix, array $middleware, callable $callback): void
    {
        $prevPrefix     = self::$groupPrefix;
        $prevMiddleware = self::$groupMiddleware;

        self::$groupPrefix     = trim($prevPrefix . '/' . trim($prefix, '/'), '/');
        self::$groupMiddleware = array_merge($prevMiddleware, $middleware);

        $callback();

        self::$groupPrefix     = $prevPrefix;
        self::$groupMiddleware = $prevMiddleware;
    }

    // ----------------------------------------
    // Middleware
    // ----------------------------------------

    public static function registerMiddleware(string $name, $handler): void
    {
        self::$middlewareRegistry[$name] = $handler;
    }

    public static function attachMiddleware(string $method, string $uri, array $middleware): void
    {
        $key                         = "$method:$uri";
        self::$routeMiddleware[$key] = $middleware;
    }

    // ----------------------------------------
    // Dispatch / Resolve
    // ----------------------------------------

    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

        $routes = self::$routes[$method] ?? [];

        foreach ($routes as $route) {
            $pattern = self::buildRegex($route['uri']);

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches); // remove full match
                $params = self::extractParams($route['uri'], $matches);

                // Middleware Check
                $routeKey    = "$method:{$route['uri']}";
                $middlewares = self::$routeMiddleware[$routeKey] ?? [];

                foreach ($middlewares as $mw) {
                    if (! isset(self::$middlewareRegistry[$mw])) {
                        continue;
                    }

                    $handler = self::$middlewareRegistry[$mw];

                    // Handle "Controller@method" string
                    if (is_string($handler) && str_contains($handler, '@')) {
                        [$class, $method] = explode('@', $handler);
                        $class            = 'App\\Controllers\\' . $class;
                        $handler          = [new $class, $method];
                    }

                    // Handle [Class::class, 'method']
                    if (is_array($handler) && is_string($handler[0]) && class_exists($handler[0])) {
                        $handler = [new $handler[0], $handler[1]];
                    }

                    if (is_callable($handler)) {
                        $result = call_user_func($handler);
                        if ($result === false) {
                            return;
                        }

                    }
                }

                // Route handler execution
                self::handleRoute($route['handler'], $params, $route['data'] ?? []);
                return;
            }
        }

        // Not Found
        self::handleNotFound();
    }

    // ----------------------------------------
    // Utilities
    // ----------------------------------------

    protected static function normalizeUri(string $uri): string
    {
        $uri = rtrim($uri, '/') ?: '/';
        if (self::$groupPrefix) {
            $uri = '/' . trim(self::$groupPrefix . '/' . ltrim($uri, '/'), '/');
        }
        return $uri;
    }

    protected static function buildRegex(string $uri): string
    {
        $pattern = preg_replace('#\{([^}]+)\}#', '([^/]+)', $uri);
        return "#^" . rtrim($pattern, '/') . "/?$#";
    }

    protected static function extractParams(string $uri, array $matches): array
    {
        $params = [];
        if (preg_match_all('#\{([^}]+)\}#', $uri, $paramNames)) {
            foreach ($paramNames[1] as $index => $name) {
                $params[$name] = $matches[$index] ?? null;
            }
        }
        return $params;
    }

    protected static function handleRoute($handler, array $params, array $data = []): void
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            $class            = 'App\\Controllers\\' . $class;
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
        }

        if (isset($class)) {
            if (! class_exists($class)) {
                throw new Exception("Class $class not found");
            }

            $instance = new $class;
            if (! method_exists($instance, $method)) {
                throw new Exception("Method $method not found in $class");
            }

            call_user_func_array([$instance, $method], $params);
        } elseif (is_callable($handler)) {
            echo call_user_func_array($handler, $params);
        } elseif (is_string($handler)) {
            View::render($handler, $data);
        }
    }

    protected static function handleNotFound(): void
    {
        if (self::$notFound) {
            call_user_func(self::$notFound);
        } else {
            exit('View not found!');
        }
    }

    // ----------------------------------------
    // Misc
    // ----------------------------------------

    public static function notFound(callable $callback): void
    {
        self::$notFound = $callback;
    }

    public static function redirect(string $path): void
    {
        header("Location: $path");
        exit;
    }

    public static function view(string $name): string
    {
        return self::$namedRoutes[$name] ?? '#';
    }

    public static function assets(string $path, bool $return = false)
    {
        $path = './public/assets/' . ltrim($path, '/');

        if (! $return) {
            echo $path;
            return;
        }

        return $path;
    }

    public static function path(string $path, bool $relative = true): string
    {
        $path = '/' . ltrim($path, '/');

        if ($relative) {
            return $path;
        }

        $root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
        $root = rtrim($root, '/');

        return $root . $path;
    }

    /**
     * Get all URL path segments as an array.
     *
     * @param bool $reverse Whether to reverse the segment order.
     * @param bool $numericIndex Whether to use numeric keys.
     * @return array
     */
    public static function segments(bool $reverse = false, bool $numericIndex = true): array
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove leading/trailing slashes and explode
        $segments = array_filter(explode('/', trim($uri, '/')));

        if ($reverse) {
            $segments = array_reverse($segments);
        }

        return $numericIndex ? array_values($segments) : $segments;
    }

    /**
     * Get a specific segment by level.
     *
     * @param int $level 1-based level (from start or end depending on $reverse).
     * @param bool $fromEnd Whether to count from the end (like reverse).
     * @return string|null
     */
    public static function segment(int $level, bool $fromEnd = false): ?string
    {
        $segments = self::segments($reverse = $fromEnd);
        return $segments[$level - 1] ?? null;
    }

    /**
     * Get the full root path from the URI.
     *
     * @return string
     */
    public static function root(): string
    {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    }

    /**
     * Get full URL with scheme, host, and request URI.
     *
     * @return string
     */
    public static function fullUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return "$scheme://$host$uri";
    }
}
