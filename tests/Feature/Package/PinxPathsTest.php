<?php



use Pinoox\Component\Package\Pinx\PinxManifest;

use Pinoox\Component\Package\Pinx\PinxPaths;



it('uses project pinx workspace paths for keys and export', function () {

    $package = 'com_test_paths';

    $appPath = sys_get_temp_dir() . '/pinx_paths_' . uniqid('', true);

    mkdir($appPath, 0777, true);



    expect(PinxPaths::defaultKeyRelative($package))->toBe('~pinx/keys/com_test_paths/sign.key.json')

        ->and(str_replace('\\', '/', PinxPaths::defaultKeyPath($package)))->toEndWith('/pinx/keys/com_test_paths/sign.key.json')

        ->and(str_replace('\\', '/', PinxPaths::exportDir($package)))->toEndWith('/pinx/export/com_test_paths');



    $manifest = PinxManifest::fromArray([

        'package' => $package,

        'type' => 'app',

        'version_name' => '1.0.0',

        'version_code' => 3,

    ]);



    $release = PinxPaths::defaultReleasePath($package, $manifest);



    expect(str_replace('\\', '/', $release))->toContain('/pinx/export/com_test_paths/com_test_paths_v3_')

        ->and(str_ends_with($release, '.pinx'))->toBeTrue()

        ->and(is_dir(PinxPaths::exportDir($package)))->toBeTrue();



    @unlink($release);

    @rmdir(PinxPaths::exportDir($package));

    @rmdir(PinxPaths::keysDir($package));

    @rmdir(dirname(PinxPaths::keysDir($package)));

    @rmdir(dirname(PinxPaths::exportDir($package)));

    @rmdir(PinxPaths::workspaceRoot());

});



it('falls back to legacy app pinx key and export directories', function () {

    $package = 'com_test_legacy';

    $appPath = sys_get_temp_dir() . '/pinx_legacy_' . uniqid('', true);

    mkdir($appPath . '/pinx', 0777, true);

    file_put_contents(PinxPaths::legacyKeyPath($appPath), '{}');



    expect(PinxPaths::resolveKeyPath($package, $appPath))->toBe(PinxPaths::legacyKeyPath($appPath));



    mkdir(PinxPaths::legacyRootExportDir($appPath), 0777, true);

    $legacyRelease = PinxPaths::legacyRootExportDir($appPath) . '/legacy-root.pinx';

    file_put_contents($legacyRelease, 'test');



    mkdir(PinxPaths::legacyAppReleasesDir($appPath), 0777, true);

    $legacyReleasesRelease = PinxPaths::legacyAppReleasesDir($appPath) . '/legacy-releases.pinx';

    file_put_contents($legacyReleasesRelease, 'test');



    expect(PinxPaths::collectReleaseFiles($package, $appPath))->toBe([$legacyReleasesRelease, $legacyRelease]);



    @unlink($legacyReleasesRelease);

    @unlink($legacyRelease);

    @unlink(PinxPaths::legacyKeyPath($appPath));

    @rmdir(PinxPaths::legacyAppReleasesDir($appPath));

    @rmdir(PinxPaths::legacyRootExportDir($appPath));

    @rmdir($appPath . '/pinx');

});

