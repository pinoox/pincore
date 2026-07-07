<?php

use Pinoox\Component\Server\WebServerFixCache;
use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendWebServerFixSync;

/**
 * @param callable(): void $callback
 */
function withViteHmrEnv(?string $value, callable $callback): void
{
    $key = FrontendConfig::VITE_HMR_ENV;
    $previousEnv = $_ENV[$key] ?? null;
    $previousServer = $_SERVER[$key] ?? null;
    $previousGetenv = getenv($key);

    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    } else {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $callback();
    } finally {
        if ($previousGetenv === false) {
            putenv($key);
        } else {
            putenv($key . '=' . ($previousGetenv ?: ''));
        }

        if ($previousEnv === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $previousEnv;
        }

        if ($previousServer === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $previousServer;
        }
    }
}

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
        ->and($hints['next_steps'])->toHaveCount(3)
        ->and($hints['next_steps'][0])->toContain('com_acme_shop')
        ->and($hints['next_steps'][0])->toContain('--theme=panel');
});

test('FrontendConfig defaultStackForNewTheme is vue', function () {
    expect(FrontendConfig::defaultStackForNewTheme())->toBe('vue');
});

test('FrontendConfig syncs manifest and hot paths with build.outDir', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-build-outdir-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'build' => ['outDir' => 'public/build']];\n");

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::buildOutDir($config, $themePath))->toBe('public/build')
        ->and(FrontendConfig::manifestRelativePath($config, $themePath))->toBe('public/build/.vite/manifest.json')
        ->and(FrontendConfig::hotRelativePath($config, $themePath))->toBe('public/build/hot')
        ->and(FrontendConfig::outDirFromManifestPath('public/build/.vite/manifest.json'))->toBe('public/build');

    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig resolves custom dev.hot path', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-hot-path-' . uniqid();
    mkdir($themePath . '/dist/custom', 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['hot' => 'dist/custom/hot']];\n");
    file_put_contents($themePath . '/dist/custom/hot', 'http://127.0.0.1:5199');

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv('1', function () use ($themePath, $config): void {
        expect(FrontendConfig::hotRelativePath($config))->toBe('dist/custom/hot')
            ->and(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBe('http://127.0.0.1:5199');
    });

    @unlink($themePath . '/dist/custom/hot');
    @rmdir($themePath . '/dist/custom');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig prefer_manifest skips dev url when manifest exists', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-prefer-manifest-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/.vite/manifest.json', '{}');
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['enabled' => true, 'url' => 'http://127.0.0.1:5173', 'prefer_manifest' => true]];\n");

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv(null, function () use ($themePath, $config): void {
        expect(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBeNull();
    });

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig allocates free port when dev.port is omitted', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-auto-port-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");

    $port = FrontendConfig::allocateDevPort($themePath);

    expect($port)->toBeGreaterThanOrEqual(5173)
        ->and(FrontendConfig::hasExplicitDevPort($themePath))->toBeFalse();

    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig resolveRuntimeDevPort reads fe dev cache file', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-dev-port-cache-' . uniqid();
    mkdir($themePath . '/dist', 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");
    file_put_contents($themePath . '/dist/.vite-dev-port', '5188');

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv('1', function () use ($themePath, $config): void {
        expect(FrontendConfig::resolveRuntimeDevPort($themePath, $config))->toBe(5188)
            ->and(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBe('http://127.0.0.1:5188');
    });

    @unlink($themePath . '/dist/.vite-dev-port');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig uses dev port when manifest is missing', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-no-manifest-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['port' => 5174]];\n");

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv('1', function () use ($themePath, $config): void {
        expect(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBe('http://127.0.0.1:5174');
    });

    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig prefers Vite over stale manifest by default in dev', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-dev-over-manifest-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/.vite/manifest.json', '{}');
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['enabled' => true, 'port' => 5174]];\n");

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv('1', function () use ($themePath, $config): void {
        expect(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBe('http://127.0.0.1:5174');
    });

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig serve mode ignores hot file and uses manifest', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-serve-manifest-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/hot', 'http://127.0.0.1:5173');
    file_put_contents($themePath . '/dist/.vite/manifest.json', '{}');
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['enabled' => true, 'url' => 'http://127.0.0.1:5173']];\n");

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv('0', function () use ($themePath, $config): void {
        expect(FrontendConfig::viteHmrMode())->toBeFalse()
            ->and(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBeNull();
    });

    @unlink($themePath . '/dist/hot');
    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig explicit HMR mode uses dev url when manifest exists', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-explicit-hmr-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/.vite/manifest.json', '{}');
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['port' => 5174]];\n");

    $config = FrontendConfig::forThemePath($themePath);

    withViteHmrEnv('1', function () use ($themePath, $config): void {
        expect(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBe('http://127.0.0.1:5174');
    });

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig reads PINOOX_VITE_HMR from getenv when absent in _ENV', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-getenv-hmr-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/.vite/manifest.json', '{}');
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['port' => 5173]];\n");

    $config = FrontendConfig::forThemePath($themePath);
    $key = FrontendConfig::VITE_HMR_ENV;

    putenv($key . '=1');

    try {
        unset($_ENV[$key], $_SERVER[$key]);

        expect(FrontendConfig::viteHmrMode())->toBeTrue()
            ->and(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBe('http://127.0.0.1:5173');
    } finally {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
});

test('FrontendConfig ignores hot file and dev url when runtime is production', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-prod-hot-' . uniqid();
    mkdir($themePath . '/dist', 0777, true);
    file_put_contents($themePath . '/dist/hot', 'http://127.0.0.1:5173');
    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue', 'dev' => ['enabled' => true, 'url' => 'http://127.0.0.1:5173']];\n");

    $previous = $_ENV['APP_ENV'] ?? null;
    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';
    putenv('APP_ENV=production');
    \Pinoox\Support\SystemConfig::clearCache();

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::viteDevAllowed())->toBeFalse()
        ->and(FrontendConfig::isDevEnabled($config))->toBeFalse()
        ->and(FrontendConfig::resolveDevServerUrl($themePath, $config))->toBeNull();

    if ($previous === null) {
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        putenv('APP_ENV');
    } else {
        $_ENV['APP_ENV'] = $previous;
        $_SERVER['APP_ENV'] = $previous;
        putenv('APP_ENV=' . $previous);
    }

    \Pinoox\Support\SystemConfig::clearCache();

    @unlink($themePath . '/dist/hot');
    @rmdir($themePath . '/dist');
    @unlink($themePath . '/frontend.config.php');
    @rmdir($themePath);
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
