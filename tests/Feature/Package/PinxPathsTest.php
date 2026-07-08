<?php

use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Package\Pinx\PinxPaths;

it('uses pinx workspace paths for keys and export', function () {
    $appPath = sys_get_temp_dir() . '/pinx_paths_' . uniqid('', true);
    mkdir($appPath, 0777, true);

    expect(PinxPaths::defaultKeyRelative())->toBe('pinx/keys/sign.key.json')
        ->and(str_replace('\\', '/', PinxPaths::defaultKeyPath($appPath)))->toBe(str_replace('\\', '/', $appPath) . '/pinx/keys/sign.key.json')
        ->and(str_replace('\\', '/', PinxPaths::exportDir($appPath)))->toBe(str_replace('\\', '/', $appPath) . '/pinx/export');

    $manifest = PinxManifest::fromArray([
        'package' => 'com_test_paths',
        'type' => 'app',
        'version_name' => '1.0.0',
        'version_code' => 3,
    ]);

    $release = PinxPaths::defaultReleasePath($appPath, 'com_test_paths', $manifest);
    $normalizedAppPath = str_replace('\\', '/', $appPath);

    expect(str_replace('\\', '/', $release))->toStartWith($normalizedAppPath . '/pinx/export/com_test_paths_v3_')
        ->and(str_ends_with($release, '.pinx'))->toBeTrue()
        ->and(is_dir(PinxPaths::exportDir($appPath)))->toBeTrue();

    @unlink($release);
    @rmdir(PinxPaths::exportDir($appPath));
    @rmdir(PinxPaths::keysDir($appPath));
    @rmdir(PinxPaths::workspaceDir($appPath));
});

it('falls back to legacy pinx key and export directories', function () {
    $appPath = sys_get_temp_dir() . '/pinx_legacy_' . uniqid('', true);
    mkdir($appPath . '/pinx', 0777, true);
    file_put_contents(PinxPaths::legacyKeyPath($appPath), '{}');

    expect(PinxPaths::resolveKeyPath($appPath))->toBe(PinxPaths::legacyKeyPath($appPath));

    mkdir(PinxPaths::legacyExportDir($appPath), 0777, true);
    $legacyRelease = PinxPaths::legacyExportDir($appPath) . '/legacy-root.pinx';
    file_put_contents($legacyRelease, 'test');

    mkdir(PinxPaths::legacyReleasesDir($appPath), 0777, true);
    $legacyReleasesRelease = PinxPaths::legacyReleasesDir($appPath) . '/legacy-releases.pinx';
    file_put_contents($legacyReleasesRelease, 'test');

    expect(PinxPaths::collectReleaseFiles($appPath))->toBe([$legacyReleasesRelease, $legacyRelease]);

    @unlink($legacyReleasesRelease);
    @unlink($legacyRelease);
    @unlink(PinxPaths::legacyKeyPath($appPath));
    @rmdir(PinxPaths::legacyReleasesDir($appPath));
    @rmdir(PinxPaths::legacyExportDir($appPath));
    @rmdir($appPath . '/pinx');
});
