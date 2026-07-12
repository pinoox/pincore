<?php

use Pinoox\Component\Http\Http as HttpClient;
use Pinoox\Portal\Http;

it('declares the Http portal contract', function () {
    expectPortalContract(Http::class);
});

it('exposes QUERY as a supported HTTP client method', function () {
    expect(Http::valid('QUERY'))->toBeTrue()
        ->and(HttpClient::valid('query'))->toBeTrue()
        ->and(HttpClient::METHODS)->toContain(HttpClient::QUERY);
});
