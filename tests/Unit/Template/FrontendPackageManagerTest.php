<?php

use Pinoox\Component\Template\Frontend\FrontendPackageManager;

beforeEach(function () {
    putenv(FrontendPackageManager::ENV);
    unset($_ENV[FrontendPackageManager::ENV], $_SERVER[FrontendPackageManager::ENV]);
});

afterEach(function () {
    putenv(FrontendPackageManager::ENV);
    unset($_ENV[FrontendPackageManager::ENV], $_SERVER[FrontendPackageManager::ENV]);
});

test('FrontendPackageManager defaults to npm', function () {
    expect(FrontendPackageManager::name())->toBe('npm')
        ->and(FrontendPackageManager::binary())->toBe(PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm');
});

test('FrontendPackageManager reads PINOOX_JS_PACKAGE_MANAGER from env', function () {
    putenv(FrontendPackageManager::ENV . '=bun');

    expect(FrontendPackageManager::name())->toBe('bun')
        ->and(FrontendPackageManager::binary())->toBe(PHP_OS_FAMILY === 'Windows' ? 'bun.exe' : 'bun')
        ->and(FrontendPackageManager::runScriptCommand('dev'))->toBe(['run', 'dev']);
});

test('FrontendPackageManager detects bun from lockfiles', function () {
    $dir = sys_get_temp_dir() . '/pinoox-pm-' . uniqid('', true);
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/bun.lock', '{}');

    try {
        expect(FrontendPackageManager::name($dir))->toBe('bun')
            ->and(FrontendPackageManager::installCommand($dir))->toBe(['install', '--frozen-lockfile']);
    } finally {
        @unlink($dir . '/bun.lock');
        @rmdir($dir);
    }
});

test('FrontendPackageManager bun install without lockfile', function () {
    putenv(FrontendPackageManager::ENV . '=bun');
    $dir = sys_get_temp_dir() . '/pinoox-pm-' . uniqid('', true);
    mkdir($dir, 0777, true);

    try {
        expect(FrontendPackageManager::installCommand($dir))->toBe(['install']);
    } finally {
        @rmdir($dir);
    }
});
