<?php

uses()->group('non-isolated');

use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\AppLayer;
use Pinoox\Component\Transport\TransportContext;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppRouter;
use Pinoox\Portal\SubApp;
use Pinoox\Portal\Router;
use function Pinoox\Router\subApp;

afterEach(function () {
    while (TransportContext::inMeeting()) {
        TransportContext::leave();
    }

    try {
        deleteFakeApp('com_test_subapp_detect');
        deleteFakeApp('com_test_subapp_config');
        deleteFakeApp('com_test_sub_restricted');
        deleteFakeApp('com_test_subapp_only');
        deleteFakeApp('com_test_subapp_route');
        deleteFakeApp('com_test_subapp_nested');
        deleteFakeApp('com_test_custom_opt');
        deleteFakeApp('com_test_direct_dir');
        deleteFakeApp('com_test_fluent_sub');
        deleteFakeApp('com_test_host_app');
        deleteFakeApp('com_inner_sub');
    } catch (\Throwable) {
    }

    try {
        App::___()->setLayer(new AppLayer('/', 'com_pinoox_manager'));
    } catch (\Throwable) {
    }
});

it('detects when running as a sub-app and identifies the host app', function () {
    pinooxBoot();

    fakeApp('com_test_subapp_detect', [
        'app.php' => "<?php return ['package' => 'com_test_subapp_detect', 'enable' => true];",
        'routes/web.php' => "<?php
use function Pinoox\Router\get;
use Pinoox\Portal\App\App;

get('/status', function () {
    return response(json_encode([
        'is_sub' => is_sub_app(),
        'parent' => sub_app_parent(),
        'is_parent_mgr' => is_sub_app_of('com_pinoox_manager'),
        'is_parent_other' => is_sub_app_of('com_other'),
    ]), 200, ['Content-Type' => 'application/json']);
});
",
    ]);

    App::___()->setLayer(new AppLayer('/manager', 'com_pinoox_manager'));

    $request = Request::create('http://localhost/manager/sub/status');
    $response = SubApp::run('com_test_subapp_detect', 'sub', $request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);

    expect($data['is_sub'])->toBeTrue()
        ->and($data['parent'])->toBe('com_pinoox_manager')
        ->and($data['is_parent_mgr'])->toBeTrue()
        ->and($data['is_parent_other'])->toBeFalse();
});

it('injects dynamic app.php configuration overlays into the sub-app', function () {
    pinooxBoot();

    fakeApp('com_test_subapp_config', [
        'app.php' => "<?php return ['package' => 'com_test_subapp_config', 'theme' => 'original_theme', 'enable' => true];",
        'routes/web.php' => "<?php
use function Pinoox\Router\get;
use Pinoox\Portal\App\App;

get('/check-config', function () {
    return response(json_encode([
        'theme' => App::get('theme'),
        'injected' => App::get('injected_custom_key'),
    ]), 200, ['Content-Type' => 'application/json']);
});
",
    ]);

    App::___()->setLayer(new AppLayer('/manager', 'com_pinoox_manager'));

    $request = Request::create('http://localhost/manager/sub/check-config');
    $response = SubApp::run('com_test_subapp_config', 'sub', $request, [
        'config' => [
            'theme' => 'injected_dark_theme',
            'injected_custom_key' => 'custom_val_123',
        ],
    ]);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);

    expect($data['theme'])->toBe('injected_dark_theme')
        ->and($data['injected'])->toBe('custom_val_123');

    // Verify that base config is untouched after SubApp::run finishes
    expect(AppEngine::config('com_test_subapp_config')->get('theme'))->toBe('original_theme')
        ->and(AppEngine::config('com_test_subapp_config')->get('injected_custom_key'))->toBeNull();
});

it('enforces allowed_hosts restrictions', function () {
    pinooxBoot();

    fakeApp('com_test_sub_restricted', [
        'app.php' => "<?php return [
            'package' => 'com_test_sub_restricted',
            'enable' => true,
            'allowed_hosts' => ['com_shop'],
        ];",
        'routes/web.php' => "<?php
use function Pinoox\Router\get;
get('/info', fn () => response('ok'));
",
    ]);

    // Current host is manager, which is NOT in allowed_hosts ['com_shop']
    App::___()->setLayer(new AppLayer('/manager', 'com_pinoox_manager'));

    expect(fn () => SubApp::run('com_test_sub_restricted', 'sub'))
        ->toThrow(\RuntimeException::class);

    // When host is com_shop, it can mount
    expect(SubApp::canMount('com_test_sub_restricted', 'com_shop'))->toBeTrue()
        ->and(SubApp::canMount('com_test_sub_restricted', 'com_pinoox_manager'))->toBeFalse();
});

