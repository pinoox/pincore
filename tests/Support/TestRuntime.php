<?php

namespace Pinoox\Tests\Support;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\AppRegistry;
use Pinoox\Support\SystemConfig;

/**
 * Isolated apps/pinker/storage workspace for framework tests — never writes to project runtime dirs.
 */
final class TestRuntime
{
    private const ENV_USE_PROJECT_PATHS = 'PINOOX_TEST_USE_PROJECT_PATHS';

    private const ENV_APPS_PATH = 'PINOOX_APPS_PATH';

    private const ENV_PINKER_PATH = 'PINOOX_PINKER_PATH';

    private const ENV_STORAGE_PATH = 'PINOOX_STORAGE_PATH';

    private const ENV_TEST_RUNTIME_PATH = 'PINOOX_TEST_RUNTIME_PATH';

    private const ENV_PROJECT_REGISTRY = 'PINOOX_PROJECT_REGISTRY_PATH';

    private const ENV_INCLUDE_PROJECT_APPS = 'PINOOX_TEST_INCLUDE_PROJECT_APPS';

    public static function bootstrap(string $platformRoot): void
    {
        if (self::usesProjectPaths()) {
            return;
        }

        self::ensureDirectories();
        self::applyPathEnv();
        self::writeProjectAppsRegistry($platformRoot);
    }

    public static function reapplyIsolatedRuntime(string $platformRoot): void
    {
        if (self::usesProjectPaths()) {
            return;
        }

        self::resetDevDbWorkspace();
        self::bootstrap($platformRoot);
        self::syncAppEngineRegistry($platformRoot);
    }

