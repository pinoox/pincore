<?php

use Pinoox\Component\Package\Pinx\PlatformUpdater;
use Pinoox\Portal\App\AppEngine;

it('applies archive files while preserving runtime data and extra apps', function () {
    $sandbox = testSandbox('platform-update/' . uniqid('case_', true));
    $project = $sandbox . '/project';
    $zipPath = $sandbox . '/platform.zip';

    mkdir($project . '/storage', 0777, true);
    mkdir($project . '/apps/com_user_shop', 0777, true);
    mkdir($project . '/apps/com_pinoox_manager/pinker', 0777, true);
    mkdir($project . '/pinker/state/platform', 0777, true);
    mkdir($project . '/pinker/stable/platform', 0777, true);
    mkdir($project . '/platform', 0777, true);
    file_put_contents($project . '/.env', "SECRET=keep-me\nDB_CONNECTION=mysql\n");
    file_put_contents($project . '/storage/keep.txt', 'runtime');
    file_put_contents($project . '/index.php', '<?php // old');
    file_put_contents($project . '/apps/com_user_shop/app.php', "<?php\nreturn ['package' => 'com_user_shop'];\n");
    file_put_contents($project . '/apps/com_pinoox_manager/pinker/keep.txt', 'app-pinker');
    file_put_contents($project . '/platform/app-router.config.php', "<?php\nreturn ['/' => 'com_pinoox_welcome'];\n");
    file_put_contents($project . '/platform/pinoox.config.php', "<?php\nreturn ['version_name' => '1.0.0'];\n");
    file_put_contents($project . '/pinker/stable/platform/database.config.php', <<<'PHP'
<?php
/**
 * Pinoox Baker
 * @stable yes
 */

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => 'localhost',
            'port' => '13308',
        ],
    ],
];
PHP);
    file_put_contents($project . '/pinker/state/platform/app-router.config.php', <<<'PHP'
<?php
return [
    '__pinker_override__' => true,
    'schema' => 1,
    'data' => [
        '/' => 'com_pinoox_welcome',
    ],
    'remove' => [],
    'info' => [
        'updated_at' => 1,
    ],
];
PHP);

    $zip = new \ZipArchive();
    expect($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('index.php', '<?php // new');
    $zip->addFromString('storage/BUILD.json', json_encode([
        'type' => 'platform',
        'version_name' => '9.9.9',
        'version_code' => 9999,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->addFromString('.env', "SECRET=overwrite\n");
    $zip->addFromString('platform/app-router.config.php', "<?php\nreturn ['/' => 'com_pinoox_installer'];\n");
    $zip->addFromString('platform/pinoox.config.php', "<?php\nreturn ['version_name' => '9.9.9'];\n");
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

    $databaseStable = include $project . '/pinker/stable/platform/database.config.php';
    $routerState = include $project . '/pinker/state/platform/app-router.config.php';

    expect($result->success)->toBeTrue()
        ->and($result->apps)->toBe(['com_pinoox_manager'])
        ->and(file_get_contents($project . '/index.php'))->toContain('new')
        ->and(file_get_contents($project . '/.env'))->toContain('keep-me')
        ->and(file_get_contents($project . '/storage/keep.txt'))->toBe('runtime')
        ->and(file_get_contents($project . '/storage/BUILD.json'))->toContain('"type": "platform"')
        ->and(file_get_contents($project . '/platform/app-router.config.php'))->toContain('com_pinoox_welcome')
        ->and(file_get_contents($project . '/platform/pinoox.config.php'))->toContain('9.9.9')
        ->and($databaseStable['default'] ?? null)->toBe('mysql')
        ->and($databaseStable['connections']['mysql']['port'] ?? null)->toBe('13308')
        ->and($routerState['info']['updated_at'] ?? 0)->toBeGreaterThan(1)
        ->and(is_file($project . '/apps/com_user_shop/app.php'))->toBeTrue()
        ->and(is_file($project . '/apps/com_pinoox_manager/app.php'))->toBeTrue()
        ->and(file_get_contents($project . '/apps/com_pinoox_manager/pinker/keep.txt'))->toBe('app-pinker')
        ->and(is_dir($project . '/storage/.platform-update'))->toBeFalse();
});

it('seeds app-router from the archive when the host file is missing or empty', function () {
    $sandbox = testSandbox('platform-update/' . uniqid('seed-router_', true));
    $project = $sandbox . '/project';
    $zipPath = $sandbox . '/platform.zip';

    mkdir($project . '/storage', 0777, true);
    mkdir($project . '/platform', 0777, true);
    file_put_contents($project . '/index.php', '<?php // old');
    file_put_contents($project . '/platform/app-router.config.php', "<?php\nreturn [];\n");

    $zip = new \ZipArchive();
    expect($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('index.php', '<?php // new');
    $zip->addFromString('storage/BUILD.json', json_encode([
        'type' => 'platform',
        'version_name' => '9.9.9',
        'version_code' => 9999,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->addFromString('platform/app-router.config.php', "<?php\nreturn ['/' => 'com_pinoox_installer'];\n");
    $zip->addFromString('platform/pinoox.config.php', "<?php\nreturn ['version_name' => '9.9.9'];\n");
    $zip->close();

    $result = (new PlatformUpdater(AppEngine::___()))->update($zipPath, [
        'project_root' => $project,
        'force' => true,
        'skip_migrate' => true,
        'skip_patch' => true,
        'skip_lifecycle' => true,
        'skip_cache' => true,
    ]);

    $router = include $project . '/platform/app-router.config.php';
    $baked = include $project . '/pinker/bake/platform/app-router.config.php';

    expect($result->success)->toBeTrue()
        ->and($router['/'] ?? null)->toBe('com_pinoox_installer')
        ->and($baked['/'] ?? null)->toBe('com_pinoox_installer');
});
