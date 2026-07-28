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
use Pinoox\Component\RateLimiter\Limit;
use Pinoox\Component\RateLimiter\RateLimiter as RateLimiterComponent;
use Pinoox\Component\RateLimiter\RateLimiterStore;
use Pinoox\Component\RateLimiter\Unlimited;
use Pinoox\Component\Source\Portal;
use Psr\SimpleCache\CacheInterface;

/**
 * Rate limiting facade (HTTP-agnostic).
 *
 * RateLimiter::define('api', fn ($request) => Limit::perMinute(60)->by($request->getClientIp()));
 * RateLimiter::hit('send-email:'.$id, 60);
 * RateLimiter::tooManyAttempts('send-email:'.$id, 10);
 * RateLimiter::attempt('invoice:'.$id, 3, fn () => ...);
 *
 * @method static void define(string $name, Closure $callback)
 * @method static Closure|null limiter(string $name)
 * @method static array limiters()
 * @method static mixed attempt(string $key, int $maxAttempts, callable $callback, int $decaySeconds = 60)
 * @method static bool tooManyAttempts(string $key, int $maxAttempts)
 * @method static int hit(string $key, int $decaySeconds = 60)
 * @method static void clear(string $key)
 * @method static void resetAttempts(string $key)
 * @method static int attempts(string $key)
 * @method static int remaining(string $key, int $maxAttempts)
 * @method static int retriesLeft(string $key, int $maxAttempts)
 * @method static int availableIn(string $key)
 * @method static list<Limit|Unlimited> resolve(string $name, mixed ...$arguments)
 * @method static RateLimiterStore store()
 * @method static RateLimiterComponent ___()
 *
 * @see RateLimiterComponent
 * @see Limit
 */
class RateLimiter extends Portal
{
    public static function __register(): void
    {
        $prefix = 'pinoox_rate:';

        try {
            $config = Config::name('~rate_limiter')->get() ?? [];
            if (is_array($config) && isset($config['prefix'])) {
                $prefix = (string) $config['prefix'];
            }
        } catch (\Throwable) {
            // Use default prefix when config is unavailable.
        }

        self::__bind(RateLimiterComponent::class)->setFactory(static function () use ($prefix) {
            /** @var CacheInterface $cache */
            $cache = Cache::___();

            return new RateLimiterComponent($cache, $prefix);
        });
    }

    public static function __name(): string
    {
        return 'rate_limiter';
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
