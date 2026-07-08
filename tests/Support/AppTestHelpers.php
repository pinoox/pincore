<?php

use Pinoox\Component\Test\AppTestKit;

function pinooxBoot(): void
{
    AppTestKit::boot();
}

function appPackage(?string $package = null): string
{
    if ($package !== null) {
        AppTestKit::setPackage($package);

        return $package;
    }

    return AppTestKit::package();
}

/**
 * Package under test (set by apps/{package}/tests/bootstrap.php or php pinoox test {package}).
 */
function appUnderTest(): string
{
    return AppTestKit::package();
}

function inMyApp(Closure $callback, string $path = '/'): mixed
{
    return inApp(appUnderTest(), $callback, $path);
}

function myAppGet(string $uri, array $query = [], array $headers = []): Pinoox\Component\Test\TestResponse
{
    return appGet(appUnderTest(), $uri, $query, $headers);
}

function myAppPost(string $uri, array $data = [], array $headers = []): Pinoox\Component\Test\TestResponse
{
    return appPost(appUnderTest(), $uri, $data, $headers);
}

function myAppPostJson(string $uri, array $json = [], array $headers = []): Pinoox\Component\Test\TestResponse
{
    return appPostJson(appUnderTest(), $uri, $json, $headers);
}

function inApp(string $package, Closure $callback, string $path = '/'): mixed
{
    return AppTestKit::inApp($package, $callback, $path);
}

function appPath(string $package, string $subPath = ''): string
{
    return AppTestKit::path($package, $subPath);
}

function appRequest(string $method, string $uri, array $data = [], array $query = [], array $headers = [], ?array $json = null): Pinoox\Component\Http\Request
{
    return AppTestKit::request($method, $uri, $data, $query, $headers, $json);
}

function appCall(string $package, string $method, string $uri, array $options = []): Pinoox\Component\Test\TestResponse
{
    return AppTestKit::call($package, $method, $uri, $options);
}

function appGet(string $package, string $uri, array $query = [], array $headers = []): Pinoox\Component\Test\TestResponse
{
    return AppTestKit::get($package, $uri, $query, $headers);
}

function appPost(string $package, string $uri, array $data = [], array $headers = []): Pinoox\Component\Test\TestResponse
{
    return AppTestKit::post($package, $uri, $data, $headers);
}

function appPostJson(string $package, string $uri, array $json = [], array $headers = []): Pinoox\Component\Test\TestResponse
{
    return AppTestKit::postJson($package, $uri, $json, $headers);
}

function fakeApp(string $package, array $files = []): string
{
    return AppTestKit::fakeApp($package, $files);
}

function deleteFakeApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}

function cleanupTestArtifacts(): void
{
    AppTestKit::cleanupTransientArtifacts(false);

    if (!\Pinoox\Tests\Support\TestRuntime::usesProjectPaths()) {
        \Pinoox\Tests\Support\TestRuntime::reapplyIsolatedRuntime(testProjectRoot());
        \Pinoox\Support\SystemConfig::clearCache();
    } else {
        try {
            \Pinoox\Portal\App\AppEngine::__rebuild();
        } catch (\Throwable) {
        }
    }
}

function testSandbox(string $relative = ''): string
{
    return \Pinoox\Tests\Support\TestSandbox::path($relative);
}

function testSandboxRoot(): string
{
    return \Pinoox\Tests\Support\TestSandbox::root();
}

function testPackage(string $suffix): string
{
    return \Pinoox\Tests\Support\TestSandbox::packageName($suffix);
}

function testRuntimeRoot(): string
{
    return \Pinoox\Tests\Support\TestRuntime::root();
}

function testRuntimeApps(): string
{
    return \Pinoox\Tests\Support\TestRuntime::appsRoot();
}

function testRuntimePinker(): string
{
    return \Pinoox\Tests\Support\TestRuntime::pinkerRoot();
}

function testRuntimeStorage(): string
{
    return \Pinoox\Tests\Support\TestRuntime::storageRoot();
}

function testRuntimeDevdb(string $relative = ''): string
{
    return \Pinoox\Tests\Support\TestRuntime::devdbPath($relative);
}

function bootstrapTestSodiumCompat(): void
{
    \Pinoox\Component\Package\Pinx\SodiumBootstrap::ensureAvailable();
}

function testProjectRoot(): string
{
    return str_replace('\\', '/', AppTestKit::projectRoot());
}

function testCoreRoot(): string
{
    if (defined('PINOOX_CORE_PATH')) {
        return rtrim(str_replace('\\', '/', PINOOX_CORE_PATH), '/');
    }

    $vendorCore = testProjectRoot() . '/vendor/pinoox/pincore';
    if (is_dir($vendorCore)) {
        return $vendorCore;
    }

    return testProjectRoot() . '/pincore';
}

function testCoreRootReal(): string
{
    $path = testCoreRoot();
    $realPath = realpath($path);

    return str_replace('\\', '/', $realPath !== false ? $realPath : $path);
}

function testFixtures(string $relative = ''): string
{
    $base = AppTestKit::fixturesRoot();
    $relative = ltrim(str_replace('\\', '/', $relative), '/');

    return $relative === '' ? $base : $base . '/' . $relative;
}

function testFixturesProjectRelative(string $relative = ''): string
{
    return AppTestKit::fixturesProjectRelative($relative);
}

function expectPortalContract(string $class): void
{
    $basePath = rtrim(testCoreRoot(), '/') . '/';
    $file = $basePath . str_replace('\\', '/', substr($class, strlen('Pinoox\\'))) . '.php';
    $source = file_get_contents($file);

    expect(is_file($file))->toBeTrue()
        ->and($source)->toContain('extends Portal')
        ->and($source)->toContain('function __name');
}

