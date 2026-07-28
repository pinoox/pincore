<?php

use Pinoox\Component\Router\RouteMethod;
use Pinoox\Component\Router\RouteRegister;

it('normalizes any aliases to all supported methods', function () {
    expect(RouteMethod::normalize('any'))->toBe(RouteMethod::METHODS)
        ->and(RouteMethod::normalize('all'))->toBe(RouteMethod::METHODS)
        ->and(RouteMethod::normalize('*'))->toBe(RouteMethod::METHODS)
        ->and(RouteMethod::normalize(['GET', 'POST']))->toBe(['GET', 'POST']);
});

it('registers uncommon http methods via route helpers', function () {
    $routes = RouteRegister::collect(function (RouteRegister $routes) {
        $routes->options('/preflight', 'preflight');
        $routes->trace('/debug', 'debug');
        $routes->connect('/tunnel', 'tunnel');
        $routes->any('/catch-all', 'handle');
    });

    expect($routes[0]['methods'])->toBe(['OPTIONS'])
        ->and($routes[1]['methods'])->toBe(['TRACE'])
        ->and($routes[2]['methods'])->toBe(['CONNECT'])
        ->and($routes[3]['methods'])->toBe(RouteMethod::METHODS);
});

it('normalizes manifest entries with any method alias', function () {
    $entry = \Pinoox\Component\Router\RouteManifest::normalizeEntry([
        'path' => '/hook',
        'method' => 'any',
        'action' => 'handle',
    ], forApi: true);

    expect($entry['methods'])->toBe(RouteMethod::METHODS);
});
