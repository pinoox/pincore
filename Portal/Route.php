<?php

/**
 * ***  *  *     *  ****  ****  *    *
 *   *  *  * *   *  *  *  *  *   *  *
 * ***  *  *  *  *  *  *  *  *    *
 *      *  *   * *  *  *  *  *   *  *
 *      *  *    **  ****  ****  *    *
 *
 * @author   Pinoox
 * @link https://www.pinoox.com
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Portal;

use Closure;
use Pinoox\Component\Router\RouteBuilder;
use Pinoox\Component\Router\RouteEntryBuilder;
use Pinoox\Component\Router\RouteGroupBuilder;
use Pinoox\Component\Router\RouteRegistrar;
use Pinoox\Component\Source\Portal;

/**
 * Laravel-style route definitions for Pinoox apps.
 *
 * Route::get('/', '@home')->name('home');
 * Route::get('/')->actionName('home');
 * Route::get('/')->named('home');
 * Route::group(['prefix' => 'api', 'flow' => 'auth'], fn () => ...);
 * Route::collect(fn () => ...);
 *
 * @see RouteRegistrar
 */
class Route extends Portal
{
    private static ?RouteRegistrar $registrar = null;

    private static function registrar(): RouteRegistrar
    {
        return self::$registrar ??= new RouteRegistrar();
    }

    public static function get(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->get($path, $action);
    }

    public static function post(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->post($path, $action);
    }

    public static function put(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->put($path, $action);
    }

    public static function patch(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->patch($path, $action);
    }

    public static function delete(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->delete($path, $action);
    }

    public static function query(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->query($path, $action);
    }

    public static function options(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->options($path, $action);
    }

    public static function head(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->head($path, $action);
    }

    public static function purge(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->purge($path, $action);
    }

    public static function trace(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->trace($path, $action);
    }

    public static function connect(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->connect($path, $action);
    }

    public static function any(string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->any($path, $action);
    }

    public static function match(array|string $methods, string $path, array|string|Closure $action = ''): RouteBuilder|RouteEntryBuilder
    {
        return self::registrar()->match($methods, $path, $action);
    }

    public static function group(array|string|null $attributes = null, ?callable $callback = null): ?RouteGroupBuilder
    {
        return self::registrar()->group($attributes, $callback);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function collect(callable $callback): array
    {
        return self::registrar()->collect($callback);
    }

    /**
     * Register a global route parameter resolver.
     *
     * Route::resolve('user', User::class);
     * Route::resolve('tenant', fn ($value) => TenantService::findByDomain($value));
     * Route::resolve('user', User::class)->missing(fn () => redirect('/'));
     *
     * @param class-string|callable|\Pinoox\Component\RouteResolver\ResolverInterface $resolver
     */
    public static function resolve(string $parameter, mixed $resolver): \Pinoox\Component\RouteResolver\Binding
    {
        return RouteResolver::bind($parameter, $resolver);
    }

    public static function __register(): void
    {
        self::__bind(RouteRegistrar::class);
    }

    public static function __name(): string
    {
        return 'route';
    }

    public static function __exclude(): array
    {
        return [
            'resolve',
        ];
    }

    public static function __callback(): array
    {
        return [];
    }
}

