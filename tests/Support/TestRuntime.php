<?php

namespace Pinoox\Tests\Support;

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

    public static function bootstrap(string $platformRoot): void
    {
        if (self::usesProjectPaths()) {
            return;
        }

        self::ensureDirectories();
        self::applyPathEnv();
        self::writeProjectAppsRegistry($platformRoot);
    }

    public static function usesProjectPaths(): bool
    {
        $flag = $_ENV[self::ENV_USE_PROJECT_PATHS]
            ?? $_SERVER[self::ENV_USE_PROJECT_PATHS]
            ?? getenv(self::ENV_USE_PROJECT_PATHS);

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

        $projectRegistry = $platformRoot . '/config/apps.config.php';
        if (is_file($projectRegistry)) {
            $config = require $projectRegistry;
            if (is_array($config)) {
                $configured = $config['packages'] ?? $config['apps'] ?? [];
                if (is_array($configured)) {
                    $packages = array_merge($packages, $configured);
                }
            }
        }

        $projectApps = $platformRoot . '/apps';
        if (is_dir($projectApps)) {
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

                if (!isset($packages[$entry])) {
                    $packages[$entry] = '~/apps/' . $entry;
                }
            }
        }

        foreach (self::bundledSystemPackages() as $package => $path) {
            if (!isset($packages[$package])) {
                $packages[$package] = $path;
            }
        }

        return $packages;
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
}
