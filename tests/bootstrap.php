<?php

/**
 * Shared bootstrap for framework and app tests.
 */

$coreTestsDir = rtrim(str_replace('\\', '/', __DIR__), '/');
$platformRoot = null;

foreach ([2, 4] as $depth) {
    $candidate = dirname($coreTestsDir, $depth);

    if (is_file($candidate . '/launcher/core-path.php')) {
        $platformRoot = $candidate;
        break;
    }
}

if ($platformRoot === null) {
    throw new RuntimeException(
        'Could not locate Pinoox project root (launcher/core-path.php) from core tests path: ' . $coreTestsDir,
    );
}

require_once $platformRoot . '/launcher/core-path.php';
require_once PINOOX_CORE_PATH . 'launcher/test-paths.php';
require_once PINOOX_BASE_PATH . '/vendor/autoload.php';
require_once PINOOX_CORE_PATH . 'functions/base.php';
require_once __DIR__ . '/Support/AppTestHelpers.php';
require_once __DIR__ . '/Support/ApiSystemHelpers.php';
require_once __DIR__ . '/Support/InstallerTestHelpers.php';
require_once __DIR__ . '/Support/DatabaseTestHelpers.php';

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
