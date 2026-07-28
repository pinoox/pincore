<?php

use Pinoox\Component\Cors\CorsManager;
use Pinoox\Component\Cors\CorsPolicy;
use Pinoox\Component\Http\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->cors = new CorsManager();
    $this->cors->define('api', function () {
        return CorsPolicy::make()
            ->allowOrigins([
                'https://example.com',
                '*.example.com',
            ])
            ->allowMethods(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
            ->allowHeaders(['*'])
            ->exposeHeaders(['X-RateLimit-Remaining', 'X-Request-Id'])
            ->allowCredentials()
            ->maxAge(86400);
    });

    $this->cors->default(function () {
        return CorsPolicy::make()
            ->allowOrigins('*')
            ->allowMethods('*')
            ->allowHeaders('*');
    });
});

it('validates exact and wildcard origins', function () {
    $policy = $this->cors->resolve('api');

    expect($this->cors->validateOrigin('https://example.com', $policy))->toBeTrue()
        ->and($this->cors->validateOrigin('https://app.example.com', $policy))->toBeTrue()
        ->and($this->cors->validateOrigin('https://evil.com', $policy))->toBeFalse();
});

it('supports dynamic origin callbacks', function () {
    $this->cors->define('tenant', function () {
        return CorsPolicy::make()->allowOrigins(function (string $origin) {
            return str_ends_with(parse_url($origin, PHP_URL_HOST) ?: '', '.tenant.test');
        });
    });

    $policy = $this->cors->resolve('tenant');

    expect($this->cors->validateOrigin('https://acme.tenant.test', $policy))->toBeTrue()
        ->and($this->cors->validateOrigin('https://other.com', $policy))->toBeFalse();
});

it('builds preflight headers and returns 204', function () {
    $request = Request::create('https://api.test/items', 'OPTIONS', server: [
        'HTTP_ORIGIN' => 'https://app.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, X-Token',
    ]);

    expect($this->cors->isPreflight($request))->toBeTrue();

    $response = $this->cors->handlePreflight($request, 'api');

    expect($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.example.com')
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true')
        ->and($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and($response->headers->get('Access-Control-Allow-Headers'))->toBe('Content-Type, X-Token')
        ->and($response->headers->get('Access-Control-Max-Age'))->toBe('86400')
        ->and($response->headers->get('Vary'))->toContain('Origin');
});

it('applies expose headers on normal responses', function () {
    $request = Request::create('https://api.test/items', 'GET', server: [
        'HTTP_ORIGIN' => 'https://example.com',
    ]);

    $response = $this->cors->apply(new Response('ok'), $request, 'api');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://example.com')
        ->and($response->headers->get('Access-Control-Expose-Headers'))
        ->toBe('X-RateLimit-Remaining, X-Request-Id');
});

it('uses default policy when name is omitted', function () {
    $request = Request::create('/', 'GET', server: ['HTTP_ORIGIN' => 'https://anywhere.test']);
    $response = $this->cors->apply(new Response('ok'), $request);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

it('rejects disallowed origins without setting allow-origin', function () {
    $request = Request::create('/', 'GET', server: ['HTTP_ORIGIN' => 'https://evil.com']);
    $response = $this->cors->apply(new Response('ok'), $request, 'api');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('echoes origin when credentials are enabled instead of wildcard', function () {
    $this->cors->define('creds', function () {
        return CorsPolicy::make()
            ->allowOrigins('*')
            ->allowCredentials();
    });

    $request = Request::create('/', 'GET', server: ['HTTP_ORIGIN' => 'https://app.test']);
    $response = $this->cors->apply(new Response('ok'), $request, 'creds');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.test')
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});
