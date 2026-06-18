<?php

/**
 * Resolve platform / pincore test paths before or after Composer install.
 *
 * Used by pincore/tests/bootstrap.php and app tests/bootstrap.php stubs.
 */

function pinoox_platform_root_from_core_tests(string $coreTestsDir): string
{
    $coreTestsDir = rtrim(str_replace('\\', '/', $coreTestsDir), '/');

    foreach ([4, 3, 2, 1] as $depth) {
        $candidate = dirname($coreTestsDir, $depth);

        if (is_file($candidate . '/platform/launcher/core-path.php')) {
            return $candidate;
        }
    }

    foreach ([1, 2, 3, 4] as $depth) {
        $candidate = dirname($coreTestsDir, $depth);

        if (is_file($candidate . '/launcher/core-path.php')) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'Could not locate Pinoox project root (launcher/core-path.php) from core tests path: ' . $coreTestsDir,
    );
}

function pinoox_core_tests_dir(string $projectRoot): string
{
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

    foreach ([
        $projectRoot . '/vendor/pinoox/pincore/tests',
        $projectRoot . '/pincore/tests',
        $projectRoot . '/tests',
    ] as $path) {
        if (is_file($path . '/bootstrap.php')) {
            return $path;
        }
    }

    throw new RuntimeException(
        'Pinoox core tests bootstrap was not found under vendor/pinoox/pincore/tests, pincore/tests, or tests/.',
    );
}

function pinoox_core_phpunit_config(string $projectRoot): string
{
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

    foreach ([
        $projectRoot . '/vendor/pinoox/pincore/phpunit.xml',
        $projectRoot . '/pincore/phpunit.xml',
        $projectRoot . '/phpunit.xml',
    ] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    throw new RuntimeException(
        'Pinoox phpunit.xml was not found under vendor/pinoox/pincore, pincore, or project root.',
    );
}
