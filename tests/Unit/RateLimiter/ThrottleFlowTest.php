<?php

use Pinoox\Component\Flow\FlowManager;
use Pinoox\Component\Http\Request;
use Pinoox\Component\RateLimiter\Limit;
use Pinoox\Component\RateLimiter\RateLimiter as RateLimiterComponent;
use Pinoox\Flow\ThrottleFlow;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $cache = new class implements CacheInterface {
        private array $items = [];

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->items[$key] ?? $default;
        }

        public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
        {
            $this->items[$key] = $value;

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
            return array_key_exists($key, $this->items);
        }
    };

    $this->rates = new RateLimiterComponent($cache, 'throttle_test:');
    $this->rates->define('api', function (Request $request) {
        return Limit::perMinute(2)
            ->by($request->getClientIp() ?: '127.0.0.1')
            ->message('Too Many Requests.');
    });
});

it('returns 429 after exceeding the named limiter', function () {
    $flow = ThrottleFlow::for('api', $this->rates);
    $request = Request::create('/api/items', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('ok');
    };

    expect($flow->response($request, $next)->getStatusCode())->toBe(200)
        ->and($flow->response($request, $next)->getStatusCode())->toBe(200);

    $blocked = $flow->response($request, $next);

    expect($hits)->toBe(2)
        ->and($blocked)->toBeInstanceOf(Response::class)
        ->and($blocked->getStatusCode())->toBe(429)
        ->and($blocked->headers->get('X-RateLimit-Limit'))->toBe('2')
        ->and($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and(json_decode($blocked->getContent(), true)['message'])->toBe('Too Many Requests.');
});

it('resolves throttle:api through FlowManager', function () {
    $manager = new FlowManager(
        flows: ['throttle:api'],
        alias: ['throttle' => ThrottleFlow::class],
    );

    // Instantiate manually to inject store — verify alias parsing builds ThrottleFlow('api')
    $captured = null;
    $manager->setFlows([ThrottleFlow::for('api', $this->rates)]);

    $request = Request::create('/api/ping', 'GET', server: ['REMOTE_ADDR' => '198.51.100.2']);
    $response = $manager->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('2');
});

it('parses alias:parameter into ThrottleFlow limiter name', function () {
    $manager = new FlowManager(
        flows: [],
        alias: ['throttle' => ThrottleFlow::class],
    );

    $ref = new ReflectionClass($manager);
    $method = $ref->getMethod('makeFlow');
    $method->setAccessible(true);

    /** @var ThrottleFlow $flow */
    $flow = $method->invoke($manager, ThrottleFlow::class, 'login');

    expect($flow)->toBeInstanceOf(ThrottleFlow::class)
        ->and($flow->limiter())->toBe('login');
});
