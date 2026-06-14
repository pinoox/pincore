<?php

/**
 * Shared bootstrap for framework and app tests.
 */

$coreTestsDir = rtrim(str_replace('\\', '/', __DIR__), '/');
$platformRoot = null;

foreach ([2, 4] as $depth) {
    $candidate = dirname($coreTestsDir, $depth);

    foreach ([
        '/platform/launcher/core-path.php',
        '/launcher/core-path.php',
    ] as $launcherCorePath) {
        if (is_file($candidate . $launcherCorePath)) {
            $platformRoot = $candidate;
            break 2;
        }
    }
}

if ($platformRoot === null) {
    throw new RuntimeException(
        'Could not locate Pinoox project root (platform/launcher/core-path.php) from core tests path: ' . $coreTestsDir,
    );
}

foreach ([
    '/platform/launcher/core-path.php',
    '/launcher/core-path.php',
] as $launcherCorePath) {
    if (is_file($platformRoot . $launcherCorePath)) {
        require_once $platformRoot . $launcherCorePath;
        break;
    }
}
require_once PINOOX_CORE_PATH . 'launcher/test-paths.php';
$loader = require PINOOX_BASE_PATH . '/vendor/autoload.php';

if ($loader instanceof Composer\Autoload\ClassLoader && is_file($platformRoot . '/platform/launcher/core-autoload.php')) {
    require_once $platformRoot . '/platform/launcher/core-autoload.php';
    pinoox_register_core_autoload($loader, PINOOX_BASE_PATH, PINOOX_CORE_PATH);
}
require_once PINOOX_CORE_PATH . 'functions/base.php';
require_once __DIR__ . '/Support/AppTestHelpers.php';
require_once __DIR__ . '/Support/ApiSystemHelpers.php';
require_once __DIR__ . '/Support/InstallerTestHelpers.php';
require_once __DIR__ . '/Support/DatabaseTestHelpers.php';
require_once __DIR__ . '/Support/CliTestHelpers.php';

require_once __DIR__ . '/Support/TestSandbox.php';
require_once __DIR__ . '/Support/TestRuntime.php';

\Pinoox\Component\Helpers\EnvBootstrap::load(PINOOX_BASE_PATH);

Tests\Support\TestRuntime::bootstrap($platformRoot);
\Pinoox\Support\SystemConfig::clearCache();

// PHPUnit/Pest: test runtime overrides machine env (individual tests may override again).
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';
putenv('DB_CONNECTION=sqlite');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_CONNECTION'] = 'sqlite';

Pinoox\Component\Test\AppTestKit::boot();

$testPackage = getenv('PINOOX_TEST_PACKAGE') ?: ($_ENV['PINOOX_TEST_PACKAGE'] ?? $_SERVER['PINOOX_TEST_PACKAGE'] ?? '');
if (is_string($testPackage) && $testPackage !== '') {
    Pinoox\Component\Test\AppTestKit::setPackage($testPackage);
}

register_shutdown_function(static function (): void {
    if (class_exists(\Pinoox\Component\Test\AppTestKit::class, false)) {
        \Pinoox\Component\Test\AppTestKit::cleanupTransientArtifacts(false);
    }
});
