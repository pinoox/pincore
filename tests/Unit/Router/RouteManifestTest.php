<?php

use Pinoox\Component\Router\RouteManifest;
use Pinoox\Component\Router\RouteRegister;

it('expands nested route groups with prefix, name, and controller defaults', function () {
    $routes = RouteManifest::expandRoutes([
        [
            'prefix' => '/options',
            'as' => 'options.',
            'controller' => 'App\\Demo\\OptionController',
            'routes' => [
                ['method' => 'GET', 'uri' => '/get', 'action' => 'getOptions', 'name' => 'get'],
                [
                    'prefix' => '/wallpaper',
                    'as' => 'wallpaper.',
                    'routes' => [
                        ['method' => 'POST', 'uri' => '/upload', 'action' => 'upload', 'name' => 'upload'],
                    ],
                ],
            ],
        ],
    ]);

    expect($routes)->toHaveCount(2)
        ->and($routes[0]['uri'])->toBe('/options/get')
        ->and($routes[0]['name'])->toBe('options.get')
        ->and($routes[0]['action'])->toBe(['App\\Demo\\OptionController', 'getOptions'])
        ->and($routes[1]['uri'])->toBe('/options/wallpaper/upload')
        ->and($routes[1]['name'])->toBe('options.wallpaper.upload')
        ->and($routes[1]['action'])->toBe(['App\\Demo\\OptionController', 'upload']);
});

it('applies group attributes in collect mode', function () {
    $routes = RouteRegister::collect(function (RouteRegister $routes) {
        $routes->group([
            'prefix' => '/user',
            'as' => 'user.',
            'controller' => 'App\\Demo\\UserController',
        ], function (RouteRegister $routes) {
            $routes->get('/get', 'get')->name('get');
        });
    });

    expect($routes[0]['uri'])->toBe('/user/get')
        ->and($routes[0]['name'])->toBe('user.get')
        ->and($routes[0]['action'])->toBe(['App\\Demo\\UserController', 'get']);
});

it('merges manifest and group flows', function () {
    $manifest = RouteManifest::normalizeManifest([
        'flow' => ['manager.auth'],
        'routes' => [
            [
                'prefix' => '/auth',
                'as' => 'auth.',
                'controller' => 'App\\Demo\\AuthController',
                'flow' => ['screen.lock'],
                'routes' => [
                    ['method' => 'GET', 'uri' => '/lock', 'action' => 'lock', 'name' => 'lock'],
                ],
            ],
        ],
    ]);

    expect($manifest['routes'][0]['flow'])->toBe(['manager.auth', 'screen.lock']);
});
