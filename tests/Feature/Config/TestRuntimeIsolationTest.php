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
