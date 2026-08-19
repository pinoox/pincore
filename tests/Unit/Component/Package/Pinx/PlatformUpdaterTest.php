<?php

use Pinoox\Component\Package\Pinx\PlatformUpdater;
use Pinoox\Portal\App\AppEngine;

it('applies archive files while preserving runtime data and extra apps', function () {
    $sandbox = testSandbox('platform-update/' . uniqid('case_', true));
    $project = $sandbox . '/project';
    $zipPath = $sandbox . '/platform.zip';

    mkdir($project . '/storage', 0777, true);
    mkdir($project . '/apps/com_user_shop', 0777, true);
    file_put_contents($project . '/.env', "SECRET=keep-me\n");
    file_put_contents($project . '/storage/keep.txt', 'runtime');
    file_put_contents($project . '/index.php', '<?php // old');
    file_put_contents($project . '/apps/com_user_shop/app.php', "<?php\nreturn ['package' => 'com_user_shop'];\n");

    $zip = new \ZipArchive();
    expect($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('index.php', '<?php // new');
    $zip->addFromString('storage/BUILD.json', json_encode([
        'type' => 'platform',
        'version_name' => '9.9.9',
        'version_code' => 9999,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->addFromString('.env', "SECRET=overwrite\n");
    $zip->addFromString('apps/com_pinoox_manager/app.php', "<?php\nreturn ['package' => 'com_pinoox_manager', 'sys-app' => true, 'version-name' => '2.9.0', 'version-code' => 200];\n");
    $zip->close();

    $result = (new PlatformUpdater(AppEngine::___()))->update($zipPath, [
        'project_root' => $project,
        'force' => true,
        'skip_migrate' => true,
        'skip_patch' => true,
        'skip_lifecycle' => true,
        'skip_cache' => true,
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->apps)->toBe(['com_pinoox_manager'])
        ->and(file_get_contents($project . '/index.php'))->toContain('new')
        ->and(file_get_contents($project . '/.env'))->toContain('keep-me')
        ->and(file_get_contents($project . '/storage/keep.txt'))->toBe('runtime')
        ->and(file_get_contents($project . '/storage/BUILD.json'))->toContain('"type": "platform"')
        ->and(is_file($project . '/apps/com_user_shop/app.php'))->toBeTrue()
        ->and(is_file($project . '/apps/com_pinoox_manager/app.php'))->toBeTrue()
        ->and(is_dir($project . '/storage/.platform-update'))->toBeFalse();
});
