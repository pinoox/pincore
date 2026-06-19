<?php

uses(Tests\TestCase::class)->in('Feature', 'Unit', 'NonIsolated');

/*
| Domain folders under pincore/tests/Feature/ — see pincore/tests/README.md
| Run: php vendor/bin/pest --testsuite=Server
*/
beforeEach(function () {
    cleanupTestArtifacts();
});
afterEach(function () {
    cleanupTestArtifacts();
});
expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

