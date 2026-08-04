<?php

use Pinoox\Component\Cors\CorsManager;
use Pinoox\Component\Cors\CorsPolicy;
use Pinoox\Component\Flow\FlowManager;
use Pinoox\Component\Http\Request;
use Pinoox\Flow\CorsFlow;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->cors = new CorsManager();
    $this->cors->define('api', function () {
        return CorsPolicy::make()
            ->allowOrigins(['https://example.com', '*.example.com'])
            ->allowMethods(['GET', 'POST', 'OPTIONS'])
            ->allowHeaders(['*'])
            ->maxAge(3600);
    });
    $this->cors->default('api');
});

it('short-circuits OPTIONS preflight without calling next', function () {
    $flow = CorsFlow::for('api', $this->cors);
    $request = Request::create('/api', 'OPTIONS', server: [
        'HTTP_ORIGIN' => 'https://example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $called = false;
    $response = $flow->response($request, function () use (&$called) {
        $called = true;

        return new Response('should-not-run');
    });

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://example.com');
});

it('applies cors headers to controller responses', function () {
    $flow = CorsFlow::for('api', $this->cors);
    $request = Request::create('/api', 'GET', server: [
        'HTTP_ORIGIN' => 'https://app.example.com',
    ]);

    $response = $flow->response($request, fn () => new Response('ok', 200));

    expect($response->getContent())->toBe('ok')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com');
});

it('parses cors:api through FlowManager', function () {
    $manager = new FlowManager(
        flows: [],
        alias: ['cors' => CorsFlow::class],
    );

    $ref = new ReflectionClass($manager);
    $method = $ref->getMethod('makeFlow');
    $method->setAccessible(true);

    /** @var CorsFlow $flow */
    $flow = $method->invoke($manager, CorsFlow::class, 'api');

    expect($flow)->toBeInstanceOf(CorsFlow::class)
        ->and($flow->policy())->toBe('api');
});
