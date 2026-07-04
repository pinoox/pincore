<?php

use Pinoox\Component\Package\Engine\AppEngine as PackageAppEngine;
use Pinoox\Support\SystemConfig;

beforeEach(function () {
    SystemConfig::clearCache();
    restoreSystemConfigTestEnv();
    deleteSystemConfigTestDirectory(systemConfigTestRoot());
});

afterEach(function () {
    SystemConfig::clearCache();
    restoreSystemConfigTestEnv();
    deleteSystemConfigTestDirectory(systemConfigTestRoot());
});

it('resolves configurable project paths from env values', function () {
    setSystemConfigTestEnv('PINOOX_PINKER_PATH', testFixturesProjectRelative('system_config/custom_pinker'));
    setSystemConfigTestEnv('PINOOX_STORAGE_PATH', testFixturesProjectRelative('system_config/custom_storage'));
    SystemConfig::clearCache();

    expect(SystemConfig::path('pinker'))->toBe(testFixtures('system_config/custom_pinker'))
        ->and(SystemConfig::path('storage'))->toBe(testFixtures('system_config/custom_storage'))
        ->and(SystemConfig::path('wizard_tmp'))->toBe(testFixtures('system_config/custom_pinker/wizard_tmp'));
});

it('loads deploy configs from project config with pincore stub fallback', function () {
    $projectConfig = systemConfigTestRoot() . '/deploy_config';
    $corePath = testCoreRoot();

    deleteSystemConfigTestDirectory($projectConfig);
    mkdir($projectConfig, 0755, true);
    file_put_contents(
        $projectConfig . '/app-router.config.php',
        "<?php\n\nreturn ['/' => 'com_test_project_router'];\n",
    );

    setSystemConfigTestEnv('PINOOX_PROJECT_CONFIG_PATH', testFixturesProjectRelative('system_config/deploy_config'));
    SystemConfig::clearCache();

    expect(SystemConfig::projectConfigPath())->toBe($projectConfig)
        ->and(SystemConfig::projectLayerConfigFile('app-router'))->toBe($projectConfig . '/app-router.config.php')
        ->and(SystemConfig::get('app-router', '/'))->toBe('com_test_project_router')
        ->and(SystemConfig::projectLayerConfigFile('domain'))->toBe($corePath . '/config/domain.config.php');

    deleteSystemConfigTestDirectory($projectConfig);
});

it('merges platform manifest onto pincore runtime defaults', function () {
    $projectConfig = systemConfigTestRoot() . '/pinoox_deploy';
    $corePath = testCoreRoot();
    $template = include $corePath . '/config/pinoox.config.php';

    deleteSystemConfigTestDirectory($projectConfig);
    mkdir($projectConfig, 0755, true);
    file_put_contents(
        $projectConfig . '/pinoox.config.php',
        "<?php\n\nreturn ['version_name' => '2.5.0', 'version_code' => 25, 'name' => 'Deploy Project', 'log' => ['level' => 'error']];\n",
    );

    setSystemConfigTestEnv('PINOOX_PROJECT_CONFIG_PATH', testFixturesProjectRelative('system_config/pinoox_deploy'));
    SystemConfig::clearCache();

    $kernel = include $corePath . '/config/pincore.config.php';

    expect(SystemConfig::get('pinoox', 'version_name'))->toBe('2.5.0')
        ->and(SystemConfig::get('pinoox', 'version_code'))->toBe(25)
        ->and(SystemConfig::get('pinoox', 'name'))->toBe('Deploy Project')
        ->and(SystemConfig::get('pinoox', 'log.level'))->toBe('error')
        ->and(SystemConfig::get('pinoox', 'lang'))->toBe($template['lang'])
        ->and(SystemConfig::get('pincore', 'version_name'))->toBe($kernel['version_name'])
        ->and(SystemConfig::get('pincore', 'version_code'))->toBe($kernel['version_code']);

    deleteSystemConfigTestDirectory($projectConfig);
});

