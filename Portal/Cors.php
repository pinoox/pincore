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
use Pinoox\Component\Cors\CorsManager;
use Pinoox\Component\Cors\CorsPolicy;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Source\Portal;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS facade.
 *
 * Cors::define('api', fn () => CorsPolicy::make()->allowOrigins('*')->allowMethods('*'));
 * Cors::default(fn () => CorsPolicy::make()->allowOrigins('*'));
 * Cors::apply($response, 'api');
 * Cors::apply($response); // default policy
 *
 * @method static void define(string $name, Closure $callback)
 * @method static void default(Closure|string $policy)
 * @method static string|null defaultName()
 * @method static bool has(string $name)
 * @method static array policies()
 * @method static CorsPolicy resolve(?string $name = null)
 * @method static Response apply(Response $response, Request|SymfonyRequest|null $request = null, ?string $policy = null)
 * @method static Response handlePreflight(Request|SymfonyRequest $request, ?string $policy = null)
 * @method static array buildHeaders(CorsPolicy $policy, Request|SymfonyRequest $request, bool $preflight = false)
 * @method static bool validateOrigin(string $origin, CorsPolicy $policy, Request|SymfonyRequest|null $request = null)
 * @method static bool isPreflight(Request|SymfonyRequest $request)
 * @method static CorsManager ___()
 *
 * @see CorsManager
 * @see CorsPolicy
 */
class Cors extends Portal
{
    public static function __register(): void
    {
        self::__bind(CorsManager::class)->setFactory(static function () {
            $manager = new CorsManager();

            $defaultName = 'default';

            try {
                $config = Config::name('~cors')->get() ?? [];
                if (is_array($config) && !empty($config['default'])) {
                    $defaultName = (string) $config['default'];
                }
            } catch (\Throwable) {
            }

            // Sensible built-in default when apps have not registered policies yet.
            if (!$manager->has(CorsManager::DEFAULT_NAME)) {
                $manager->define(CorsManager::DEFAULT_NAME, static function () {
                    return CorsPolicy::make()
                        ->allowOrigins('*')
                        ->allowMethods('*')
                        ->allowHeaders('*');
                });
            }

            if ($manager->has($defaultName)) {
                $manager->default($defaultName);
            } else {
                $manager->default(CorsManager::DEFAULT_NAME);
            }

            return $manager;
        });
    }

    /**
     * Apply CORS using optional policy name as second argument (DX sugar).
     *
     * Cors::apply($response);
     * Cors::apply($response, 'api');
     * Cors::apply($response, $request, 'api');
     */
    public static function apply(
        Response $response,
        Request|SymfonyRequest|string|null $requestOrPolicy = null,
        ?string $policy = null,
    ): Response {
        if (is_string($requestOrPolicy)) {
            return static::___()->apply($response, null, $requestOrPolicy);
        }

        return static::___()->apply($response, $requestOrPolicy, $policy);
    }

    public static function __name(): string
    {
        return 'cors';
    }

    public static function __exclude(): array
    {
        return [
            'apply',
        ];
    }

    public static function __callback(): array
    {
        return [];
    }
}
