<?php

use Pinoox\Component\Http\Http;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

it('sends QUERY requests through the client', function () {
    $client = new MockHttpClient(function (string $method, string $url, array $options = []) {
        expect($method)->toBe('QUERY')
            ->and($url)->toBe('https://api.example.com/search');

        return new MockResponse('{"ok":true}', ['http_code' => 200]);
    });

    $http = Http::create([], $client);
    $response = $http->query('https://api.example.com/search', [
        'json' => ['q' => 'pinoox'],
    ]);

    expect($response)->not->toBeNull()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('{"ok":true}');
});

it('rejects unsupported HTTP methods', function () {
    $http = Http::create([], new MockHttpClient());

    $http->request('CONNECT', 'https://example.com');
})->throws(BadMethodCallException::class);

it('keeps static verb helpers for backward compatibility', function () {
    $client = new MockHttpClient([
        new MockResponse('get-ok'),
        new MockResponse('query-ok'),
    ]);

    $http = Http::create([], $client);

    expect($http->get('https://example.com/a')->getContent())->toBe('get-ok')
        ->and($http->query('https://example.com/b', ['json' => ['a' => 1]])->getContent())->toBe('query-ok')
        ->and(Http::valid('PATCH'))->toBeTrue()
        ->and(Http::valid('FOO'))->toBeFalse();
});

it('clones default options with withOptions', function () {
    $http = Http::create(['timeout' => 5], new MockHttpClient());
    $scoped = $http->withOptions(['timeout' => 15, 'headers' => ['X-Test' => '1']]);

    expect($scoped)->not->toBe($http)
        ->and($scoped->getDefaultOptions()['timeout'])->toBe(15)
        ->and($scoped->getDefaultOptions()['headers']['X-Test'])->toBe('1')
        ->and($http->getDefaultOptions()['timeout'])->toBe(5);
});
