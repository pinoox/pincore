<?php

namespace Pinoox\Component\Router;

use Closure;

class RouteRegister
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /** @var list<array<string, mixed>> */
    private array $groupStack = [];

    public function __construct(private readonly ?Router $router = null)
    {
    }

    /**
     * @param callable(self): void $callback
     * @return list<array<string, mixed>>
     */
    public static function collect(callable $callback): array
    {
        $register = new self(null);
        $callback($register);

        return $register->entries;
    }

    public function route(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('GET', $path, $action);
    }

    public function get(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('GET', $path, $action);
    }

    public function post(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('POST', $path, $action);
    }

    public function put(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('PUT', $path, $action);
    }

    public function patch(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('PATCH', $path, $action);
    }

    public function delete(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('DELETE', $path, $action);
    }

    public function query(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->method('QUERY', $path, $action);
    }

    public function match(array|string $methods, string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        if ($this->router !== null) {
            return $this->router->route($path, $action)->methods($methods);
        }

        $builder = new RouteEntryBuilder($this, 'GET', $path, $action);
        $builder->methods($methods);

        return $builder;
    }

    /**
     * @param array{
     *     prefix?: string,
     *     name?: string,
     *     as?: string,
     *     controller?: class-string,
     *     flow?: string|list<string>,
     *     flows?: list<string>,
     *     middleware?: string|list<string>,
     *     tags?: list<string>,
     *     defaults?: array<string, mixed>,
     *     filters?: array<string, string>,
     *     data?: array<string, mixed>,
     * } $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $parent = $this->groupStack !== [] ? $this->groupStack[array_key_last($this->groupStack)] : [];
        $this->groupStack[] = RouteManifest::mergeGroupContext($parent, $attributes);

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function pushEntry(array $entry): void
    {
        if ($this->groupStack !== []) {
            $entry = RouteManifest::expandRoutes([$entry], $this->groupStack[array_key_last($this->groupStack)])[0];
        }

        $this->entries[] = $entry;
    }

    private function method(string $method, string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        if ($this->router !== null) {
            return match (strtoupper($method)) {
                'POST' => $this->router->route($path, $action)->post(),
                'PUT' => $this->router->route($path, $action)->put(),
                'PATCH' => $this->router->route($path, $action)->patch(),
                'DELETE' => $this->router->route($path, $action)->delete(),
                'QUERY' => $this->router->route($path, $action)->query(),
                default => $this->router->route($path, $action)->get(),
            };
        }

        return new RouteEntryBuilder($this, $method, $path, $action);
    }
}

