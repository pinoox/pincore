<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\RateLimiter;

use Closure;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * Named limiters + standalone counters. HTTP-agnostic — safe for jobs, CLI, events.
 */
class RateLimiter
{
    /** @var array<string, Closure> */
    private array $limiters = [];

    private RateLimiterStore $store;

    public function __construct(
        CacheInterface $cache,
        string $prefix = 'pinoox_rate:',
        ?RateLimiterStore $store = null,
    ) {
        $this->store = $store ?? new RateLimiterStore($cache, $prefix);
    }

    /**
     * Register a named limiter used by ThrottleFlow (`throttle:api`).
     *
     * @param Closure(mixed...): (Limit|Unlimited|array<int, Limit|Unlimited>) $callback
     */
    public function define(string $name, Closure $callback): void
    {
        $this->limiters[$name] = $callback;
    }

    public function limiter(string $name): ?Closure
    {
        return $this->limiters[$name] ?? null;
    }

    /**
     * @return array<string, Closure>
     */
    public function limiters(): array
    {
        return $this->limiters;
    }

    /**
     * Execute $callback when under the limit; otherwise return false.
     */
    public function attempt(string $key, int $maxAttempts, callable $callback, int $decaySeconds = 60): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        $this->hit($key, $decaySeconds);

        return $callback();
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->store->tooManyAttempts($key, $maxAttempts);
    }

    public function hit(string $key, int $decaySeconds = 60): int
    {
        return $this->store->hit($key, $decaySeconds);
    }

    public function clear(string $key): void
    {
        $this->store->clear($key);
    }

    public function resetAttempts(string $key): void
    {
        $this->clear($key);
    }

    public function attempts(string $key): int
    {
        return $this->store->attempts($key);
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return $this->store->remaining($key, $maxAttempts);
    }

    public function retriesLeft(string $key, int $maxAttempts): int
    {
        return $this->remaining($key, $maxAttempts);
    }

    public function availableIn(string $key): int
    {
        return $this->store->availableIn($key);
    }

    /**
     * Resolve named limiter callback against optional request (or any context).
     *
     * @return list<Limit|Unlimited>
     */
    public function resolve(string $name, mixed ...$arguments): array
    {
        $callback = $this->limiter($name);

        if ($callback === null) {
            throw new InvalidArgumentException(sprintf('Rate limiter [%s] is not defined.', $name));
        }

        $result = $callback(...$arguments);

        if ($result instanceof Limit || $result instanceof Unlimited) {
            return [$result];
        }

        if (!is_array($result)) {
            throw new RuntimeException(sprintf(
                'Rate limiter [%s] must return Limit, Unlimited, or an array of them.',
                $name,
            ));
        }

        return array_values($result);
    }

    public function store(): RateLimiterStore
    {
        return $this->store;
    }
}