    /**
     * Drop DevDB workspaces left by earlier tests in the same Pest process.
     */
    public static function resetDevDbWorkspace(): void
    {
        $root = self::devdbRoot();
        if (!is_dir($root)) {
            return;
        }

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::deleteTree($root . '/' . $entry);
        }
    }

    private static function deleteTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        } catch (\Throwable) {
        }

        @rmdir($path);
    }

    public static function usesProjectPaths(): bool
    {
        $flag = $_ENV[self::ENV_USE_PROJECT_PATHS]
            ?? $_SERVER[self::ENV_USE_PROJECT_PATHS]
            ?? getenv(self::ENV_USE_PROJECT_PATHS);

        return $flag === '1' || $flag === 'true';
    }

    public static function includesProjectApps(): bool
    {
        $flag = $_ENV[self::ENV_INCLUDE_PROJECT_APPS]
            ?? $_SERVER[self::ENV_INCLUDE_PROJECT_APPS]
            ?? getenv(self::ENV_INCLUDE_PROJECT_APPS);

        return $flag === '1' || $flag === 'true';
    }

    public static function root(): string
    {
        $corePath = defined('PINOOX_CORE_PATH')
            ? rtrim(str_replace('\\', '/', \PINOOX_CORE_PATH), '/')
            : dirname(__DIR__, 2);

        return $corePath . '/tests/Fixtures/runtime';
    }

    public static function appsRoot(): string
    {
        return self::root() . '/apps';
    }

    public static function pinkerRoot(): string
    {
        return self::root() . '/pinker';
    }

    public static function storageRoot(): string
    {
        return self::root() . '/storage';
    }

    public static function devdbRoot(): string
    {
        return self::root() . '/devdb';
    }

    public static function devdbPath(string $relative = ''): string
    {
        $root = self::devdbRoot();
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return $root;
        }

        $path = $root . '/' . $relative;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    /**
     * Writable runtime directories created under {@see root()}.
     *
     * @return list<string>
     */
    public static function runtimeDirectories(): array
    {
        $pinker = self::pinkerRoot();
        $storage = self::storageRoot();

        return [
            self::root(),
            self::appsRoot(),
            $pinker,
            $pinker . '/apps',
            $pinker . '/platform',
            $pinker . '/state',
            $pinker . '/state/platform',
            $pinker . '/wizard_tmp',
            $storage,
            $storage . '/pinion',
            self::devdbRoot(),
        ];
    }

    public static function projectRelative(string $absolutePath): string
    {
        $root = defined('PINOOX_BASE_PATH')
            ? rtrim(str_replace('\\', '/', \PINOOX_BASE_PATH), '/')
            : dirname(self::root(), 4);

        $absolutePath = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($absolutePath, $root . '/')) {
            return substr($absolutePath, strlen($root) + 1);
        }

        return $absolutePath;
    }

    public static function ensureDirectories(): void
    {
        foreach (self::runtimeDirectories() as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }

    private static function applyPathEnv(): void
    {
        self::setEnv(self::ENV_TEST_RUNTIME_PATH, self::projectRelative(self::root()));
        self::setEnv(self::ENV_APPS_PATH, self::projectRelative(self::appsRoot()));
        self::setEnv(self::ENV_PINKER_PATH, self::projectRelative(self::pinkerRoot()));
        self::setEnv(self::ENV_STORAGE_PATH, self::projectRelative(self::storageRoot()));
    }

    private static function writeProjectAppsRegistry(string $platformRoot): void
    {
        $registryFile = self::root() . '/project-apps.registry.php';
        $packages = self::registryPackages($platformRoot);

        file_put_contents(
            $registryFile,
            "<?php\n\nreturn " . var_export(['packages' => $packages], true) . ";\n",
        );

        self::setEnv(self::ENV_PROJECT_REGISTRY, self::projectRelative($registryFile));
    }

    /**
     * @return array<string, string>
     */
    private static function registryPackages(string $platformRoot): array
    {
        $platformRoot = rtrim(str_replace('\\', '/', $platformRoot), '/');
        $packages = [];

        foreach ([
            $platformRoot . '/config/apps.config.php',
            $platformRoot . '/platform/apps.config.php',
        ] as $projectRegistry) {
            if (!is_file($projectRegistry)) {
                continue;
            }

            $config = require $projectRegistry;
            if (!is_array($config)) {
                continue;
            }

            $configured = $config['packages'] ?? $config['apps'] ?? [];
            if (!is_array($configured)) {
                continue;
            }

            foreach ($configured as $package => $path) {
                if (!is_string($package) || self::isProjectAppsRegistryPath($path, $package)) {
                    continue;
                }

                $packages[$package] = $path;
            }
        }

        foreach (self::bundledSystemPackages() as $package => $path) {
            if (!isset($packages[$package])) {
                $packages[$package] = $path;
            }
        }

        if (self::includesProjectApps()) {
            $packages = self::mergeProjectAppsRegistry($platformRoot, $packages);
        }

        return $packages;
    }

    /**
     * Read-only registry entries for installed project apps (NonIsolated tests only).
     *
     * @param array<string, string> $packages
     * @return array<string, string>
     */
    private static function mergeProjectAppsRegistry(string $platformRoot, array $packages): array
    {
        $projectApps = $platformRoot . '/apps';
        if (!is_dir($projectApps)) {
            return $packages;
        }

        foreach (scandir($projectApps) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_starts_with($entry, 'com_')) {
                continue;
            }

            if (str_starts_with($entry, 'com_test_') || str_starts_with($entry, 'com_boot_')) {
                continue;
            }

            $appFile = $projectApps . '/' . $entry . '/app.php';
            if (!is_file($appFile)) {
                continue;
            }

            $packages[$entry] = '~/apps/' . $entry;
        }

        return $packages;
    }

    /**
     * Skip registry entries that point at the project apps/ tree (real installed apps).
     */
    private static function isProjectAppsRegistryPath(mixed $path, string $package): bool
    {
        if (is_array($path)) {
            $path = $path['path'] ?? '';
        }

        if (!is_string($path)) {
            return false;
        }

        $path = str_replace('\\', '/', trim($path));

        return $path === '~/apps/' . $package
            || $path === 'apps/' . $package
            || str_starts_with($path, '~/apps/')
            || str_starts_with($path, 'apps/com_');
    }

    /**
     * Minimal com_pinoox_* stubs for standalone pincore CI (no monorepo apps/).
     *
     * @return array<string, string> package => ~pincore path alias
     */
    private static function bundledSystemPackages(): array
    {
        $root = self::systemAppsRoot();
        if (!is_dir($root)) {
            return [];
        }

        $packages = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $appRoot = $root . '/' . $entry;
            if (!is_dir($appRoot) || !is_file($appRoot . '/app.php')) {
                continue;
            }

            $packages[$entry] = '~pincore/tests/Fixtures/system-apps/' . $entry;
        }

        return $packages;
    }

    private static function systemAppsRoot(): string
    {
        $corePath = defined('PINOOX_CORE_PATH')
            ? rtrim(str_replace('\\', '/', \PINOOX_CORE_PATH), '/')
            : dirname(__DIR__, 2);

        return $corePath . '/tests/Fixtures/system-apps';
    }

    private static function setEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public static function enableProjectAppsRegistry(string $platformRoot): void
    {
        self::setEnv(self::ENV_INCLUDE_PROJECT_APPS, '1');
        self::syncAppEngineRegistry($platformRoot);
    }

    public static function disableProjectAppsRegistry(string $platformRoot): void
    {
        putenv(self::ENV_INCLUDE_PROJECT_APPS);
        unset($_ENV[self::ENV_INCLUDE_PROJECT_APPS], $_SERVER[self::ENV_INCLUDE_PROJECT_APPS]);
        self::syncAppEngineRegistry($platformRoot);
    }

    /**
     * Rewrite the isolated registry file and push resolved packages into AppEngine.
     *
     * AppEngine::__rebuild() clears cached config but keeps the original ArrayLoader;
     * NonIsolated tests must overlay registry paths after the portal is first booted.
     */
    public static function syncAppEngineRegistry(string $platformRoot): void
    {
        self::writeProjectAppsRegistry($platformRoot);
        SystemConfig::clearCache();

        if (!class_exists(AppEngine::class, false)) {
            return;
        }

        $packages = AppRegistry::load(
            SystemConfig::path('system_registry'),
            (string)Loader::getBasePath(),
        );

        foreach ($packages as $package => $path) {
            try {
                AppEngine::add($package, $path);
            } catch (\Throwable) {
            }
        }

        try {
            AppEngine::__rebuild();
        } catch (\Throwable) {
        }
    }
}
