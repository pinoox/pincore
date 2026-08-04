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
use Pinoox\Component\RouteResolver\Binding;
use Pinoox\Component\RouteResolver\ResolverManager;
use Pinoox\Component\Source\Portal;
use Pinoox\Component\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route parameter resolver facade.
 *
 * RouteResolver::define('user', User::class);
 * RouteResolver::bind('tenant', fn ($value) => TenantService::findByDomain($value));
 * Route::resolve('post', Post::class)->missing(fn () => redirect('/'));
 *
 * @method static Binding define(string $parameter, mixed $resolver)
 * @method static Binding bind(string $parameter, mixed $resolver)
 * @method static void register(Binding $binding)
 * @method static bool has(string $parameter)
 * @method static Binding|null get(string $parameter)
 * @method static array all()
 * @method static void forget(string $parameter)
 * @method static void flush()
 * @method static mixed resolveParameter(string $parameter, mixed $value, Request $request)
 * @method static Response|null resolve(Request $request)
 * @method static ResolverManager ___()
 *
 * @see ResolverManager
 */
class RouteResolver extends Portal
{
    public static function __register(): void
    {
        self::__bind(ResolverManager::class);
    }

    public static function __name(): string
    {
        return 'route_resolver';
    }

    public static function __exclude(): array
    {
        return [];
    }

    public static function __callback(): array
    {
        return [];
    }
}
