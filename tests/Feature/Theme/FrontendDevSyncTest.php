<?php

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendDevSync;

const FRONTEND_DEV_SYNC_ENV_KEYS = ['VITE_HOT_FILE', 'VITE_DEV_PORT', 'VITE_DEV', 'VITE_DEV_SERVER', 'VITE_DEV_FORCE'];

function frontendDevSyncEnvSnapshot(): array
{
    $snapshot = [];

    foreach (FRONTEND_DEV_SYNC_ENV_KEYS as $key) {
        $snapshot[$key] = $_ENV[$key] ?? null;
    }

    return $snapshot;
}

function frontendDevSyncEnvRestore(array $snapshot): void
{
    foreach ($snapshot as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function frontendDevSyncThemeDir(): string
{
    $path = sys_get_temp_dir() . '/pinoox-fe-sync-' . uniqid('', true);
    mkdir($path, 0777, true);

    return $path;
}

function frontendDevSyncRemoveThemeDir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

beforeEach(function () {
    $GLOBALS['__frontendDevSyncEnvSnapshot'] = frontendDevSyncEnvSnapshot();
});

afterEach(function () {
    if (isset($GLOBALS['__frontendDevSyncThemePath'])) {
        frontendDevSyncRemoveThemeDir($GLOBALS['__frontendDevSyncThemePath']);
        unset($GLOBALS['__frontendDevSyncThemePath']);
    }

    if (isset($GLOBALS['__frontendDevSyncEnvSnapshot'])) {
        frontendDevSyncEnvRestore($GLOBALS['__frontendDevSyncEnvSnapshot']);
        unset($GLOBALS['__frontendDevSyncEnvSnapshot']);
    }
});

test('FrontendDevSync adds vite plugin dependency and seeds theme env', function () {
    $themePath = frontendDevSyncThemeDir();
    $GLOBALS['__frontendDevSyncThemePath'] = $themePath;

    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");
    file_put_contents($themePath . '/.env.example', "VITE_DEV_PORT=5199\n");
    file_put_contents($themePath . '/package.json', json_encode([
        'private' => true,
        'devDependencies' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    $config = FrontendConfig::forThemePath($themePath);
    $corePath = dirname(__DIR__, 3);

    $result = FrontendDevSync::sync($themePath, $config, $corePath, null, true);

    expect($result['vite_plugin'])->toBeTrue()
        ->and($result['vite_plugin_added'])->toBeTrue()
        ->and($result['env_seeded'])->toBeTrue()
        ->and($result['hot_path'])->toBe('dist/hot')
        ->and(is_file($themePath . '/vite.pinoox.mjs'))->toBeFalse()
        ->and(is_file($themePath . '/.env'))->toBeTrue()
        ->and(file_get_contents($themePath . '/package.json'))->toContain('@pinoox/vite-plugin');
});

test('FrontendConfig reads VITE_HOT_FILE and VITE_DEV_PORT from env', function () {
    $themePath = frontendDevSyncThemeDir();
    $GLOBALS['__frontendDevSyncThemePath'] = $themePath;

    file_put_contents($themePath . '/frontend.config.php', "<?php\n\nreturn ['stack' => 'vue'];\n");

    $_ENV['VITE_HOT_FILE'] = 'dist/custom/hot';
    $_SERVER['VITE_HOT_FILE'] = 'dist/custom/hot';
    $_ENV['VITE_DEV_PORT'] = '5199';
    $_SERVER['VITE_DEV_PORT'] = '5199';

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::hotRelativePath($config))->toBe('dist/custom/hot')
        ->and(FrontendConfig::devPort($config))->toBe(5199);
});
