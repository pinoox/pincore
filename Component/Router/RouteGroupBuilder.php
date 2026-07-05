<?php

namespace Pinoox\Component\Router;

class RouteGroupBuilder
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(private readonly RouteRegistrar $registrar)
    {
    }

    public static function make(RouteRegistrar $registrar, array|string|null $seed = null): self
    {
        $builder = new self($registrar);

        if (is_string($seed)) {
            $builder->prefix($seed);
        } elseif (is_array($seed)) {
            $builder->attributes($seed);
        }

        return $builder;
    }

    public function prefix(string $prefix): self
    {
        $this->attributes['prefix'] = $prefix;

        return $this;
    }

    public function as(string $as): self
    {
        $this->attributes['as'] = $as;

        return $this;
    }

    public function name(string $name): self
    {
        $this->attributes['name'] = $name;

        return $this;
    }

    /**
     * @param class-string $controller
     */
    public function controller(string $controller): self
    {
        $this->attributes['controller'] = $controller;

        return $this;
    }

    public function flow(array|string $flow): self
    {
        $this->attributes['flow'] = $flow;

        return $this;
    }

    public function flows(array $flows): self
    {
        $this->attributes['flows'] = $flows;

        return $this;
    }

    public function middleware(array|string $middleware): self
    {
        $this->attributes['middleware'] = $middleware;

        return $this;
    }

    public function tags(array $tags): self
    {
        $this->attributes['tags'] = $tags;

        return $this;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public function defaults(array $defaults): self
    {
        $this->attributes['defaults'] = $defaults;

        return $this;
    }

    /**
     * @param array<string, string> $filters
     */
    public function filters(array $filters): self
    {
        $this->attributes['filters'] = $filters;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function data(array $data): self
    {
        $this->attributes['data'] = $data;

        return $this;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function attributes(array $attributes): self
    {
        $this->attributes = array_replace($this->attributes, $attributes);

        return $this;
    }

    public function routes(callable $callback): void
    {
        $this->registrar->applyGroup($this->attributes, $callback);
    }

    public function route(callable $callback): void
    {
        $this->routes($callback);
    }
}
