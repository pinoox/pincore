<?php

namespace Pinoox\Router;

use Closure;
use Pinoox\Component\Router\Collection;
use Pinoox\Component\Router\RouteBuilder;
use Pinoox\Component\Router\RouteEntryBuilder;
use Pinoox\Component\Router\RouteGroupBuilder;
use Pinoox\Component\Router\RouteRegistrar;
use Pinoox\Component\Router\Router as RouterComponent;
use Pinoox\Portal\Route as RouteFacade;
use Pinoox\Portal\Router;

/**
 * Register a named action.
 *
 * action('home', fn () => ...);            // immediate
 * action('home')->handle(...)->register(); // with metadata
 */
function action(string $name, array|string|Closure|null $handler = null): ?\Pinoox\Component\Router\Action\ActionBuilder
{
    return Router::action($name, $handler);
}

/**
 * Group routes under a path prefix with shared flows (theme contexts, auth, …).
 *
 * Uses the Router currently loading routes (see RouteRegistrar::usingRouter),
 * so nested files can call get()/post() and inherit prefix + flows.
 *
 * @param RouterComponent|string|array|callable|null $routes
 * @param array|string|Closure $action
 */
function collection(
    string $path = '',
    RouterComponent|string|array|callable|null $routes = null,
    mixed $controller = null,
    array|string $methods = [],
    array|string|Closure $action = '',
    array $defaults = [],
    array $filters = [],
    string $prefixName = '',
    array $data = [],
    array $flows = [],
    array $tags = [],
): Collection {
    return RouteRegistrar::requireActiveRouter()->collection(
        path: $path,
        routes: $routes,
        controller: $controller,
        methods: $methods,
        action: $action,
        defaults: $defaults,
        filters: $filters,
        prefixName: $prefixName,
        data: $data,
        flows: $flows,
        tags: $tags,
    );
}

function get(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::get($path, $action);
}

function post(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::post($path, $action);
}

function put(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::put($path, $action);
}

function patch(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::patch($path, $action);
}

function delete(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::delete($path, $action);
}

function query(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::query($path, $action);
}

function route_match(array|string $methods, string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
{
    return RouteFacade::match($methods, $path, $action);
}

function group(array|string|null $attributes = null, ?callable $callback = null): ?RouteGroupBuilder
{
    return RouteFacade::group($attributes, $callback);
}

/**
 * Collect route definitions from a fluent callback.
 *
 * collect(fn () => ...);                            // list of routes
 * collect(['flow' => ['manager.auth']], fn () => ...); // manifest with shared attributes
 *
 * @param array<string, mixed>|callable $attributes
 * @return array<string, mixed>|list<array<string, mixed>>
 */
function collect(array|callable $attributes, ?callable $callback = null): array
{
    if ($callback === null) {
        if (!is_callable($attributes)) {
            throw new \InvalidArgumentException('collect() requires a callback.');
        }

        return RouteFacade::collect($attributes);
    }

    return array_merge($attributes, [
        'routes' => RouteFacade::collect($callback),
    ]);
}

/**
 * Resolve the fully-qualified route name for the active app or a package.
 *
 * route_name('home') => installer.home
 * route_name('home', 'com_pinoox_manager') => manager.home
 */
function route_name(string $name, ?string $package = null): string
{
    return \Pinoox\Component\Router\RouteNaming::full($name, $package);
}

/**
 * Generate a URL for a route name (short or fully-qualified).
 */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return \Pinoox\Portal\Url::route(route_name($name, null), $parameters, $absolute);
}

/**
 * Route file helper — config manifest entry point.
 *
 * get('/', '@home')->name('home');
 * get('/')->actionName('home');
 * get('/')->named('home');
 * return routes([..., 'routes' => collect(fn () => ...)]);
 * return routes([..., 'routes' => route_files('api')]);
 * return routes([..., 'routes' => route_files(['api/public.php', 'api/private.php'])]);
 */
function route_files(string|array $sources, ?string $from = null): string|array
{
    if ($from === null) {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $from = dirname($trace[0]['file'] ?? __DIR__);
    }

    if (is_array($sources)) {
        return array_map(
            static fn(string $source): string => route_files($source, $from),
            $sources,
        );
    }

    $sources = str_replace('\\', '/', $sources);

    if ($sources !== '' && (is_file($sources) || is_dir($sources))) {
        return $sources;
    }

    return rtrim(str_replace('\\', '/', $from), '/') . '/' . ltrim($sources, '/');
}

function routes(array|callable|null $definition = null): array|\Pinoox\Component\Router\RouteFile|null
{
    if (is_array($definition)) {
        return \Pinoox\Component\Router\RouteManifest::normalizeManifest($definition);
    }

    $router = null;

    try {
        $router = Router::___();
    } catch (\Throwable) {
        $router = null;
    }

    if ($definition === null) {
        return new \Pinoox\Component\Router\RouteFile($router);
    }

    if ($router === null) {
        throw new \RuntimeException('Route callback requires an active router context.');
    }

    (new \Pinoox\Component\Router\RouteFile($router))->register($definition);

    return null;
}

