<?php

use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\AppPackagePath;
use Pinoox\Support\SystemConfig;

it('resolves app config from registry-backed package paths', function () {
    $package = 'com_test_package_path';
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/' . $package));

    if (!is_dir($externalApp)) {
        mkdir($externalApp, 0777, true);
    }

    file_put_contents(
        $externalApp . '/app.php',
        "<?php\n\nreturn ['package' => '{$package}', 'version-name' => 'External', 'version-code' => 9];\n",
    );

    AppTestKit::boot();
    AppEngine::add($package, $externalApp);

    try {
        expect(AppPackagePath::configFile($package))->toBe($externalApp . '/app.php');
    } finally {
        AppEngine::__rebuild();
    }
});

it('detects package names from tests outside the default apps folder', function () {
    $package = 'com_test_package_detect';
    $externalApp = testFixtures('external_apps/' . $package);
    $testsFile = $externalApp . '/tests/Unit/ExampleTest.php';

    if (!is_dir(dirname($testsFile))) {
        mkdir(dirname($testsFile), 0777, true);
    }

    file_put_contents(
        $externalApp . '/app.php',
        "<?php\n\nreturn ['package' => '{$package}', 'enable' => true];\n",
    );
    file_put_contents($testsFile, "<?php\n\nit('works', fn () => true);\n");

    expect(AppPackagePath::fromTestsFile(str_replace('\\', '/', $testsFile)))->toBe($package);
});

it('detects package names from a custom apps folder configured by env', function () {
    $appsPath = testFixturesProjectRelative('system_config/custom_apps');
    $testsFile = testFixtures('system_config/custom_apps/com_test_custom/tests/Feature/DemoTest.php');

    putenv('PINOOX_APPS_PATH=' . $appsPath);
    $_ENV['PINOOX_APPS_PATH'] = $appsPath;
    $_SERVER['PINOOX_APPS_PATH'] = $appsPath;
    SystemConfig::clearCache();

    try {
        expect(AppPackagePath::fromTestsFile(str_replace('\\', '/', $testsFile)))->toBe('com_test_custom');
    } finally {
        putenv('PINOOX_APPS_PATH');
        unset($_ENV['PINOOX_APPS_PATH'], $_SERVER['PINOOX_APPS_PATH']);
        \Tests\Support\TestRuntime::bootstrap(testProjectRoot());
        SystemConfig::clearCache();
    }
});
