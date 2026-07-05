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

it('loads and merges route files from a directory', function () {
    $directory = testSandbox('route_sources');
    $publicFile = $directory . '/public.php';
    $privateFile = $directory . '/private.php';

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($publicFile, <<<'PHP'
<?php
return [
    ['method' => 'GET', 'uri' => '/open/ping', 'action' => ['App\\Demo\\OpenController', 'ping'], 'name' => 'open.ping'],
];
PHP);
    file_put_contents($privateFile, <<<'PHP'
<?php
return [
    'flow' => ['demo.auth'],
    'routes' => [[
        'prefix' => '/secure',
        'as' => 'secure.',
        'controller' => 'App\\Demo\\SecureController',
        'routes' => [
            ['method' => 'GET', 'uri' => '/profile', 'action' => 'profile', 'name' => 'profile'],
        ],
    ]],
];
PHP);

    $manifest = RouteManifest::normalizeManifest([
        'routes' => $directory,
    ]);

    expect($manifest['routes'])->toHaveCount(2)
        ->and($manifest['routes'][0]['name'])->toBe('secure.profile')
        ->and($manifest['routes'][0]['flow'])->toBe(['demo.auth'])
        ->and($manifest['routes'][1]['name'])->toBe('open.ping')
        ->and($manifest['routes'][1]['uri'])->toBe('/open/ping')
        ->and($manifest['routes'][1]['flow'])->toBe([]);
});

it('loads explicit route file paths in order', function () {
    $directory = testSandbox('route_source_files');
    $publicFile = $directory . '/public.php';
    $privateFile = $directory . '/private.php';

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($publicFile, <<<'PHP'
<?php
return [
    ['method' => 'GET', 'uri' => '/open/ping', 'action' => ['App\\Demo\\OpenController', 'ping'], 'name' => 'open.ping'],
];
PHP);
    file_put_contents($privateFile, <<<'PHP'
<?php
return [
    'flow' => ['demo.auth'],
    'routes' => [[
        'prefix' => '/secure',
        'as' => 'secure.',
        'controller' => 'App\\Demo\\SecureController',
        'routes' => [
            ['method' => 'GET', 'uri' => '/profile', 'action' => 'profile', 'name' => 'profile'],
        ],
    ]],
];
PHP);

    $manifest = RouteManifest::normalizeManifest([
        'routes' => [
            $privateFile,
            $publicFile,
        ],
    ]);

    expect($manifest['routes'][0]['name'])->toBe('secure.profile')
        ->and($manifest['routes'][1]['name'])->toBe('open.ping');
});
