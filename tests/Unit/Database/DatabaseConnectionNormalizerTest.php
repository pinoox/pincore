<?php

use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Component\Database\DatabaseConnectionNormalizer;

it('resolves driver name from input aliases', function () {
    expect(DatabaseConnectionNormalizer::driverName(['driver' => 'pgsql']))->toBe('pgsql')
        ->and(DatabaseConnectionNormalizer::driverName(['connection' => 'mariadb']))->toBe('mariadb')
        ->and(DatabaseConnectionNormalizer::driverName(['driver' => 'unknown'], 'sqlite'))->toBe('sqlite');
});

it('returns default ports per driver', function () {
    expect(DatabaseConnectionNormalizer::defaultPort('mysql'))->toBe('3306')
        ->and(DatabaseConnectionNormalizer::defaultPort('pgsql'))->toBe('5432')
        ->and(DatabaseConnectionNormalizer::defaultPort('sqlsrv'))->toBe('1433');
});

it('normalizes mysql and sqlite connection configs', function () {
    $mysql = DatabaseConnectionNormalizer::normalize([
        'driver' => 'mysql',
        'host' => 'db.local',
        'database' => 'app',
        'username' => 'app',
        'password' => 'secret',
        'prefix' => 'app_',
    ]);

    expect($mysql['driver'])->toBe('mysql')
        ->and($mysql['host'])->toBe('db.local')
        ->and($mysql['database'])->toBe('app')
        ->and($mysql['prefix'])->toBe('app_');

    $sqlite = DatabaseConnectionNormalizer::normalize(['driver' => 'sqlite']);

    expect($sqlite['driver'])->toBe('sqlite')
        ->and($sqlite['database'])->toBe(':memory:');
});

it('tests sqlite memory connections when pdo sqlite is available', function () {
    if (!extension_loaded('pdo_sqlite')) {
        expect(true)->toBeTrue();

        return;
    }

    $ok = DatabaseConnectionNormalizer::test([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect($ok)->toBeTrue();
});

it('reports failed test for empty database name', function () {
    expect(DatabaseConnectionNormalizer::test([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => '',
    ]))->toBeFalse();
});

it('lists installable drivers with extension status', function () {
    expect(DatabaseConnectionNormalizer::INSTALLABLE_DRIVERS)->toContain('sqlite')
        ->and(DatabaseConnectionNormalizer::extensionStatus('sqlite'))->toHaveKeys(['available', 'extension']);
});
