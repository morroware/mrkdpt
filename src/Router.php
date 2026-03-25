<?php

declare(strict_types=1);

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    /** @var callable[] */
    private array $middleware = [];

    private string $prefix = '';

    public function addMiddleware(callable $fn): void
    {
        $this->middleware[] = $fn;
    }

    public function group(string $prefix, callable $fn): void
    {
        $prev = $this->prefix;
        $this->prefix .= $prefix;
        $fn($this);
        $this->prefix = $prev;
    }

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$this->prefix . $path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$this->prefix . $path] = $handler;
    }

    public function patch(string $path, callable $handler): void
    {
        $this->routes['PATCH'][$this->prefix . $path] = $handler;
    }

    public function put(string $path, callable $handler): void
    {
        $this->routes['PUT'][$this->prefix . $path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE'][$this->prefix . $path] = $handler;
    }

    public function dispatch(string $method, string $uri): bool
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // run middleware
        foreach ($this->middleware as $mw) {
            $result = $mw($method, $path);
            if ($result === false) {
                return true; // middleware halted the request (already sent response)
            }
        }

        // exact match first
        if (isset($this->routes[$method][$path])) {
            ($this->routes[$method][$path])([]);
            return true;
        }

        // regex match for parameterised routes
        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
            if (preg_match('#^' . $regex . '$#', $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params);
                return true;
            }
        }

        return false;
    }
}
