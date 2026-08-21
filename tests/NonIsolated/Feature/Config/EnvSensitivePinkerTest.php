<?php

uses()->group('non-isolated');

use App\com_pinoox_installer\Component\DatabaseCredentialsSync;
use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Component\Store\Baker\EnvSensitiveConfig;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\Config;
use Pinoox\Portal\Pinker;
use Pinoox\Support\SystemConfig;

it('writes a simple @stable database file under pinker/stable', function () {
    AppTestKit::boot();

    $stablePath = SystemConfig::pinkerStableConfigPath('database');
    $stableBackup = is_file($stablePath) ? file_get_contents($stablePath) : null;

    try {
        Config::name('~pinoox')->set('mode', 'production');
        SystemConfig::clearCache();

        expect(DatabaseCredentialsSync::persist([
            'driver' => 'mysql',
            'host' => 'pinker-host',
            'port' => '3306',
            'database' => 'pin',
            'username' => 'root',
            'password' => 'secret',
            'prefix' => 'pinx_',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_bin',
            'strict' => true,
            'engine' => null,
            'timezone' => '+03:30',
        ], 'mysql'))->toBeTrue();

        $raw = file_get_contents($stablePath);
        $stable = include $stablePath;

        expect($raw)->toContain('@stable yes')
            ->and($stable['default'] ?? null)->toBe('mysql')
            ->and($stable['connections']['mysql']['host'] ?? null)->toBe('pinker-host')
            ->and($stable['__pinker_override__'] ?? null)->toBeNull();
    } finally {
        if ($stableBackup !== null) {
            file_put_contents($stablePath, $stableBackup);
        } elseif (is_file($stablePath)) {
            unlink($stablePath);
        }
    }
});

it('uses env values instead of pinker/stable when the env key is defined', function () {
    AppTestKit::boot();

    $mainFile = SystemConfig::configPath('database.config.php');
    $bakedFile = SystemConfig::pinkerConfigPath('database.config.php');
    $stablePath = SystemConfig::pinkerStableConfigPath('database');
    $stableBackup = is_file($stablePath) ? file_get_contents($stablePath) : null;

    putenv('APP_ENV=' . RuntimeMode::DEVELOPMENT);
    $_ENV['APP_ENV'] = RuntimeMode::DEVELOPMENT;
    $_SERVER['APP_ENV'] = RuntimeMode::DEVELOPMENT;
    putenv('DB_HOST=env-host');
    $_ENV['DB_HOST'] = 'env-host';
    $_SERVER['DB_HOST'] = 'env-host';

    try {
        Config::name('~pinoox')->set('mode', RuntimeMode::DEVELOPMENT);
        SystemConfig::clearCache();

        expect(DatabaseCredentialsSync::persist([
            'driver' => 'mysql',
            'host' => 'pinker-host',
            'port' => '3306',
            'database' => 'pin',
            'username' => 'root',
            'password' => 'secret',
            'prefix' => 'pinx_',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_bin',
            'strict' => true,
            'engine' => null,
            'timezone' => '+03:30',
        ], 'mysql'))->toBeTrue();

        SystemConfig::clearCache();

        $picked = Pinker::create($mainFile, $bakedFile)->pickup();
        $connections = $picked['connections'] ?? [];

        expect($connections['mysql']['host'] ?? null)->toBe('env-host');
    } finally {
        putenv('DB_HOST');
        unset($_ENV['DB_HOST'], $_SERVER['DB_HOST']);
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        if ($stableBackup !== null) {
            file_put_contents($stablePath, $stableBackup);
        } elseif (is_file($stablePath)) {
            unlink($stablePath);
        }

        SystemConfig::clearCache();
    }
});

it('falls back to pinker/stable when the mapped env key is not defined', function () {
    AppTestKit::boot();

    $mainFile = SystemConfig::configPath('database.config.php');
    $bakedFile = SystemConfig::pinkerConfigPath('database.config.php');
    $stablePath = SystemConfig::pinkerStableConfigPath('database');
    $stableBackup = is_file($stablePath) ? file_get_contents($stablePath) : null;

    putenv('APP_ENV=' . RuntimeMode::PRODUCTION);
    $_ENV['APP_ENV'] = RuntimeMode::PRODUCTION;
    $_SERVER['APP_ENV'] = RuntimeMode::PRODUCTION;

    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CONNECTION'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        Config::name('~pinoox')->set('mode', RuntimeMode::PRODUCTION);
        SystemConfig::clearCache();

        expect(DatabaseCredentialsSync::persist([
            'driver' => 'mysql',
            'host' => 'pinker-host',
            'port' => '3306',
            'database' => 'pin',
            'username' => 'root',
            'password' => 'secret',
            'prefix' => 'pinx_',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_bin',
            'strict' => true,
            'engine' => null,
            'timezone' => '+03:30',
        ], 'mysql'))->toBeTrue();

        SystemConfig::clearCache();

        $picked = Pinker::create($mainFile, $bakedFile)->pickup();

        expect($picked['connections']['mysql']['host'] ?? null)->toBe('pinker-host');
    } finally {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        if ($stableBackup !== null) {
            file_put_contents($stablePath, $stableBackup);
        } elseif (is_file($stablePath)) {
            unlink($stablePath);
        }

        SystemConfig::clearCache();
    }
});

it('maps database pinker paths to env keys', function () {
    $mainFile = SystemConfig::configPath('database.config.php');

    expect(EnvSensitiveConfig::envKeyForConfigPath($mainFile, 'default'))->toBe('DB_CONNECTION')
        ->and(EnvSensitiveConfig::envKeyForConfigPath($mainFile, 'connections.mysql.host'))->toBe('DB_HOST')
        ->and(EnvSensitiveConfig::envKeyForConfigPath($mainFile, 'connections.mariadb.database'))->toBe('DB_DATABASE');
});