it('loads runtime defaults from pincore when manifest only sets version', function () {
    $projectConfig = systemConfigTestRoot() . '/pinoox_manifest_only';
    $corePath = testCoreRoot();
    $template = include $corePath . '/config/pinoox.config.php';

    deleteSystemConfigTestDirectory($projectConfig);
    mkdir($projectConfig, 0755, true);
    file_put_contents(
        $projectConfig . '/pinoox.config.php',
        "<?php\n\nreturn ['version_name' => '2.5.0', 'version_code' => 25];\n",
    );

    setSystemConfigTestEnv('PINOOX_PROJECT_CONFIG_PATH', testFixturesProjectRelative('system_config/pinoox_manifest_only'));
    SystemConfig::clearCache();

    expect(SystemConfig::get('pinoox', 'version_name'))->toBe('2.5.0')
        ->and(SystemConfig::get('pinoox', 'version_code'))->toBe(25)
        ->and(SystemConfig::get('pinoox', 'lang'))->toBe($template['lang'])
        ->and(SystemConfig::get('pinoox', 'log.rotate'))->toBe($template['log']['rotate']);

    deleteSystemConfigTestDirectory($projectConfig);
});

it('uses top-level patch paths outside the database folders', function () {
    $corePath = testCoreRoot();

    expect(SystemConfig::path('system_patches'))->toBe($corePath . '/patches')
        ->and(SystemConfig::path('platform_patches'))->toBe($corePath . '/patches')
        ->and(SystemConfig::platformPath('migrations'))->toBe($corePath . '/database/migrations')
        ->and(SystemConfig::platformPath('patches'))->toBe($corePath . '/patches')
        ->and(SystemConfig::rawPath('app_patches'))->toBe('patches');
});

it('lets the app engine discover apps from a custom env path', function () {
    $appsPath = systemConfigTestRoot() . '/custom_apps';
    $appPath = $appsPath . '/com_test_custom';

    mkdir($appPath, 0755, true);
    file_put_contents($appPath . '/app.php', "<?php\n\nreturn ['package' => 'com_test_custom', 'enable' => true, 'name' => 'custom'];\n");

    setSystemConfigTestEnv('PINOOX_APPS_PATH', testFixturesProjectRelative('system_config/custom_apps'));
    setSystemConfigTestEnv('PINOOX_PINKER_PATH', testFixturesProjectRelative('system_config/custom_pinker'));
    SystemConfig::clearCache();

    $engine = new PackageAppEngine(
        SystemConfig::path('apps'),
        SystemConfig::rawPath('app_file', 'app.php'),
        SystemConfig::path('pinker'),
    );

    expect($engine->exists('com_test_custom'))->toBeTrue()
        ->and($engine->path('com_test_custom'))->toBe($appPath);
});

it('lets the portal app engine combine custom apps path with registry packages', function () {
    $appsPath = systemConfigTestRoot() . '/custom_apps';
    $folderApp = $appsPath . '/com_test_custom';
    $registryPackage = 'com_test_portal_registry';
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $registryPackage);

    mkdir($folderApp, 0755, true);
    file_put_contents($folderApp . '/app.php', "<?php\n\nreturn ['package' => 'com_test_custom', 'enable' => true, 'name' => 'custom'];\n");

    if (!is_dir($externalApp)) {
        mkdir($externalApp, 0777, true);
    }

    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$registryPackage}', 'enable' => true, 'name' => 'registry'];\n");

    $registry = \Pinoox\Support\AppRegistry::fromArray([
        'packages' => [
            $registryPackage => testFixturesProjectRelative('external_apps/' . $registryPackage),
        ],
    ], str_replace('\\', '/', testProjectRoot()));

    setSystemConfigTestEnv('PINOOX_APPS_PATH', testFixturesProjectRelative('system_config/custom_apps'));
    setSystemConfigTestEnv('PINOOX_PINKER_PATH', testFixturesProjectRelative('system_config/custom_pinker'));
    SystemConfig::clearCache();

    $engine = new PackageAppEngine(
        SystemConfig::path('apps'),
        SystemConfig::rawPath('app_file', 'app.php'),
        SystemConfig::path('pinker'),
        null,
        $registry,
    );

    expect($engine->exists('com_test_custom'))->toBeTrue()
        ->and($engine->exists($registryPackage))->toBeTrue()
        ->and($engine->path($registryPackage))->toBe($externalApp)
        ->and(array_keys($engine->all()))->toEqualCanonicalizing(['com_test_custom', $registryPackage]);
});

function systemConfigTestRoot(): string
{
    return str_replace('\\', '/', testFixtures('system_config'));
}

