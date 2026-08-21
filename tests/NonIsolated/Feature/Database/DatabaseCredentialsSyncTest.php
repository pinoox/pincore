<?php

uses()->group('non-isolated');

use App\com_pinoox_installer\Component\DatabaseCredentialsSync;
use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Component\Store\Baker\EnvSensitiveConfig;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\Config;
use Pinoox\Portal\Pinker;
use Pinoox\Support\SystemConfig;

it('stores mysql connection path for pinker persistence', function () {
    expect(EnvSensitiveConfig::storedProfiles())->toContain('connections.mysql');
});

it('normalizes legacy mode profiles to connections', function () {
    $normalized = DatabaseConfig::normalize([
        'default' => 'production',
        'production' => ['driver' => 'mysql', 'host' => 'prod.db', 'database' => 'site'],
        'test' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);

    expect($normalized['default'])->toBe('mysql')
        ->and($normalized['connections']['mysql']['host'])->toBe('prod.db')
        ->and($normalized['connections']['sqlite']['driver'])->toBe('sqlite');
});

it('merges legacy pinker profile keys beside connections layout', function () {
    $normalized = DatabaseConfig::normalize([
        'default' => 'mysql',
        'connections' => [
            'mysql' => ['driver' => 'mysql', 'database' => 'pinoox'],
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ],
        'production' => ['host' => 'localhost', 'database' => 'pin'],
    ]);

    expect($normalized['connections']['mysql']['database'])->toBe('pin')
        ->and($normalized['connections']['mysql']['host'])->toBe('localhost');
});

it('throws when DB_CONNECTION is unknown', function () {
    putenv('DB_CONNECTION=mysqltest');
    $_ENV['DB_CONNECTION'] = 'mysqltest';
    $_SERVER['DB_CONNECTION'] = 'mysqltest';
    SystemConfig::clearCache();

    expect(DatabaseConfig::requestedConnectionName())->toBe('mysqltest')
        ->and(fn () => DatabaseConfig::connectionName())
        ->toThrow(\InvalidArgumentException::class, 'mysqltest');

    putenv('DB_CONNECTION');
    unset($_ENV['DB_CONNECTION'], $_SERVER['DB_CONNECTION']);
    SystemConfig::clearCache();
});

it('syncs installer database credentials to pinker/stable', function () {
    AppTestKit::boot();

    $stablePath = SystemConfig::pinkerStableConfigPath('database');
    $stableBackup = is_file($stablePath) ? file_get_contents($stablePath) : null;

    try {
        putenv('APP_ENV=' . RuntimeMode::PRODUCTION);
        $_ENV['APP_ENV'] = RuntimeMode::PRODUCTION;
        $_SERVER['APP_ENV'] = RuntimeMode::PRODUCTION;

        Config::name('~pinoox')->set('mode', RuntimeMode::PRODUCTION);
        SystemConfig::clearCache();

        expect(DatabaseCredentialsSync::persist([
            'driver' => 'mysql',
            'host' => 'localhost',
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

        expect(is_file($stablePath))->toBeTrue();

        $stable = include $stablePath;

        expect($stable['default'] ?? null)->toBe('mysql')
            ->and($stable['connections']['mysql']['database'] ?? null)->toBe('pin')
            ->and($stable['connections']['mysql']['host'] ?? null)->toBe('localhost')
            ->and($stable['__pinker_override__'] ?? null)->toBeNull();

        SystemConfig::clearCache();

        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CONNECTION'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $picked = Pinker::create(
            SystemConfig::configPath('database.config.php'),
            SystemConfig::pinkerConfigPath('database.config.php'),
        )->pickup();

        $normalized = DatabaseConfig::normalize($picked);

        expect($normalized['default'] ?? null)->toBe('mysql')
            ->and($normalized['connections']['mysql']['database'] ?? null)->toBe('pin')
            ->and($normalized['connections']['mysql']['host'] ?? null)->toBe('localhost');
    } finally {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        if ($stableBackup !== null) {
            file_put_contents($stablePath, $stableBackup);
        } elseif (is_file($stablePath)) {
            unlink($stablePath);
        }
    }
});

it('syncs mariadb installer credentials and default connection to pinker/stable', function () {
    AppTestKit::boot();

    $stablePath = SystemConfig::pinkerStableConfigPath('database');
    $stableBackup = is_file($stablePath) ? file_get_contents($stablePath) : null;

    try {
        putenv('APP_ENV=' . RuntimeMode::PRODUCTION);
        $_ENV['APP_ENV'] = RuntimeMode::PRODUCTION;
        $_SERVER['APP_ENV'] = RuntimeMode::PRODUCTION;

        Config::name('~pinoox')->set('mode', RuntimeMode::PRODUCTION);
        SystemConfig::clearCache();

        expect(DatabaseCredentialsSync::persist([
            'driver' => 'mysql',
            'host' => 'db.local',
            'port' => '3307',
            'database' => 'maria_pin',
            'username' => 'maria',
            'password' => 'secret',
            'prefix' => 'pinx_',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
            'engine' => 'InnoDB',
            'timezone' => '+03:30',
        ], 'mariadb'))->toBeTrue();

        $stable = include $stablePath;

        expect($stable['default'] ?? null)->toBe('mariadb')
            ->and($stable['connections']['mariadb']['database'] ?? null)->toBe('maria_pin')
            ->and($stable['connections']['mariadb']['host'] ?? null)->toBe('db.local')
            ->and($stable['connections']['mariadb']['driver'] ?? null)->toBe('mariadb');

        SystemConfig::clearCache();

        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CONNECTION'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $picked = Pinker::create(
            SystemConfig::configPath('database.config.php'),
            SystemConfig::pinkerConfigPath('database.config.php'),
        )->pickup();

        $normalized = DatabaseConfig::normalize($picked);

        expect($normalized['default'] ?? null)->toBe('mariadb')
            ->and($normalized['connections']['mariadb']['database'] ?? null)->toBe('maria_pin')
            ->and($normalized['connections']['mariadb']['host'] ?? null)->toBe('db.local')
            ->and($normalized['connections']['mariadb']['driver'] ?? null)->toBe('mariadb');
    } finally {
        putenv('APP_ENV');
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        if ($stableBackup !== null) {
            file_put_contents($stablePath, $stableBackup);
        } elseif (is_file($stablePath)) {
            unlink($stablePath);
        }
    }
});
