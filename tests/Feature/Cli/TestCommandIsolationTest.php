<?php

use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\Test\TestCommand;

it('primes the test command with isolated runtime paths', function () {
    $keys = [
        'PINOOX_TEST_RUNTIME_PATH',
        'PINOOX_APPS_PATH',
        'PINOOX_PINKER_PATH',
        'PINOOX_STORAGE_PATH',
        'PINOOX_PROJECT_REGISTRY_PATH',
        'PINOOX_TEST_USE_PROJECT_PATHS',
    ];

    $previous = testCommandIsolationEnvSnapshot($keys);

    try {
        foreach ($keys as $key) {
            testCommandIsolationUnsetEnv($key);
        }

        putenv('PINOOX_PINKER_PATH=pinker');
        $_ENV['PINOOX_PINKER_PATH'] = 'pinker';
        $_SERVER['PINOOX_PINKER_PATH'] = 'pinker';
        SystemConfig::clearCache();

        $command = new TestCommand();
        $method = new ReflectionMethod($command, 'primeIsolatedRuntimePaths');
        $method->setAccessible(true);
        $method->invoke($command);

        expect(testCommandIsolationEnv('PINOOX_TEST_RUNTIME_PATH'))->toBe(testFixturesProjectRelative('runtime'))
            ->and(testCommandIsolationEnv('PINOOX_APPS_PATH'))->toBe(testFixturesProjectRelative('runtime/apps'))
            ->and(testCommandIsolationEnv('PINOOX_PINKER_PATH'))->toBe(testFixturesProjectRelative('runtime/pinker'))
            ->and(testCommandIsolationEnv('PINOOX_STORAGE_PATH'))->toBe(testFixturesProjectRelative('runtime/storage'))
            ->and(SystemConfig::path('pinker'))->toBe(testRuntimePinker())
            ->and(SystemConfig::path('pinker'))->not->toBe(testProjectRoot() . '/pinker');
    } finally {
        testCommandIsolationRestoreEnv($previous);
        SystemConfig::clearCache();
    }
});

/**
 * @param list<string> $keys
 * @return array<string, string|false>
 */
function testCommandIsolationEnvSnapshot(array $keys): array
{
    $snapshot = [];

    foreach ($keys as $key) {
        $snapshot[$key] = getenv($key);
    }

    return $snapshot;
}

function testCommandIsolationEnv(string $key): string
{
    $value = getenv($key);

    return is_string($value) ? $value : '';
}

function testCommandIsolationUnsetEnv(string $key): void
{
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

/**
 * @param array<string, string|false> $snapshot
 */
function testCommandIsolationRestoreEnv(array $snapshot): void
{
    foreach ($snapshot as $key => $value) {
        if ($value === false) {
            testCommandIsolationUnsetEnv($key);
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
