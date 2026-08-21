<?php

use Pinoox\Component\Package\Pinx\PlatformArchive;

it('preserves runtime paths and allows BUILD.json', function () {
    expect(PlatformArchive::shouldPreserve('.env'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('.env.local'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('storage/logs/pinoox.log'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('uploads/photo.jpg'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('downloads/file.zip'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pinker/state/app.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pinker/state/platform/database.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pinker/stable/platform/database.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pinker/bake/platform/database.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('platform/app-router.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('platform/domain.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('platform/apps.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('platform/app-router.config.php', sys_get_temp_dir() . '/pinroll-missing-router-' . bin2hex(random_bytes(2))))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('platform/pinoox.config.php'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('pinroll/pinroll.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('.pinoox/pinroll.config.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pingate.php'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('pincore/composer.json'))->toBeTrue()
        ->and(PlatformArchive::shouldPreserve('storage/BUILD.json'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('index.php'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('apps/com_pinoox_manager/app.php'))->toBeFalse()
        ->and(PlatformArchive::shouldPreserve('vendor/autoload.php'))->toBeFalse();
});

it('does not preserve an empty host app-router so the zip can seed it', function () {
    $tmp = sys_get_temp_dir() . '/pincore-empty-router-' . bin2hex(random_bytes(3));
    mkdir($tmp . '/platform', 0755, true);
    file_put_contents($tmp . '/platform/app-router.config.php', "<?php\nreturn [];\n");

    expect(PlatformArchive::shouldPreserve('platform/app-router.config.php', $tmp))->toBeFalse();

    file_put_contents($tmp . '/platform/app-router.config.php', "<?php\nreturn ['/' => 'com_pinoox_installer'];\n");

    expect(PlatformArchive::shouldPreserve('platform/app-router.config.php', $tmp))->toBeTrue();

    @unlink($tmp . '/platform/app-router.config.php');
    @rmdir($tmp . '/platform');
    @rmdir($tmp);
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

it('does not treat app pinx archives as platform zips', function () {
    if (!class_exists(ZipArchive::class)) {
        test()->markTestSkipped('ZipArchive extension not available');
    }

    $tmp = sys_get_temp_dir() . '/pincore-pinx-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, true);
    $archive = $tmp . '/com_pinoox_manager.pinx';

    $zip = new ZipArchive();
    expect($zip->open($archive, ZipArchive::CREATE))->toBeTrue();
    $zip->addFromString('manifest.json', json_encode([
        'format' => 'pinx',
        'type' => 'app',
        'package' => 'com_pinoox_manager',
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('payload/app.php', "<?php return ['package' => 'com_pinoox_manager'];");
    $zip->close();

    expect(PlatformArchive::isPinxPackageArchive($archive))->toBeTrue()
        ->and(PlatformArchive::isPlatformArchive($archive))->toBeFalse();

    @unlink($archive);
    @rmdir($tmp);
});
