<?php

use Pinoox\Component\Package\Pinx\PlatformArchive;

it('preserves runtime paths and allows BUILD.json', function () {
    expect(PlatformArchive::shouldPreserve('.env'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('.env.local'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('storage/logs/pinoox.log'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('uploads/photo.jpg'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('downloads/file.zip'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pinker/state/app.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pincore/composer.json'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('storage/BUILD.json'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('index.php'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('apps/com_pinoox_manager/app.php'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('vendor/autoload.php'))->toBeFalse();
});

it('lists apps from zip entry names', function () {
    $apps = PlatformArchive::appsFromEntries([
        'index.php',
        'apps/com_pinoox_manager/app.php',
        'apps/com_pinoox_manager/theme/spark/index.twig',
        'apps/com_pinoox_welcome/app.php',
        'apps/com_pinoox_installer/app.php',
        'vendor/autoload.php',
        'storage/BUILD.json',
    ]);

    expect($apps)->toBe([
        'com_pinoox_installer',
        'com_pinoox_manager',
        'com_pinoox_welcome',
    ]);
});

it('detects a wrapping folder prefix', function () {
    $prefix = PlatformArchive::archivePrefix([
        'pinoox/index.php',
        'pinoox/storage/BUILD.json',
        'pinoox/apps/com_pinoox_manager/app.php',
        'pinoox/vendor/autoload.php',
    ]);

    expect($prefix)->toBe('pinoox/')
        ->and(PlatformArchive::appsFromEntries([
            'pinoox/apps/com_pinoox_manager/app.php',
            'pinoox/index.php',
        ]))->toBe(['com_pinoox_manager']);
});

it('does not treat vendor/ as an archive prefix', function () {
    expect(PlatformArchive::archivePrefix([
        'vendor/autoload.php',
        'vendor/pinoox/pincore/composer.json',
        'index.php',
    ]))->toBe('');
});

it('reads version fields from BUILD.json payload', function () {
    expect(PlatformArchive::versionFromManifest([
        'type' => 'platform',
        'version_name' => '3.3.14',
        'version_code' => 55,
    ]))->toBe([
        'name' => '3.3.14',
        'code' => 55,
    ]);
});

it('rejects older archives unless versions are unknown', function () {
    expect(PlatformArchive::isNewerOrEqual(54, 55))->toBeFalse()
        ->and(PlatformArchive::isNewerOrEqual(55, 55))->toBeTrue()
        ->and(PlatformArchive::isNewerOrEqual(56, 55))->toBeTrue()
        ->and(PlatformArchive::isNewerOrEqual(null, 55))->toBeTrue();
});
