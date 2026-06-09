<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Config as ConfigPortal;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
});

it('declares the Config portal contract', function () {
    expectPortalContract(ConfigPortal::class);
});

it('loads system app resources through portal aliases', function () {
    Loader::setBasePath(testProjectRoot());

    foreach (['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    \Pinoox\Support\SystemConfig::clearCache();

    expect(ConfigPortal::name('~system/database')->get('connections.mysql.driver'))->toBe('mysql');
})->group('non-isolated');

