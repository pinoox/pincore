<?php

use Pinoox\Support\SystemConfig;
use Pinoox\Tests\Support\TestRuntime;

it('redirects apps path to fixtures runtime instead of project apps', function () {
    if (TestRuntime::usesProjectPaths()) {
        test()->markTestSkipped('Runtime apps path override disabled.');
    }

    expect(SystemConfig::path('apps'))->toBe(testRuntimeApps())
        ->and(SystemConfig::path('apps'))->not->toBe(testProjectRoot() . '/apps');
});

it('keeps project config loads inside runtime pinker', function () {
    if (TestRuntime::usesProjectPaths()) {
        test()->markTestSkipped('Runtime path override disabled.');
    }

    $projectConfig = testSandbox('isolated_project_config');
    if (!is_dir($projectConfig)) {
        mkdir($projectConfig, 0777, true);
    }

    file_put_contents(
        $projectConfig . '/app-router.config.php',
        "<?php\n\nreturn ['/' => 'com_test_isolated_router'];\n",
    );

    $watched = [
        testProjectRoot() . '/pinker/platform/app-router.config.php',
        testProjectRoot() . '/pinker/state/platform/app-router.config.php',
    ];
    $before = testRuntimeIsolationFileSignatures($watched);

    putenv('PINOOX_PROJECT_CONFIG_PATH=' . TestRuntime::projectRelative($projectConfig));
    $_ENV['PINOOX_PROJECT_CONFIG_PATH'] = TestRuntime::projectRelative($projectConfig);
    $_SERVER['PINOOX_PROJECT_CONFIG_PATH'] = TestRuntime::projectRelative($projectConfig);
    SystemConfig::clearCache();

    try {
        expect(SystemConfig::path('pinker'))->toBe(testRuntimePinker())
            ->and(SystemConfig::path('pinker'))->not->toBe(testProjectRoot() . '/pinker')
            ->and(SystemConfig::get('app-router', '/'))->toBe('com_test_isolated_router')
            ->and(testRuntimeIsolationFileSignatures($watched))->toBe($before);
    } finally {
        putenv('PINOOX_PROJECT_CONFIG_PATH');
        unset($_ENV['PINOOX_PROJECT_CONFIG_PATH'], $_SERVER['PINOOX_PROJECT_CONFIG_PATH']);
        TestRuntime::bootstrap(testProjectRoot());
        SystemConfig::clearCache();
    }
});

it('does not register packages from project apps folder', function () {
    if (TestRuntime::usesProjectPaths()) {
        test()->markTestSkipped('Runtime apps path override disabled.');
    }

    $registryFile = testRuntimeRoot() . '/project-apps.registry.php';

    expect(is_file($registryFile))->toBeTrue();

    $registry = require $registryFile;
    $packages = is_array($registry['packages'] ?? null) ? $registry['packages'] : [];

    foreach ($packages as $package => $path) {
        if (!is_string($path)) {
            continue;
        }

        expect($path)->not->toStartWith('~/apps/')
            ->and($path)->not->toStartWith('apps/com_');
    }

    $projectApps = testProjectRoot() . '/apps';
    if (!is_dir($projectApps)) {
        return;
    }

    foreach (scandir($projectApps) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !str_starts_with($entry, 'com_')) {
            continue;
        }

        if (str_starts_with($entry, 'com_test_') || str_starts_with($entry, 'com_boot_')) {
            continue;
        }

        if (!isset($packages[$entry]) || !is_string($packages[$entry])) {
            continue;
        }

        expect($packages[$entry])->not->toStartWith('~/apps/')
            ->and($packages[$entry])->not->toMatch('#^apps/com_#');
    }
});

/**
 * @param list<string> $paths
 * @return array<string, array{mtime: int, size: int, hash: string}|null>
 */
function testRuntimeIsolationFileSignatures(array $paths): array
{
    $signatures = [];

    foreach ($paths as $path) {
        $signatures[$path] = is_file($path)
            ? [
                'mtime' => filemtime($path) ?: 0,
                'size' => filesize($path) ?: 0,
                'hash' => sha1_file($path) ?: '',
            ]
            : null;
    }

    return $signatures;
}
