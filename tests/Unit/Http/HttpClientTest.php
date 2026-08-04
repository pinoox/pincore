<?php

use Pinoox\Component\Http\Http;
use Pinoox\Portal\Http as HttpPortal;

it('accepts all supported outbound http methods', function () {
    expect(Http::valid('OPTIONS'))->toBeTrue()
        ->and(Http::valid('PURGE'))->toBeTrue()
        ->and(Http::valid('TRACE'))->toBeTrue()
        ->and(Http::valid('CONNECT'))->toBeTrue()
        ->and(Http::valid('QUERY'))->toBeTrue()
        ->and(HttpPortal::valid('TRACE'))->toBeTrue();
});

it('exposes uncommon http methods via magic helpers', function () {
    $client = Http::create();

    expect(method_exists($client, 'purge'))->toBeFalse()
        ->and(is_callable([$client, 'purge']))->toBeTrue()
        ->and(is_callable([$client, 'trace']))->toBeTrue()
        ->and(is_callable([$client, 'connect']))->toBeTrue();
});
