<?php

namespace Route;

/**
 * Class RouteHelper
 *
 * Provides a fluent interface for defining route options such as:
 * - Middleware
 * - Name
 * - Custom data
 *
 * Example usage:
 * Route::get('/users', 'UserController@index')
 *     ->name('users.index')
 *     ->middleware(['auth']);
 */
class RouteHelper
{
    protected string $method;
    protected string $uri;
    protected $handler;
    protected array $data;

    /**
     * RouteHelper constructor.
     *
     * @param string $method
     * @param string $uri
     * @param callable|array|string $handler
     * @param array $data
     */
    public function __construct(string $method, string $uri, $handler, array $data = [])
    {
        $this->method  = $method;
        $this->uri     = $uri;
        $this->handler = $handler;
        $this->data    = $data;
    }

    /**
     * Assign one or more middleware(s) to the route.
     *
     * @param array|string $middleware
     * @return $this
     */
    public function middleware(array $middleware): self
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }

        Route::attachMiddleware($this->method, $this->uri, $middleware);
        return $this;
    }

    /**
     * Give this route a unique name for referencing (like named routes).
     *
     * @param string $name
     * @return $this
     */
    public function name(string $name): self
    {
        Route::addNamedRoute($name, $this->uri);
        return $this;
    }

    /**
     * Attach additional route metadata or arbitrary data.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function with(string $key, $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Retrieve route details (for debugging or introspection).
     *
     * @return array{method:string,uri:string,handler:mixed,data:array}
     */
    public function getDetails(): array
    {
        return [
            'method'  => $this->method,
            'uri'     => $this->uri,
            'handler' => $this->handler,
            'data'    => $this->data,
        ];
    }
}
