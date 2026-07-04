<?php

/**
 * Shared bootstrap for framework and app tests.
 */

$coreTestsDir = rtrim(str_replace('\\', '/', __DIR__), '/');
$platformRoot = null;

foreach ([4, 3, 2, 1] as $depth) {
    $candidate = dirname($coreTestsDir, $depth);

    if (is_file($candidate . '/platform/launcher/core-path.php')) {
        $platformRoot = $candidate;
        break;
    }
}

if ($platformRoot === null) {
    foreach ([1, 2, 3, 4] as $depth) {
        $candidate = dirname($coreTestsDir, $depth);

        if (is_file($candidate . '/launcher/core-path.php')) {
            $platformRoot = $candidate;
            break;
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

if ($loader instanceof Composer\Autoload\ClassLoader) {
    $coreTests = rtrim(str_replace('\\', '/', PINOOX_CORE_PATH), '/') . '/tests';
    if (is_dir($coreTests)) {
        $loader->addPsr4('Pinoox\\Pinoox\Tests\\', $coreTests . '/');
    }
}
require_once PINOOX_CORE_PATH . 'functions/base.php';
require_once __DIR__ . '/Support/AppTestHelpers.php';
require_once __DIR__ . '/Support/ApiSystemHelpers.php';
require_once __DIR__ . '/Support/InstallerTestHelpers.php';
require_once __DIR__ . '/Support/DatabaseTestHelpers.php';
require_once __DIR__ . '/Support/CliTestHelpers.php';
require_once __DIR__ . '/Support/FilesystemTestHelpers.php';

require_once __DIR__ . '/Support/TestSandbox.php';
require_once __DIR__ . '/Support/TestRuntime.php';
require_once __DIR__ . '/TestCase.php';

Pinoox\Tests\Support\TestRuntime::bootstrap($platformRoot);

// PHPUnit/Pest: test runtime overrides machine env (individual tests may override again).
restoreTestDevDbEnvironment();
bootstrapDevDbAutoload();
bootstrapTestSodiumCompat();

\Pinoox\Component\Helpers\EnvBootstrap::load(PINOOX_BASE_PATH);

// EnvBootstrap defaults APP_DEBUG=true for non-production modes; PHPUnit treats E_USER_NOTICE as failures.
putenv('APP_DEBUG=false');
$_ENV['APP_DEBUG'] = 'false';
$_SERVER['APP_DEBUG'] = 'false';
// PinooxDebug wraps Composer autoload and breaks PHPUnit/Pest class discovery.
putenv('PINOOX_EXCEPTION=false');
$_ENV['PINOOX_EXCEPTION'] = 'false';
$_SERVER['PINOOX_EXCEPTION'] = 'false';
\Pinoox\Support\SystemConfig::clearCache();

$testClassLoader = $loader;
foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $registeredLoader) {
    if ($registeredLoader->findFile('PHPUnit\\TextUI\\Configuration\\TestSuiteBuilder') !== false) {
        $testClassLoader = $registeredLoader;
        break;
    }
}

if ($testClassLoader instanceof Composer\Autoload\ClassLoader) {
    $appsAutoload = rtrim(str_replace('\\', '/', PINOOX_BASE_PATH), '/') . '/apps/';
    if (is_dir($appsAutoload)) {
        $testClassLoader->addPsr4('App\\', $appsAutoload, true);
    }

    \Pinoox\Component\Kernel\Loader::set($testClassLoader, PINOOX_BASE_PATH);
}

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
