<?php

use Pinoox\Component\RateLimiter\Limit;
use Pinoox\Component\RateLimiter\RateLimiter;
use Pinoox\Component\RateLimiter\Unlimited;
use Psr\SimpleCache\CacheInterface;

beforeEach(function () {
    $this->cache = new class implements CacheInterface {
        /** @var array<string, array{value: mixed, expires: ?int}> */
        private array $items = [];

        public function get(string $key, mixed $default = null): mixed
        {
            if (!$this->has($key)) {
                return $default;
            }

            return $this->items[$key]['value'];
        }

        public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
        {
            $expires = null;
            if ($ttl instanceof \DateInterval) {
                $expires = (new \DateTimeImmutable())->add($ttl)->getTimestamp();
            } elseif (is_int($ttl) && $ttl > 0) {
                $expires = time() + $ttl;
            }

            $this->items[$key] = ['value' => $value, 'expires' => $expires];

            return true;
        }

        public function delete(string $key): bool
        {
            unset($this->items[$key]);

            return true;
        }

        public function clear(): bool
        {
            $this->items = [];

            return true;
        }

        public function getMultiple(iterable $keys, mixed $default = null): iterable
        {
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = $this->get((string) $key, $default);
            }

            return $out;
        }

        public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
        {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value, $ttl);
            }

            return true;
        }

        public function deleteMultiple(iterable $keys): bool
        {
            foreach ($keys as $key) {
                $this->delete((string) $key);
            }

            return true;
        }

        public function has(string $key): bool
        {
            if (!isset($this->items[$key])) {
                return false;
            }

            $expires = $this->items[$key]['expires'];
            if ($expires !== null && $expires <= time()) {
                unset($this->items[$key]);

                return false;
            }

            return true;
        }
    };

    $this->limiter = new RateLimiter($this->cache, 'test_rate:');
});

it('hits and tracks remaining attempts', function () {
    expect($this->limiter->hit('login:1.1.1.1', 60))->toBe(1)
        ->and($this->limiter->attempts('login:1.1.1.1'))->toBe(1)
        ->and($this->limiter->remaining('login:1.1.1.1', 5))->toBe(4)
        ->and($this->limiter->tooManyAttempts('login:1.1.1.1', 5))->toBeFalse();
});

it('blocks when max attempts are reached', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->limiter->hit('api:user-1', 60);
    }

    expect($this->limiter->tooManyAttempts('api:user-1', 5))->toBeTrue()
        ->and($this->limiter->remaining('api:user-1', 5))->toBe(0)
        ->and($this->limiter->availableIn('api:user-1'))->toBeGreaterThan(0);
});

it('clears attempts', function () {
    $this->limiter->hit('job:42', 60);
    $this->limiter->clear('job:42');

    expect($this->limiter->attempts('job:42'))->toBe(0)
        ->and($this->limiter->tooManyAttempts('job:42', 1))->toBeFalse();
});

it('runs attempt callback under the limit and returns false when exceeded', function () {
    $ran = 0;

    $ok = $this->limiter->attempt('invoice:9', 2, function () use (&$ran) {
        $ran++;

        return 'created';
    }, 60);

    expect($ok)->toBe('created')->and($ran)->toBe(1);

    $this->limiter->hit('invoice:9', 60);

    $blocked = $this->limiter->attempt('invoice:9', 2, function () use (&$ran) {
        $ran++;

        return 'created';
    }, 60);

    expect($blocked)->toBeFalse()->and($ran)->toBe(1);
});

it('defines and resolves named limiters', function () {
    $this->limiter->define('api', function (string $ip) {
        return Limit::perMinute(120)->by($ip);
    });

    $limits = $this->limiter->resolve('api', '10.0.0.1');

    expect($limits)->toHaveCount(1)
        ->and($limits[0])->toBeInstanceOf(Limit::class)
        ->and($limits[0]->maxAttempts())->toBe(120)
        ->and($limits[0]->key())->toBe('10.0.0.1');
});

it('supports unlimited markers from Limit::none', function () {
    expect(Limit::none())->toBeInstanceOf(Unlimited::class);
});

it('builds fluent limit windows', function () {
    expect(Limit::perSecond(3)->decaySeconds())->toBe(1)
        ->and(Limit::perMinute(10)->maxAttempts())->toBe(10)
        ->and(Limit::perHour(200)->decaySeconds())->toBe(3600)
        ->and(Limit::perDay(1000)->decaySeconds())->toBe(86400)
        ->and(Limit::perMinutes(5, 20)->decaySeconds())->toBe(300);
});