function setSystemConfigTestEnv(string $key, string $value): void
{
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function restoreSystemConfigTestEnv(): void
{
    foreach ([
        'APP_NAME',
        'APP_ENV',
        'APP_DEBUG',
        'APP_LOCALE',
        'APP_FALLBACK_LOCALE',
        'LOG_CHANNEL',
        'LOG_LEVEL',
        'CACHE_STORE',
        'CACHE_PREFIX',
        'CACHE_PATH',
        'SESSION_SAVE_PATH',
        'BCRYPT_ROUNDS',
        'FILESYSTEM_APPS_ROOT',
        'PINOOX_APPS_PATH',
        'PINOOX_PINKER_PATH',
        'PINOOX_STORAGE_PATH',
        'PINOOX_PROJECT_REGISTRY_PATH',
        'PINOOX_PROJECT_CONFIG_PATH',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    if (!\Pinoox\Tests\Support\TestRuntime::usesProjectPaths()) {
        \Pinoox\Tests\Support\TestRuntime::reapplyIsolatedRuntime(testProjectRoot());
    }
}

function deleteSystemConfigTestDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

it('supports Laravel-compatible env aliases for core runtime config', function () {
    setSystemConfigTestEnv('APP_NAME', 'PinLab');
    setSystemConfigTestEnv('APP_ENV', 'production');
    setSystemConfigTestEnv('APP_DEBUG', 'false');
    setSystemConfigTestEnv('APP_LOCALE', 'fa');
    setSystemConfigTestEnv('APP_FALLBACK_LOCALE', 'en');
    setSystemConfigTestEnv('LOG_CHANNEL', 'runtime');
    setSystemConfigTestEnv('LOG_LEVEL', 'warning');
    SystemConfig::clearCache();

    expect(SystemConfig::get('pinoox', 'name'))->toBe('PinLab')
        ->and(SystemConfig::get('pinoox', 'mode'))->toBe('production')
        ->and(SystemConfig::get('pinoox', 'debug'))->toBeFalse()
        ->and(SystemConfig::get('pinoox', 'lang'))->toBe('fa')
        ->and(SystemConfig::get('pinoox', 'lang_fallback'))->toBe('en')
        ->and(SystemConfig::get('pinoox', 'log.channel'))->toBe('runtime')
        ->and(SystemConfig::get('pinoox', 'log.level'))->toBe('warning');
});

it('loads framework configs from platform when present without merging core defaults', function () {
    $projectConfig = systemConfigTestRoot() . '/framework_override';
    $corePath = testCoreRoot();

    deleteSystemConfigTestDirectory($projectConfig);
    mkdir($projectConfig, 0755, true);
    file_put_contents(
        $projectConfig . '/database.config.php',
        "<?php\n\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n",
    );

    setSystemConfigTestEnv('PINOOX_PROJECT_CONFIG_PATH', testFixturesProjectRelative('system_config/framework_override'));
    SystemConfig::clearCache();

    expect(SystemConfig::resolveConfigFile('database'))->toBe($projectConfig . '/database.config.php')
        ->and(SystemConfig::get('database', 'default'))->toBe('sqlite')
        ->and(SystemConfig::get('database', 'connections.sqlite.database'))->toBe(':memory:')
        ->and(SystemConfig::resolveConfigFile('session'))->toBe($corePath . '/config/session.config.php');

    deleteSystemConfigTestDirectory($projectConfig);
});

it('loads cache session and security config from env aliases', function () {
    setSystemConfigTestEnv('CACHE_STORE', 'file');
    setSystemConfigTestEnv('CACHE_PREFIX', 'pin_cache');
    setSystemConfigTestEnv('CACHE_PATH', '~storage/custom-cache');
    setSystemConfigTestEnv('SESSION_SAVE_PATH', '~storage/custom-sessions');
    setSystemConfigTestEnv('BCRYPT_ROUNDS', '10');
    SystemConfig::clearCache();

    expect(SystemConfig::get('cache', 'default'))->toBe('file')
        ->and(SystemConfig::get('cache', 'prefix'))->toBe('pin_cache')
        ->and(SystemConfig::get('cache', 'stores.file.path'))->toBe('~storage/custom-cache')
        ->and(SystemConfig::get('session', 'files'))->toBe('~storage/custom-sessions')
        ->and(SystemConfig::get('security', 'hashing.bcrypt.rounds'))->toBe(10);
});

