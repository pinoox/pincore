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
