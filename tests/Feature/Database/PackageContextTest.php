<?php

use Pinoox\Component\Test\AppTestKit;
use Pinoox\Support\AppPackagePath;
use Pinoox\Support\PackageContext;

beforeEach(function () {
    PackageContext::use(null);
});

afterEach(function () {
    PackageContext::use(null);
    AppTestKit::cleanupTransientArtifacts(false);
});

it('detects package from app seed and migration paths', function () {
    $package = 'com_demo_shop';
    AppTestKit::fakeApp($package);
    $appDir = AppTestKit::path($package);

    expect(AppPackagePath::fromDataFile($appDir . '/database/seed/DemoSeeder.php'))->toBe($package)
        ->and(AppPackagePath::fromDataFile($appDir . '/database/migrations/2026_01_01_000000_create_demo_table.php'))->toBe($package)
        ->and(AppPackagePath::fromDataFile($appDir . '/patches/2026_01_01_000000_demo.php'))->toBe($package);
});

it('detects platform package from pincore data files', function () {
    $corePath = rtrim(str_replace('\\', '/', testCoreRoot()), '/');

    expect(AppPackagePath::fromDataFile($corePath . '/database/migrations/2023_09_11_063510_create_history_table.php'))
        ->toBe('platform');
});

it('prefers explicit package over runtime package context', function () {
    PackageContext::use('com_runtime');

    expect(PackageContext::resolve('com_explicit'))->toBe('com_explicit');
});

it('uses runtime package when explicit package is omitted', function () {
    PackageContext::use('com_runtime');

    expect(PackageContext::resolve())->toBe('com_runtime');
});

it('resolves package from data file path when runtime package is not set', function () {
    $package = 'com_demo_shop';
    AppTestKit::fakeApp($package);
    $seedFile = AppTestKit::path($package) . '/database/seed/DemoSeeder.php';

    expect(PackageContext::resolve(null, $seedFile))->toBe($package);
});
