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

use Psr\SimpleCache\CacheInterface;

/**
 * Fixed-window counter store backed by any PSR-16 cache driver.
 */
class RateLimiterStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'pinoox_rate:',
    ) {
    }

    public function attempts(string $key): int
    {
        return max(0, (int) $this->cache->get($this->attemptsKey($key), 0));
    }

    public function hit(string $key, int $decaySeconds = 60): int
    {
        $decaySeconds = max(1, $decaySeconds);
        $attemptsKey = $this->attemptsKey($key);
        $timerKey = $this->timerKey($key);

        $attempts = $this->attempts($key) + 1;

        if ($attempts === 1 || !$this->cache->has($timerKey)) {
            $this->cache->set($timerKey, time() + $decaySeconds, $decaySeconds);
            $this->cache->set($attemptsKey, $attempts, $decaySeconds);

            return $attempts;
        }

        $ttl = max(1, $this->availableIn($key));
        $this->cache->set($attemptsKey, $attempts, $ttl);

        return $attempts;
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($maxAttempts < 1) {
            return true;
        }

        if ($this->attempts($key) < $maxAttempts) {
            return false;
        }

        if ($this->availableIn($key) > 0) {
            return true;
        }

        $this->clear($key);

        return false;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    public function availableIn(string $key): int
    {
        $availableAt = (int) $this->cache->get($this->timerKey($key), 0);

        if ($availableAt <= 0) {
            return 0;
        }

        return max(0, $availableAt - time());
    }

    public function clear(string $key): void
    {
        $this->cache->delete($this->attemptsKey($key));
        $this->cache->delete($this->timerKey($key));
    }

    private function attemptsKey(string $key): string
    {
        return $this->prefix . $key . ':attempts';
    }

    private function timerKey(string $key): string
    {
        return $this->prefix . $key . ':timer';
    }
}
