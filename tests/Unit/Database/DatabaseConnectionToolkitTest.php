<?php

use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;

beforeEach(function () {
    deleteTestApp('com_test_cli_db');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteTestApp('com_test_cli_db');
    AppEngine::__rebuild();
});

it('builds platform-only app database blocks', function () {
    $block = DatabaseConnectionToolkit::buildAppDatabaseBlock([
        'use' => 'platform',
        'prefix' => 'shop_',
    ]);

    expect($block)->toBe(['use' => 'platform', 'prefix' => 'shop_']);
});

it('cleans up redundant platform database blocks', function () {
    expect(DatabaseConnectionToolkit::cleanupAppDatabaseBlock([
        'use' => 'platform',
        'prefix' => 'blog_',
        'table_prefix' => 'legacy_',
    ]))->toBe(['use' => 'platform', 'prefix' => 'blog_']);
});

it('preserves dedicated driver credentials in app database blocks', function () {
    $block = DatabaseConnectionToolkit::buildAppDatabaseBlock([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'shop',
        'username' => 'root',
        'password' => '',
    ]);

    expect($block['driver'])->toBe('mysql')
        ->and($block['database'])->toBe('shop');
});

it('describes fake app database mode as platform default', function () {
    writeTestApp('com_test_cli_db', []);

    $row = DatabaseConnectionToolkit::describeApp('com_test_cli_db', test: false);

    expect($row['package'])->toBe('com_test_cli_db')
        ->and($row['mode'])->toBe('platform default');
});

it('describes fake app with platform prefix mode', function () {
    writeTestApp('com_test_cli_db', [
        'database' => [
            'use' => 'platform',
            'prefix' => 'cli_',
        ],
    ]);

    $row = DatabaseConnectionToolkit::describeApp('com_test_cli_db', test: false);

    expect($row['mode'])->toBe('platform + prefix')
        ->and($row['logical_prefix'])->toBe('cli_');
});

it('saves and reads app database prefix via pinker', function () {
    writeTestApp('com_test_cli_db', []);

    expect(DatabaseConnectionToolkit::setAppPrefix('com_test_cli_db', 'cli_test_'))->toBeTrue();

    $config = AppTestKit::inApp('com_test_cli_db', static fn () => AppEngine::config('com_test_cli_db')->get('database'));

    expect($config)->toBeArray()
        ->and($config['prefix'] ?? null)->toBe('cli_test_');
});
