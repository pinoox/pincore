<?php

use Pinoox\Component\Test\AppTestKit;
use Pinoox\Component\Test\TestResponse;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\SystemConfig;

afterEach(function () {
    deleteFakeApp('com_test_appkit');
});

it('boots pinoox in test mode', function () {
    AppTestKit::boot();

    expect(config('~pinoox')->get('mode'))->toBe('test');
});

it('creates and removes fake apps', function () {
    fakeApp('com_test_appkit', [
        'routes/web.php' => "<?php\n\nuse function Pinoox\\Router\\get;\n\nget('/', fn () => 'ok');\n",
    ]);

    expect(AppEngine::exists('com_test_appkit'))->toBeTrue()
        ->and(appPath('com_test_appkit'))->toBeDirectory();
});

it('runs callbacks inside app context', function () {
    fakeApp('com_test_appkit');

    $seen = inApp('com_test_appkit', fn () => \Pinoox\Portal\App\App::package());

    expect($seen)->toBe('com_test_appkit');
});

it('builds test responses with helpers', function () {
    $response = new TestResponse(new \Pinoox\Component\Http\Response('{"ok":true}', 200));

    $response->assertOk()->assertJsonPath('ok', true);
});

it('detects package from app test path', function () {
    $file = AppTestKit::path('com_demo', 'tests/Feature/DemoTest.php');

    expect(AppTestKit::detectPackageFromPath($file))->toBe('com_demo');
});

it('stores transient fake apps under tests/Fixtures/runtime/apps', function () {
    if (\Tests\Support\TestRuntime::usesProjectPaths()) {
        test()->markTestSkipped('Runtime apps path override disabled.');
    }

    fakeApp('com_test_appkit');

    expect(appPath('com_test_appkit'))->toBe(testRuntimeApps() . '/com_test_appkit')
        ->and(SystemConfig::path('apps'))->toBe(testRuntimeApps());

    if (AppEngine::exists('com_pinoox_welcome')) {
        expect(AppEngine::path('com_pinoox_welcome'))->toEndWith('com_pinoox_welcome')
            ->and(AppEngine::path('com_pinoox_welcome'))->toBeDirectory();
    }
});

it('stores pinker and storage under tests/Fixtures/runtime', function () {
    if (\Tests\Support\TestRuntime::usesProjectPaths()) {
        test()->markTestSkipped('Runtime path override disabled.');
    }

    expect(SystemConfig::path('pinker'))->toBe(testRuntimePinker())
        ->and(SystemConfig::path('storage'))->toBe(testRuntimeStorage())
        ->and(SystemConfig::path('wizard_tmp'))->toBe(testRuntimePinker() . '/wizard_tmp')
        ->and(SystemConfig::path('pinion_uploads'))->toBe(testRuntimeStorage() . '/pinion')
        ->and(is_dir(testRuntimePinker() . '/apps'))->toBeTrue()
        ->and(is_dir(testRuntimeStorage() . '/pinion'))->toBeTrue();
});

it('detects package from custom apps folder path', function () {
    $customApps = testFixtures('system_config/custom_apps/com_test_custom/tests/Feature/DemoTest.php');
    $appsPath = testFixturesProjectRelative('system_config/custom_apps');

    putenv('PINOOX_APPS_PATH=' . $appsPath);
    $_ENV['PINOOX_APPS_PATH'] = $appsPath;
    $_SERVER['PINOOX_APPS_PATH'] = $appsPath;
    SystemConfig::clearCache();

    try {
        expect(AppTestKit::detectPackageFromPath($customApps))->toBe('com_test_custom');
    } finally {
        putenv('PINOOX_APPS_PATH');
        unset($_ENV['PINOOX_APPS_PATH'], $_SERVER['PINOOX_APPS_PATH']);
        \Tests\Support\TestRuntime::bootstrap(testProjectRoot());
        SystemConfig::clearCache();
    }
});

it('detects package from external registry app test path', function () {
    $package = 'com_test_registry_detect';
    $externalApp = testFixtures('external_apps/' . $package);
    $testsFile = $externalApp . '/tests/Feature/BootTest.php';

    if (!is_dir(dirname($testsFile))) {
        mkdir(dirname($testsFile), 0777, true);
    }

    file_put_contents(
        $externalApp . '/app.php',
        "<?php\n\nreturn ['package' => '{$package}', 'enable' => true, 'name' => 'Detect Test'];\n",
    );
    file_put_contents($testsFile, "<?php\n\nit('boots', fn () => true);\n");

    expect(AppTestKit::detectPackageFromPath(str_replace('\\', '/', $testsFile)))->toBe($package);
});

it('exposes global app test helpers', function () {
    expect(function_exists('appGet'))->toBeTrue()
        ->and(function_exists('inApp'))->toBeTrue()
        ->and(function_exists('fakeApp'))->toBeTrue();
});

