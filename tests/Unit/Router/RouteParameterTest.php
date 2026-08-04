<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\App as PackageApp;
use Pinoox\Component\Router\Parameter\ParameterPatterns;
use Pinoox\Component\Router\Parameter\PathCompiler;
use Pinoox\Component\Router\RouteName;
use Pinoox\Component\Router\RouteRegistrar;
use Pinoox\Component\Router\Router as RouterComponent;
use Pinoox\Portal\Route;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

beforeEach(function () {
    ParameterPatterns::reset();
});

function paramTestRouter(): RouterComponent
{
    $app = test()->createMock(PackageApp::class);
    $app->method('package')->willReturn('com_test_params');
    $app->method('path')->willReturnCallback(
        static fn (string $path = '') => rtrim(testProjectRoot(), '/\\') . '/' . ltrim($path, '/\\')
    );

    return new RouterComponent(new RouteName(), $app);
}

it('compiles optional parameters', function () {
    $compiled = PathCompiler::compile('/users/{id?}');

    expect($compiled['path'])->toBe('/users/{id}')
        ->and($compiled['defaults'])->toHaveKey('id')
        ->and($compiled['defaults']['id'])->toBeNull();
});

it('compiles catch-all and optional catch-all', function () {
    $required = PathCompiler::compile('/docs/{path*}');
    $optional = PathCompiler::compile('/docs/{path*?}');

    expect($required['path'])->toBe('/docs/{path}')
        ->and($required['filters']['path'])->toBe('.*')
        ->and($required['defaults']['path'])->toBe('')
        ->and($optional['defaults']['path'])->toBeNull()
        ->and($required['catch_all'])->toBeTrue();
});

it('compiles built-in types and enums', function () {
    $typed = PathCompiler::compile('/items/{id:int}');
    $enum = PathCompiler::compile('/orders/{status:pending|paid|cancelled}');

    expect($typed['filters']['id'])->toBe('[0-9]+')
        ->and($enum['filters']['status'])->toBe('pending|paid|cancelled');
});

it('registers custom patterns', function () {
    ParameterPatterns::pattern('username', '[a-z][a-z0-9_]{2,20}');

    $compiled = PathCompiler::compile('/u/{username:username}');

    expect($compiled['filters']['username'])->toBe('[a-z][a-z0-9_]{2,20}')
        ->and(ParameterPatterns::has('username'))->toBeTrue();
});

it('matches optional route parameters at runtime', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/users/{id?}', fn () => 'ok')->name('users.optional');
    });

    $with = $router->matchRequest(Request::create('/users/15', 'GET'));
    $without = $router->matchRequest(Request::create('/users', 'GET'));

    expect($with['id'])->toBe('15')
        ->and($with['_route'])->toBe('users.optional')
        ->and($without['_route'])->toBe('users.optional')
        ->and($without['id'] ?? null)->toBeNull();
});

it('matches catch-all path segments', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/docs/{path*}', fn () => 'docs')->name('docs');
    });

    $deep = $router->matchRequest(Request::create('/docs/install/php/linux', 'GET'));
    $root = $router->matchRequest(Request::create('/docs', 'GET'));

    expect($deep['path'])->toBe('install/php/linux')
        ->and($root['path'])->toBe('');
});

it('matches typed int and rejects invalid values', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/items/{id:int}', fn () => 'ok')->name('items.show');
    });

    $ok = $router->matchRequest(Request::create('/items/42', 'GET'));
    expect($ok['id'])->toBe('42');

    $router->matchRequest(Request::create('/items/abc', 'GET'));
})->throws(ResourceNotFoundException::class);

it('matches enum parameters and rejects others', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/orders/{status:pending|paid|cancelled}', fn () => 'ok')->name('orders.status');
    });

    expect($router->matchRequest(Request::create('/orders/paid', 'GET'))['status'])->toBe('paid');

    $router->matchRequest(Request::create('/orders/refunded', 'GET'));
})->throws(ResourceNotFoundException::class);

it('matches file extension style parameters', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/files/{name}.{ext}', fn () => 'file')->name('files.show');
    });

    $matched = $router->matchRequest(Request::create('/files/logo.png', 'GET'));

    expect($matched['name'])->toBe('logo')
        ->and($matched['ext'])->toBe('png');
});

it('matches multiple parameters with catch-all', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/download/{app}/{path*}', fn () => 'dl')->name('download');
    });

    $matched = $router->matchRequest(Request::create('/download/pinoox/releases/v3/file.zip', 'GET'));

    expect($matched['app'])->toBe('pinoox')
        ->and($matched['path'])->toBe('releases/v3/file.zip');
});

it('prefers exact routes over parameterized ones', function () {
    $router = paramTestRouter();

    RouteRegistrar::usingRouter($router, function () {
        Route::get('/users/{id}', fn () => 'param')->name('users.id');
        Route::get('/users', fn () => 'list')->name('users.index');
    });

    $matched = $router->matchRequest(Request::create('/users', 'GET'));

    expect($matched['_route'])->toBe('users.index');
});

it('exposes parameters via Request::route(key)', function () {
    $request = Request::create('/x');
    $request->attributes->set('status', 'paid');
    $request->attributes->set('_router', null);

    expect($request->route('status'))->toBe('paid')
        ->and($request->route('missing', 'n/a'))->toBe('n/a');
});

it('supports Route::pattern facade helpers', function () {
    Route::pattern('snowflake', '[0-9]{19}');
    Route::patterns(['code' => '[A-Z]{3}']);

    expect(Route::hasPattern('snowflake'))->toBeTrue()
        ->and(Route::hasPattern('code'))->toBeTrue();

    Route::clearPatterns();

    expect(Route::hasPattern('int'))->toBeTrue()
        ->and(Route::hasPattern('snowflake'))->toBeFalse();
});
