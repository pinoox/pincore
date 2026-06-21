<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\App;
use Pinoox\Component\Package\AppRouter;
use Pinoox\Component\Package\Engine\AppEngine as PackageAppEngine;
use Pinoox\Component\Store\Config\ConfigInterface;
use Pinoox\Support\AppRegistry;
use Symfony\Component\Routing\RequestContext;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
});

afterEach(function () {
    deleteAppRegistryTestDirectory(testFixtures('external_apps'));
    deleteAppRegistryTestDirectory(testFixtures('system_config/combined_apps'));
    deleteAppRegistryTestDirectory(testFixtures('system_config/priority_apps'));
});

it('registers external apps from the system app registry config', function () {
    $basePath = str_replace('\\', '/', testProjectRoot());
    $package = 'com_test_registry';
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $package);

    if (!is_dir($externalApp)) {
        mkdir($externalApp, 0777, true);
    }

    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'Registry Test', 'enable' => true];\n");

    $registryFile = str_replace('\\', '/', testFixtures('app_registry.config.php'));

    try {
        file_put_contents($registryFile, "<?php\n\nreturn ['packages' => ['{$package}' => '" . testFixturesProjectRelative('external_apps/' . $package) . "']];\n");

        $packages = AppRegistry::load($registryFile, $basePath);
        $engine = new PackageAppEngine($basePath . '/missing_apps_dir', 'app.php', testAppRegistryPinkerPath(), null, $packages);

        expect($packages[$package])->toBe($externalApp)
            ->and($engine->exists($package))->toBeTrue()
            ->and($engine->path($package))->toBe($externalApp)
            ->and($engine->config($package)->get('name'))->toBe('Registry Test');
    } finally {
        @unlink($registryFile);
    }
});

it('autoloads external app namespaces registered by the system registry', function () {
    $basePath = str_replace('\\', '/', testProjectRoot());
    $package = 'com_test_registry_autoload';
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $package);
    $controllerDir = $externalApp . '/Controller';
    $controllerClass = 'App\\' . $package . '\\Controller\\RegistryController';

    if (!is_dir($controllerDir)) {
        mkdir($controllerDir, 0777, true);
    }

    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'Registry Autoload', 'enable' => true];\n");
    file_put_contents($controllerDir . '/RegistryController.php', "<?php\n\nnamespace App\\{$package}\\Controller;\n\nclass RegistryController {}\n");

    $packages = [$package => $externalApp];
    $engine = new PackageAppEngine($basePath . '/missing_apps_dir', 'app.php', testAppRegistryPinkerPath(), null, $packages);
    $loader = new Composer\Autoload\ClassLoader();
    $loader->register();

    try {
        new App(
            new AppRouter(new AppRegistryTestConfig([]), $engine, new Request()),
            $engine,
            new RequestContext(),
            $loader,
        );

        expect(class_exists($controllerClass))->toBeTrue();
    } finally {
        $loader->unregister();
    }
});

it('skips disabled packages in the registry config', function () {
    $basePath = str_replace('\\', '/', testProjectRoot());
    $package = 'com_test_registry_disabled';
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $package);

    if (!is_dir($externalApp)) {
        mkdir($externalApp, 0777, true);
    }

    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'enable' => true];\n");

    $packages = AppRegistry::fromArray([
        'packages' => [
            $package => ['path' => testFixturesProjectRelative('external_apps/' . $package), 'enabled' => false],
        ],
    ], $basePath);

    expect($packages)->toBe([]);
});

it('accepts the apps key alias in registry config', function () {
    $basePath = str_replace('\\', '/', testProjectRoot());
    $package = 'com_test_registry_apps_key';
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $package);

    if (!is_dir($externalApp)) {
        mkdir($externalApp, 0777, true);
    }

    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'enable' => true];\n");

    $packages = AppRegistry::fromArray([
        'apps' => [
            $package => testFixturesProjectRelative('external_apps/' . $package),
        ],
    ], $basePath);

    expect($packages[$package])->toBe($externalApp);
});

it('combines folder discovery with registry packages in the app engine', function () {
    $basePath = str_replace('\\', '/', testProjectRoot());
    $appsPath = testFixtures('system_config/combined_apps');
    $folderPackage = 'com_test_folder_combo';
    $registryPackage = 'com_test_registry_combo';
    $folderApp = $appsPath . '/' . $folderPackage;
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $registryPackage);

    foreach ([$folderApp, $externalApp] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    file_put_contents($folderApp . '/app.php', "<?php\n\nreturn ['package' => '{$folderPackage}', 'enable' => true];\n");
    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$registryPackage}', 'enable' => true];\n");

    $registry = AppRegistry::fromArray([
        'packages' => [
            $registryPackage => testFixturesProjectRelative('external_apps/' . $registryPackage),
        ],
    ], $basePath);

    $engine = new PackageAppEngine($appsPath, 'app.php', testAppRegistryPinkerPath(), null, $registry);

    expect($engine->exists($folderPackage))->toBeTrue()
        ->and($engine->exists($registryPackage))->toBeTrue()
        ->and($engine->path($folderPackage))->toBe(str_replace('\\', '/', $folderApp))
        ->and($engine->path($registryPackage))->toBe($externalApp)
        ->and(array_keys($engine->all()))->toEqualCanonicalizing([$folderPackage, $registryPackage]);
});

it('prefers registry path when the same package exists in the apps folder', function () {
    $basePath = str_replace('\\', '/', testProjectRoot());
    $package = 'com_test_registry_priority';
    $appsPath = testFixtures('system_config/priority_apps');
    $folderApp = $appsPath . '/' . $package;
    $externalApp = str_replace('\\', '/', testFixtures('external_apps/') . $package);

    foreach ([$folderApp, $externalApp] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    file_put_contents($folderApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'enable' => true, 'name' => 'Folder copy'];\n");
    file_put_contents($externalApp . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'enable' => true, 'name' => 'Registry copy'];\n");

    $registry = AppRegistry::fromArray([
        'packages' => [
            $package => testFixturesProjectRelative('external_apps/' . $package),
        ],
    ], $basePath);

    $engine = new PackageAppEngine($appsPath, 'app.php', testAppRegistryPinkerPath(), null, $registry);

    expect($engine->path($package))->toBe($externalApp)
        ->and($engine->config($package)->get('name'))->toBe('Registry copy');
});

class AppRegistryTestConfig implements ConfigInterface
{
    public function __construct(private array $data = [])
    {
    }

    public function get(?string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->data[$key]);

        return $this;
    }

    public function save(): static
    {
        return $this;
    }
}

function deleteAppRegistryTestDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

function testAppRegistryPinkerPath(): string
{
    return testFixturesProjectRelative('runtime/pinker');
}

