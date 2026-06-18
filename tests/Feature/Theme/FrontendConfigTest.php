<?php

use Pinoox\Component\Server\WebServerFixCache;
use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendWebServerFixSync;

test('FrontendConfig omits manifest and entry defaults for twig stack', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-twig-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'twig'];\n");

    $config = FrontendConfig::forThemePath($themePath);

    expect($config['stack'])->toBe('twig')
        ->and(FrontendConfig::manifestRelativePath($config))->toBeNull()
        ->and(FrontendConfig::usesViteAssets($config))->toBeFalse()
        ->and($config)->not->toHaveKey('entry');

    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig resolves vite manifest path for vue stack', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-vue-manifest-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::usesViteAssets($config))->toBeTrue()
        ->and(FrontendConfig::manifestRelativePath($config))->toBe(FrontendConfig::VITE_MANIFEST)
        ->and($config['entry'])->toBe('src/main.js');

    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig loadViteManifest ignores webpack mix-manifest files', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-webpack-skip-' . uniqid();
    mkdir($themePath . '/dist', 0777, true);
    file_put_contents($themePath . '/dist/mix-manifest.json', json_encode(['/dist/pinoox.js' => '/dist/pinoox.js']));
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::loadViteManifest($themePath, $config))->toBe([]);

    @unlink($themePath . '/dist/mix-manifest.json');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

test('FrontendConfig recommendations prefer vite_tags for vite stacks', function () {
    $config = FrontendConfig::normalize(['stack' => 'vue'], '/tmp/theme');

    $hints = FrontendConfig::recommendations($config, 'com_acme_shop', 'panel');

    expect($hints['twig'])->toBe("{{ vite_tags('src/main.js')|raw }}")
        ->and($hints['assets_hint'])->toContain('pinoox_bootstrap')
        ->and($hints['next_steps'])->toHaveCount(2)
        ->and($hints['next_steps'][0])->toContain('com_acme_shop')
        ->and($hints['next_steps'][0])->toContain('--theme=panel');
});

test('FrontendConfig defaultStackForNewTheme is vue', function () {
    expect(FrontendConfig::defaultStackForNewTheme())->toBe('vue');
});

test('FrontendWebServerFixSync registers vite chunk paths and skips twig themes', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-fe-sync-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vite'];\n");
    file_put_contents($themePath . '/dist/.vite/manifest.json', json_encode([
        'src/main.js' => [
            'file' => 'assets/main-abc.js',
            'css' => ['assets/main-abc.css'],
        ],
    ]));

    $config = FrontendConfig::forThemePath($themePath);
    $package = 'com_test_fe_sync';

    FrontendWebServerFixSync::syncFromThemeConfig($package, $themePath, $config);

    $paths = array_column(WebServerFixCache::load($package), 'relative');

    expect($paths)->toContain('/dist/assets/main-abc.js', '/dist/assets/main-abc.css');

    FrontendWebServerFixSync::syncFromThemeConfig($package, $themePath, ['stack' => 'twig']);

    @unlink($themePath . '/dist/.vite/manifest.json');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});
