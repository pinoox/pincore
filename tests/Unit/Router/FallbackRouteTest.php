<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\App as PackageApp;
use Pinoox\Component\Router\RouteName;
use Pinoox\Component\Router\RouteRegistrar;
use Pinoox\Component\Router\Router as RouterComponent;
use Pinoox\Portal\Route;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

function fallbackTestRouter(string $package = 'com_test_fallback'): RouterComponent
{
    $app = test()->createMock(PackageApp::class);
    $app->method('package')->willReturn($package);
    $app->method('path')->willReturnCallback(
        static fn (string $path = '') => rtrim(testProjectRoot(), '/\\') . '/' . ltrim($path, '/\\')
    );

    return new RouterComponent(new RouteName(), $app);
}

it('matches global fallback when no other route matches', function () {
    $router = fallbackTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/home', fn () => 'home')->name('home');
        Route::fallback(fn () => 'missing')->name('fallback');
    });

    $matched = $router->matchRequest(Request::create('/unknown-page', 'GET'));

    expect($matched['_route'])->toBe('fallback')
        ->and($matched['_router']->getData()['fallback'] ?? null)->toBeTrue();
});

it('prefers group fallback over global fallback', function () {
    $router = fallbackTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::fallback(fn () => 'global')->name('fallback.global');

        Route::group(['prefix' => '/api'], function () {
            Route::get('/ping', fn () => 'pong')->name('api.ping');
            Route::fallback(fn () => 'api-missing')->name('fallback.api');
        });
    });

    $apiMiss = $router->matchRequest(Request::create('/api/nope', 'GET'));
    $webMiss = $router->matchRequest(Request::create('/web-nope', 'GET'));

    expect($apiMiss['_route'])->toBe('fallback.api')
        ->and($webMiss['_route'])->toBe('fallback.global');
});

it('supports prefix() helper with nested fallback', function () {
    $router = fallbackTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::prefix('/admin', function () {
            Route::get('/dashboard', fn () => 'dash')->name('admin.dashboard');
            Route::fallback(fn () => 'admin-404')->name('fallback.admin');
        });
    });

    $matched = $router->matchRequest(Request::create('/admin/settings', 'GET'));

    expect($matched['_route'])->toBe('fallback.admin');
});

it('allows flow chaining on fallback routes', function () {
    $router = fallbackTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::fallback(fn () => 'x')
            ->name('fallback.json')
            ->flow(['cors:api']);
    });

    $route = $router->all()['fallback.json'];
    $pinoox = $route->getDefault('_router');

    expect($pinoox->flows)->toBe(['cors:api'])
        ->and($pinoox->getData()['fallback'] ?? null)->toBeTrue();
});

it('matches fallback for any http method', function () {
    $router = fallbackTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::fallback(fn () => 'x')->name('fallback.any');
    });

    $matched = $router->matchRequest(Request::create('/missing', 'POST'));

    expect($matched['_route'])->toBe('fallback.any');
});

it('still throws when no fallback is registered', function () {
    $router = fallbackTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/only', fn () => 'ok')->name('only');
    });

    $router->matchRequest(Request::create('/nope', 'GET'));
})->throws(ResourceNotFoundException::class);

it('expands collect-mode group fallback paths as /prefix/*', function () {
    $routes = \Pinoox\Component\Router\RouteRegister::collect(function ($routes) {
        $routes->group(['prefix' => '/api'], function ($routes) {
            $routes->fallback(fn () => 'api');
        });
    });

    expect($routes[0]['uri'] ?? $routes[0]['path'] ?? null)->toBe('/api/*')
        ->and($routes[0]['data']['fallback'] ?? null)->toBeTrue();
});
