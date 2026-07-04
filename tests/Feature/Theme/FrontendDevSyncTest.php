<?php

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendDevSync;

test('FrontendDevSync copies hot plugin and seeds theme env', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-fe-sync-dev-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");
    file_put_contents($themePath . '/.env.example', "VITE_DEV_PORT=5199\n");

    $config = FrontendConfig::forThemePath($themePath);
    $corePath = dirname(__DIR__, 3);

    $result = FrontendDevSync::sync($themePath, $config, $corePath);

    expect($result['pinoox_bundle'])->toBeTrue()
        ->and($result['env_seeded'])->toBeTrue()
        ->and($result['hot_path'])->toBe('dist/hot')
        ->and(is_file($themePath . '/vite.pinoox.mjs'))->toBeTrue()
        ->and(is_file($themePath . '/.env'))->toBeTrue();

    @unlink($themePath . '/vite.pinoox.mjs');
    @unlink($themePath . '/.env');
    @unlink($themePath . '/.env.example');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig reads VITE_HOT_FILE and VITE_DEV_PORT from env', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-fe-env-hot-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");

    putenv('VITE_HOT_FILE=dist/custom/hot');
    putenv('VITE_DEV_PORT=5199');

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::hotRelativePath($config))->toBe('dist/custom/hot')
        ->and(FrontendConfig::devPort($config))->toBe(5199);

    putenv('VITE_HOT_FILE');
    putenv('VITE_DEV_PORT');

    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});
