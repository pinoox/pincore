<?php

namespace Pinoox\Component\AppEvent;

use Closure;
use Pinoox\Component\Router\RouteManifest;
use Pinoox\Component\Router\Router;
use Pinoox\Portal\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Fluent registration API used inside boot.php and event listeners.
 */
class AppRegister
{
    public function __construct(
        private readonly string $package,
        private readonly AppRegisterCollector $collector,
    ) {
    }

    public function package(): string
    {
        return $this->package;
    }

    public function web(callable $callback): self
    {
        $this->collector->webCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param array<string, mixed> $route
     */
    public function route(array $route): self
    {
        return $this->web(function (Router $router) use ($route): void {
            RouteManifest::apply($router, ['routes' => [$route]]);
        });
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function api(array $manifest): self
    {
        $this->collector->apiManifests[] = RouteManifest::normalizeManifest($manifest);

        return $this;
    }

    /**
     * @param array<string, mixed> $route
     */
    public function apiRoute(array $route, ?string $version = null): self
    {
        $entry = $route;
        if ($version !== null) {
            $entry['_version'] = $version;
        }

        $this->collector->apiRoutes[] = $entry;

        return $this;
    }

    /**
     * Register flow middleware aliases for routes (maps alias name → Flow class).
     *
     * @param array<string, string|class-string> $map
     */
    public function flowAlias(array $map): self
    {
        $this->collector->flows = array_merge($this->collector->flows, $map);

        return $this;
    }

    /**
     * @param array<string, mixed> $aliases
     */
    public function alias(array $aliases): self
    {
        $this->collector->aliases = array_replace_recursive($this->collector->aliases, $aliases);

        return $this;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function graphql(array $manifest): self
    {
        $this->collector->graphqlManifests[] = $manifest;

        return $this;
    }

    public function action(string $name, array|string|Closure $handler): self
    {
        $this->collector->actions[$name] = $handler;

        return $this;
    }

    public function schedule(callable $callback): self
    {
        $this->collector->schedules[] = $callback;

        return $this;
    }

    public function listen(string $event, callable|array $listener, int $priority = 0): self
    {
        $this->collector->listeners[] = [$event, $listener, $priority];

        return $this;
    }

    /**
     * @param class-string<EventSubscriberInterface> $subscriber
     */
    public function subscribe(string $subscriber): self
    {
        $this->collector->subscribers[] = $subscriber;

        return $this;
    }

    /**
     * Register routes/API when another app boots (plugin → host app).
     */
    public function when(string $targetPackage, callable $callback): self
    {
        AppRegisterCollector::$pendingWhen[$targetPackage][] = $callback;

        return $this;
    }

    /**
     * Run when a web route name matches (after route match, before controller).
     *
     * @param string|list<string> $name
     */
    public function onRoute(string|array $name, callable $handler, ?string $package = null): self
    {
        return $this->watch('route', $name, $handler, $package);
    }

    /**
     * Run when an API route name matches.
     *
     * @param string|list<string> $name
     */
    public function onApi(string|array $name, callable $handler, ?string $package = null): self
    {
        return $this->watch('api', $name, $handler, $package);
    }

    /**
     * Run when the request path matches (supports trailing `*` wildcard).
     *
     * @param string|list<string> $pattern
     */
    public function onPath(string|array $pattern, callable $handler, ?string $package = null): self
    {
        return $this->watch('path', $pattern, $handler, $package);
    }

    /**
     * Run when a named action reference matches.
     *
     * @param string|list<string> $name
     */
    public function onAction(string|array $name, callable $handler, ?string $package = null): self
    {
        return $this->watch('action', $name, $handler, $package);
    }

    /**
     * Run when a controller class (and optional method) is invoked.
     *
     * @param class-string|array{0: class-string, 1?: string} $target
     */
    public function onController(string|array $target, callable $handler, ?string $package = null): self
    {
        return $this->watch('controller', $target, $handler, $package);
    }

    /**
     * Run on an Eloquent model event (creating, updating, saved, …).
     *
     * @param class-string $model
     */
    public function onModel(string $model, string $event, callable $handler): self
    {
        $this->collector->watches[] = [
            'kind' => 'model',
            'match' => ['class' => $model, 'event' => $event],
            'handler' => $handler,
            'package' => null,
        ];

        return $this;
    }

    private function watch(string $kind, mixed $match, callable $handler, ?string $package): self
    {
        $this->collector->watches[] = [
            'kind' => $kind,
            'match' => $match,
            'handler' => $handler,
            'package' => $package,
        ];

        return $this;
    }

    public function collector(): AppRegisterCollector
    {
        return $this->collector;
    }
}

