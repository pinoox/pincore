<?php

use Pinoox\Component\Router\RouteManifest;
use function Pinoox\Router\collect;

it('builds a manifest from collect with shared attributes and fluent groups', function () {
    $manifest = RouteManifest::normalizeManifest(collect(['flow' => ['demo.auth']], function () {
        \Pinoox\Router\group('/user')
            ->as('user.')
            ->controller('App\\Demo\\UserController')
            ->routes(function () {
                \Pinoox\Router\get('/get', 'get')->name('get');
                \Pinoox\Router\post('/save', 'save')->name('save');
            });
    }));

    expect($manifest['routes'])->toHaveCount(2)
        ->and($manifest['routes'][0]['name'])->toBe('user.get')
        ->and($manifest['routes'][0]['uri'])->toBe('/user/get')
        ->and($manifest['routes'][0]['flow'])->toBe(['demo.auth'])
        ->and($manifest['routes'][1]['name'])->toBe('user.save')
        ->and($manifest['routes'][1]['method'])->toBe('POST');
});

it('supports nested fluent groups inside collect with attributes', function () {
    $manifest = RouteManifest::normalizeManifest(collect([], function () {
        \Pinoox\Router\group('/app')
            ->as('app.')
            ->controller('App\\Demo\\AppController')
            ->routes(function () {
                \Pinoox\Router\group('/pinion')
                    ->as('pinion.')
                    ->controller('App\\Demo\\PinionController')
                    ->route(function () {
                        \Pinoox\Router\get('/limits', 'limits')->name('limits');
                    });
            });
    }));

    expect($manifest['routes'][0]['name'])->toBe('app.pinion.limits')
        ->and($manifest['routes'][0]['uri'])->toBe('/app/pinion/limits');
});

it('supports fluent group builder with explicit prefix method', function () {
    $manifest = RouteManifest::normalizeManifest(collect([], function () {
        \Pinoox\Router\group()
            ->prefix('/auth')
            ->as('auth.')
            ->controller('App\\Demo\\AuthController')
            ->routes(function () {
                \Pinoox\Router\get('/lock', 'lock')->name('lock');
            });
    }));

    expect($manifest['routes'][0]['name'])->toBe('auth.lock')
        ->and($manifest['routes'][0]['uri'])->toBe('/auth/lock');
});

it('keeps legacy array group syntax working', function () {
    $manifest = RouteManifest::normalizeManifest(collect([], function () {
        \Pinoox\Router\group([
            'prefix' => '/legacy',
            'as' => 'legacy.',
            'controller' => 'App\\Demo\\LegacyController',
        ], function () {
            \Pinoox\Router\get('/ping', 'ping')->name('ping');
        });
    }));

    expect($manifest['routes'][0]['name'])->toBe('legacy.ping')
        ->and($manifest['routes'][0]['uri'])->toBe('/legacy/ping');
});

it('keeps single-callback collect returning a plain route list', function () {
    $routes = collect(function () {
        \Pinoox\Router\get('/ping', 'App\\Demo\\PingController@ping')->name('ping');
    });

    expect($routes)->toBeList()
        ->and($routes[0]['name'])->toBe('ping');
});
