<?php

namespace Pinoox\Component\Router;

use Closure;
use Pinoox\Portal\Router as RouterPortal;

class RouteRegistrar
{
    private static ?RouteRegister $context = null;

    /** @var list<Router> */
    private static array $routerStack = [];

    /**
     * Bind helpers (get/post/collection/…) to a concrete Router while loading routes.
     *
     * Needed because AppEngine builds a Router via Portal::build() while nested
     * collection files may otherwise hit a different Portal singleton instance.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function usingRouter(Router $router, callable $callback): mixed
    {
        self::$routerStack[] = $router;

        try {
            return $callback();
        } finally {
            array_pop(self::$routerStack);
        }
    }

    public static function activeRouter(): ?Router
    {
        if (self::$routerStack === []) {
            return null;
        }

        return self::$routerStack[array_key_last(self::$routerStack)];
    }

    public static function requireActiveRouter(): Router
    {
        $router = self::activeRouter();

        if ($router !== null) {
            return $router;
        }

        try {
            return RouterPortal::___();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Route definitions require an active router context.', 0, $e);
        }
    }

    public function get(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->get($path, $action);
    }

    public function post(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->post($path, $action);
    }

    public function put(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->put($path, $action);
    }

    public function patch(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->patch($path, $action);
    }

    public function delete(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->delete($path, $action);
    }

    public function query(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->query($path, $action);
    }

    public function match(array|string $methods, string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return $this->register()->match($methods, $path, $action);
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
     * }|string|null $attributes
     */
    public function group(array|string|null $attributes = null, ?callable $callback = null): ?RouteGroupBuilder
    {
        if (is_array($attributes) && $callback !== null) {
            $this->applyGroup($attributes, $callback);

            return null;
        }

        $builder = RouteGroupBuilder::make($this, $attributes);

        if ($callback !== null) {
            $builder->routes($callback);

            return null;
        }

        return $builder;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function applyGroup(array $attributes, callable $callback): void
    {
        if (self::$context !== null) {
            self::$context->group($attributes, $callback);

            return;
        }

        $router = $this->router();

        $router->collection(
            path: (string) ($attributes['prefix'] ?? ''),
            routes: $callback,
            controller: $attributes['controller'] ?? null,
            prefixName: (string) ($attributes['name'] ?? $attributes['as'] ?? ''),
            flows: $this->flows($attributes),
            tags: $attributes['tags'] ?? [],
            defaults: $attributes['defaults'] ?? [],
            filters: $attributes['filters'] ?? [],
            data: $attributes['data'] ?? [],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collect(callable $callback): array
    {
        return RouteRegister::collect(function (RouteRegister $register) use ($callback) {
            $previous = self::$context;
            self::$context = $register;

            try {
                $callback();
            } finally {
                self::$context = $previous;
            }
        });
    }

    private function register(): RouteRegister
    {
        if (self::$context !== null) {
            return self::$context;
        }

        return new RouteRegister($this->router());
    }

    private function router(): Router
    {
        return self::requireActiveRouter();
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function flows(array $attributes): array
    {
        $flows = $attributes['flows']
            ?? $attributes['flow']
            ?? $attributes['middleware']
            ?? [];

        if (is_string($flows)) {
            return [$flows];
        }

        return is_array($flows) ? array_values($flows) : [];
    }
}