it('prevents subapp_only apps from running standalone via AppRouter', function () {
    pinooxBoot();

    fakeApp('com_test_subapp_only', [
        'app.php' => "<?php return [
            'package' => 'com_test_subapp_only',
            'enable' => true,
            'subapp_only' => true,
        ];",
    ]);

    expect(SubApp::isSubAppOnly('com_test_subapp_only'))->toBeTrue();

    // AppRouter::stable should be false for subapp_only
    expect(AppRouter::stable('com_test_subapp_only'))->toBeFalse();
});

it('registers subApp routes via the router fluent API', function () {
    pinooxBoot();

    fakeApp('com_test_subapp_route', [
        'app.php' => "<?php return ['package' => 'com_test_subapp_route', 'enable' => true];",
        'routes/web.php' => "<?php
use function Pinoox\Router\get;
get('/verify', fn () => response('verified'));
",
    ]);

    App::___()->setLayer(new AppLayer('/shop', 'com_shop'));

    subApp('/pay', 'com_test_subapp_route')
        ->config(['theme' => 'shop_theme'])
        ->name('shop.pay');

    $allPaths = Router::getAllPath();

    // Ensure the sub-app catch-all route was registered
    $found = false;
    foreach ($allPaths as $path) {
        if (str_contains($path, 'pay')) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue();
});

it('handles nested subpaths accurately within the mounted app', function () {
    pinooxBoot();

    fakeApp('com_test_subapp_nested', [
        'app.php' => "<?php return ['package' => 'com_test_subapp_nested', 'enable' => true];",
        'routes/web.php' => "<?php
use function Pinoox\Router\get;
get('/order/{id}', function (string \$id) {
    return response(json_encode(['order_id' => \$id, 'url' => url('order/' . \$id)]), 200, ['Content-Type' => 'application/json']);
});
",
    ]);

    App::___()->setLayer(new AppLayer('/shop', 'com_shop'));

    $request = Request::create('http://localhost/shop/payment/order/9988');
    $response = SubApp::run('com_test_subapp_nested', 'payment', $request);

    expect($response->getStatusCode())->toBe(200);
    $data = json_decode($response->getContent(), true);

    expect($data['order_id'])->toBe('9988')
        ->and($data['url'])->toContain('/shop/payment/order/9988');
});

it('provides Twig helper functions inside views', function () {
    pinooxBoot();

    App::___()->setLayer(new AppLayer('/shop', 'com_shop'));

    $output = App::meeting('com_pinoox_welcome', function () {
        $view = \Pinoox\Portal\View::___();
        $twig = $view->getTwigEngine()->template;
        $funcIsSub = $twig->getFunction('is_sub_app');
        $funcParent = $twig->getFunction('sub_app_parent');
        $funcIsOf = $twig->getFunction('is_sub_app_of');

        return [
            'is_sub' => call_user_func($funcIsSub->getCallable()),
            'parent' => call_user_func($funcParent->getCallable()),
            'is_shop' => call_user_func($funcIsOf->getCallable(), 'com_shop'),
        ];
    }, '/shop/welcome');

    expect($output['is_sub'])->toBeTrue()
        ->and($output['parent'])->toBe('com_shop')
        ->and($output['is_shop'])->toBeTrue();
});

it('auto-resolves sub-app from custom path passed in options', function () {
    pinooxBoot();

    $customDir = testSandbox('custom_apps/com_test_custom_opt');
    if (!is_dir($customDir)) {
        mkdir($customDir, 0777, true);
    }
    if (!is_dir($customDir . '/routes')) {
        mkdir($customDir . '/routes', 0777, true);
    }

    file_put_contents($customDir . '/app.php', "<?php return ['package' => 'com_test_custom_opt', 'enable' => true];");
    file_put_contents($customDir . '/routes/web.php', "<?php
use function Pinoox\Router\get;
get('/ping', fn () => response('pong-from-custom-path'));
");

    App::___()->setLayer(new AppLayer('/manager', 'com_pinoox_manager'));

    $request = Request::create('http://localhost/manager/opt/ping');
    $response = SubApp::run('com_test_custom_opt', 'opt', $request, [
        'path' => $customDir,
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('pong-from-custom-path');

    $expectedPath = rtrim(str_replace('\\', '/', realpath($customDir) ?: $customDir), '/');
    expect(rtrim(str_replace('\\', '/', SubApp::path('com_test_custom_opt')), '/'))->toBe($expectedPath)
        ->and(rtrim(str_replace('\\', '/', AppEngine::path('com_test_custom_opt')), '/'))->toBe($expectedPath)
        ->and(SubApp::exists('com_test_custom_opt'))->toBeTrue();
});

it('mounts sub-app by passing directory path directly to subApp()', function () {
    pinooxBoot();

    $customDir = testSandbox('custom_apps/com_test_direct_dir');
    if (!is_dir($customDir)) {
        mkdir($customDir, 0777, true);
    }
    if (!is_dir($customDir . '/routes')) {
        mkdir($customDir . '/routes', 0777, true);
    }

    file_put_contents($customDir . '/app.php', "<?php return ['package' => 'com_test_direct_dir', 'enable' => true];");
    file_put_contents($customDir . '/routes/web.php', "<?php
use function Pinoox\Router\get;
get('/hello', fn () => response('hello-from-direct-path'));
");

    App::___()->setLayer(new AppLayer('/shop', 'com_shop'));

    subApp('/direct-dir', $customDir)->name('shop.direct');

    $request = Request::create('http://localhost/shop/direct-dir/hello');
    $response = SubApp::run('com_test_direct_dir', '/direct-dir', $request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('hello-from-direct-path')
        ->and(SubApp::exists('com_test_direct_dir'))->toBeTrue();
});

it('supports fluent ->path() on SubAppRouteBuilder', function () {
    pinooxBoot();

    $customDir = testSandbox('custom_apps/com_test_fluent_sub');
    if (!is_dir($customDir)) {
        mkdir($customDir, 0777, true);
    }
    if (!is_dir($customDir . '/routes')) {
        mkdir($customDir . '/routes', 0777, true);
    }

    file_put_contents($customDir . '/app.php', "<?php return ['package' => 'com_test_fluent_sub', 'enable' => true];");
    file_put_contents($customDir . '/routes/web.php', "<?php
use function Pinoox\Router\get;
get('/greet', fn () => response('fluent-greet-ok'));
");

    App::___()->setLayer(new AppLayer('/shop', 'com_shop'));

    subApp('/fluent-test', 'com_test_fluent_sub')
        ->path($customDir)
        ->name('shop.fluent');

    $request = Request::create('http://localhost/shop/fluent-test/greet');
    $response = SubApp::run('com_test_fluent_sub', '/fluent-test', $request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('fluent-greet-ok');
});

it('auto-detects sub-app located inside host app sub_apps folder', function () {
    pinooxBoot();

    $hostPath = fakeApp('com_test_host_app', [
        'app.php' => "<?php return ['package' => 'com_test_host_app', 'enable' => true];",
    ]);

    $innerSubDir = $hostPath . '/sub_apps/com_inner_sub';
    if (!is_dir($innerSubDir . '/routes')) {
        mkdir($innerSubDir . '/routes', 0777, true);
    }

    file_put_contents($innerSubDir . '/app.php', "<?php return ['package' => 'com_inner_sub', 'enable' => true];");
    file_put_contents($innerSubDir . '/routes/web.php', "<?php
use function Pinoox\Router\get;
get('/auto-detect-test', fn () => response('inner-sub-detected'));
");

    App::___()->setLayer(new AppLayer('/host', 'com_test_host_app'));

    // SubApp should automatically detect 'com_inner_sub' inside host's sub_apps folder
    expect(SubApp::exists('com_inner_sub'))->toBeTrue();

    $expectedPath = rtrim(str_replace('\\', '/', realpath($innerSubDir) ?: $innerSubDir), '/');
    expect(rtrim(str_replace('\\', '/', SubApp::path('com_inner_sub')), '/'))->toBe($expectedPath);

    $request = Request::create('http://localhost/host/inner/auto-detect-test');
    $response = SubApp::run('com_inner_sub', 'inner', $request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('inner-sub-detected');
});

it('provides sub_app_path helper function in PHP and Twig', function () {
    pinooxBoot();

    $hostPath = fakeApp('com_test_host_app', [
        'app.php' => "<?php return ['package' => 'com_test_host_app', 'enable' => true];",
    ]);

    App::___()->setLayer(new AppLayer('/host', 'com_test_host_app'));

    // Inside meeting, sub_app_path() without arguments returns current sub-app path
    $paths = App::meeting('com_pinoox_welcome', function () {
        $view = \Pinoox\Portal\View::___();
        $twig = $view->getTwigEngine()->template;
        $funcTwigPath = $twig->getFunction('sub_app_path');

        return [
            'php_current' => sub_app_path(),
            'php_welcome' => sub_app_path('com_pinoox_welcome'),
            'twig_current' => call_user_func($funcTwigPath->getCallable()),
            'twig_welcome' => call_user_func($funcTwigPath->getCallable(), 'com_pinoox_welcome'),
        ];
    }, '/host/welcome');

    $expectedWelcomePath = rtrim(str_replace('\\', '/', AppEngine::path('com_pinoox_welcome')), '/');

    expect(rtrim(str_replace('\\', '/', $paths['php_current']), '/'))->toBe($expectedWelcomePath)
        ->and(rtrim(str_replace('\\', '/', $paths['php_welcome']), '/'))->toBe($expectedWelcomePath)
        ->and(rtrim(str_replace('\\', '/', $paths['twig_current']), '/'))->toBe($expectedWelcomePath)
        ->and(rtrim(str_replace('\\', '/', $paths['twig_welcome']), '/'))->toBe($expectedWelcomePath);
});

