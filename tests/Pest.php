<?php

use Pinoox\Tests\Support\TestRuntime;

/*
| Domain folders under pincore/tests/Feature/ — see pincore/tests/README.md
| Run: php vendor/bin/pest --testsuite=Server
*/
uses(Pinoox\Tests\TestCase::class)
    ->beforeEach(function () {
        cleanupTestArtifacts();
    })
    ->afterEach(function () {
        cleanupTestArtifacts();
    })
    ->in('Feature', 'Unit');

uses(Pinoox\Tests\TestCase::class)
    ->beforeEach(function () {
        if (!TestRuntime::usesProjectPaths()) {
            TestRuntime::enableProjectAppsRegistry(testProjectRoot());
        }

        cleanupTestArtifacts();
    })
    ->afterEach(function () {
        cleanupTestArtifacts();
    })
    ->afterAll(function () {
        if (!TestRuntime::usesProjectPaths()) {
            TestRuntime::disableProjectAppsRegistry(testProjectRoot());
        }
    })
    ->group('non-isolated')
    ->in('NonIsolated');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * @param callable(): void $callback
 */
function withViteHmrEnv(?string $value, callable $callback): void
{
    $key = \Pinoox\Component\Template\Frontend\FrontendConfig::VITE_HMR_ENV;
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
