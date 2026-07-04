<?php

/**
 * Shared helpers for Database feature tests — avoids duplicate global functions across test files.
 */

use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Tests\Support\TestRuntime;

function writeTestApp(string $package, array $config): void
{
    $payload = [
        'package' => $package,
        'enable' => true,
        'name' => $package,
        ...$config,
    ];

    $path = AppTestKit::fakeApp($package, [
        'app.php' => "<?php\n\nreturn " . var_export($payload, true) . ";\n",
    ]);
    AppEngine::add($package, $path);
    AppEngine::__rebuild();
}

function deleteTestApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}

function testDevDbPath(?string $name = null): string
{
    static $counter = 0;

    $counter++;
    $name ??= 'connection_' . $counter;
    $path = TestRuntime::devdbPath(preg_replace('/[^A-Za-z0-9_=-]+/', '_', $name));
    deleteDirectory($path);
    mkdir($path, 0777, true);

    return $path;
}

function testDevDbConnection(string $prefix = '', ?string $name = null): array
{
    static $counter = 0;

    $counter++;
    $name ??= 'connection_' . $counter;
    $path = testDevDbPath($name);

    return [
        'driver' => 'devdb',
        'database' => 'devdb',
        'engine' => 'json',
        'path' => $path,
        'prefix' => $prefix,
    ];
}

function restoreTestDevDbEnvironment(): void
{
    putenv('APP_ENV=test');
    $_ENV['APP_ENV'] = 'test';
    $_SERVER['APP_ENV'] = 'test';

    putenv('PINOOX_TESTING=true');
    $_ENV['PINOOX_TESTING'] = 'true';
    $_SERVER['PINOOX_TESTING'] = 'true';

    putenv('DB_CONNECTION=devdb');
    $_ENV['DB_CONNECTION'] = 'devdb';
    $_SERVER['DB_CONNECTION'] = 'devdb';

    putenv('DEVDB_ENGINE=json');
    $_ENV['DEVDB_ENGINE'] = 'json';
    $_SERVER['DEVDB_ENGINE'] = 'json';

    \Pinoox\Support\SystemConfig::clearCache();
}

function bootstrapDevDbAutoload(): void
{
    bootstrapPincoreIlluminateAutoload();

    if (class_exists(\Pinoox\Component\Database\Connections\DevDbConnection::class)) {
        return;
    }

    $corePath = rtrim(str_replace('\\', '/', defined('PINOOX_CORE_PATH') ? PINOOX_CORE_PATH : testCoreRoot()), '/');
    $classmapCandidates = [
        $corePath . '/vendor/composer/autoload_classmap.php',
        dirname($corePath) . '/packages/devdb/vendor/composer/autoload_classmap.php',
    ];

    foreach ($classmapCandidates as $classmapFile) {
        if (!is_file($classmapFile)) {
            continue;
        }

        $classmap = require $classmapFile;
        $devdb = [];

        foreach ($classmap as $class => $path) {
            if (is_string($path) && str_contains($path, '/pinoox/devdb/')) {
                $devdb[$class] = $path;
            }
        }

        if ($devdb === []) {
            continue;
        }

        foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap($devdb);
            break;
        }

        if (class_exists(\Pinoox\Component\Database\Connections\DevDbConnection::class)) {
            return;
        }
    }

    throw new RuntimeException(
        'DevDB is required for database tests. Run: composer install --working-dir=' . $corePath,
    );
}

function bootstrapPincoreIlluminateAutoload(): void
{
    $corePath = rtrim(str_replace('\\', '/', defined('PINOOX_CORE_PATH') ? PINOOX_CORE_PATH : testCoreRoot()), '/');
    $psr4File = $corePath . '/vendor/composer/autoload_psr4.php';
    if (!is_file($psr4File)) {
        return;
    }

    $packages = require $psr4File;
    $prefixes = $packages['prefixes'] ?? $packages;
    $loaders = Composer\Autoload\ClassLoader::getRegisteredLoaders();
    if ($loaders === []) {
        return;
    }

    $loader = reset($loaders);
    foreach ($prefixes as $prefix => $paths) {
        if (!str_starts_with($prefix, 'Illuminate\\')) {
            continue;
        }

        foreach ($paths as $path) {
            $loader->addPsr4($prefix, $path, true);
        }
    }
}

/** @deprecated Use {@see deleteTestApp()} */
function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
