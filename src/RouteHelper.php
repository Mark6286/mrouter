<?php

namespace Route;

class RouteHelper extends Route
{
    protected string $method;
    protected string $uri;
    protected $handler;

    public function __construct(string $method, string $uri, $handler)
    {
        $this->method = $method;
        $this->uri = rtrim($uri, '/') ?: '/';
        $this->handler = $handler;
    }

    public function name(string $name): void
    {
        Route::$namedRoutes[$name] = $this->uri;
    }

    public function middleware($middleware): self
    {
        $list = is_array($middleware) ? $middleware : [$middleware];
        Route::attachMiddleware($this->method, $this->uri, $list);
        return $this;
    }
}