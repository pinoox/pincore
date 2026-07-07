<?php

namespace Pinoox\Support;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Store\Baker\Pinker;

class SystemConfig
{
    private static array $cache = [];

    /** Config names that must never go through Pinker (bootstrap / path resolution). */
    private const DIRECT_LOAD_CONFIGS = ['paths'];

    /** Deploy configs: project source with pincore stub fallback. */
    private const PROJECT_LAYER_CONFIGS = ['apps', 'domain', 'app-router'];

    /** Platform manifest ({project}/platform) merged onto pincore runtime defaults. */
    private const MERGED_PLATFORM_CONFIGS = ['pinoox'];

    /** Bootstrap/kernel configs — never overridden from {project}/platform. */
    private const CORE_ONLY_CONFIGS = ['paths', 'pincore'];

    /** @var array<string, string> legacy path key → v3 key */
    private const PATH_KEY_ALIASES = [
        'system_config' => 'config',
        'system_registry' => 'project_registry',
        'system_router' => 'project_router',
        'system_lang' => 'platform_lang',
        'system_migrations' => 'platform_migrations',
        'system_seed' => 'platform_seed',
        'system_patches' => 'platform_patches',
        'system_models' => 'platform_models',
    ];

    public static function get(string $config, ?string $key = null, mixed $default = null): mixed
    {
        $data = self::load($config);

        if ($key === null || $key === '') {
            return $data;
        }

        foreach (explode('.', $key) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return $default;
            }

            $data = $data[$part];
        }

        return $data;
    }

    public static function path(string $key, ?string $default = null): string
    {
        $key = self::PATH_KEY_ALIASES[$key] ?? $key;

        foreach (self::runtimePathEnvOverrides() as $pathKey => $envKey) {
            if ($key !== $pathKey) {
                continue;
            }

            $override = self::env($envKey);
            if (is_string($override) && $override !== '') {
                return self::resolvePath($override);
            }
        }

        $value = self::get('paths', $key, $default ?? $key);

        return self::resolvePath((string)$value);
    }

    /**
     * Path keys that must follow process env after config files were cached (test bootstrap).
     *
     * @return array<string, string> path key => env var
     */
    private static function runtimePathEnvOverrides(): array
    {
        return [
            'config' => 'PINOOX_CONFIG_PATH',
            'system' => 'PINOOX_CONFIG_PATH',
            'pinker_config' => 'PINOOX_PINKER_CONFIG_PATH',
            'apps' => 'PINOOX_APPS_PATH',
            'pinker' => 'PINOOX_PINKER_PATH',
            'storage' => 'PINOOX_STORAGE_PATH',
            'project_config' => 'PINOOX_PROJECT_CONFIG_PATH',
            'project_registry' => 'PINOOX_PROJECT_REGISTRY_PATH',
            'project_router' => 'PINOOX_PROJECT_ROUTER_PATH',
            'project_domain' => 'PINOOX_PROJECT_DOMAIN_PATH',
            'project_pinoox' => 'PINOOX_PROJECT_PINOOX_PATH',
            'project_pincore' => 'PINOOX_PROJECT_PINCORE_PATH',
            'platform_lang' => 'PINOOX_PLATFORM_LANG_PATH',
            'platform_migrations' => 'PINOOX_PLATFORM_MIGRATIONS_PATH',
            'platform_seed' => 'PINOOX_PLATFORM_SEED_PATH',
            'platform_patches' => 'PINOOX_PLATFORM_PATCHES_PATH',
            'platform_models' => 'PINOOX_PLATFORM_MODELS_PATH',
            'stubs' => 'PINOOX_STUBS_PATH',
            'wizard_tmp' => 'PINOOX_WIZARD_TMP_PATH',
            'pinion_uploads' => 'PINOOX_PINION_UPLOADS_PATH',
            'package_manual' => 'PINOOX_PACKAGE_MANUAL_PATH',
        ];
    }

    /**
     * Resolve a platform resource directory (migrations, patches, seed).
     *
     * Uses the v3 pincore path first, then legacy system/ layout from older installs.
     */
    public static function platformPath(string $resource): string
    {
        $canonical = match ($resource) {
            'migrations' => self::path('platform_migrations'),
            'patches' => self::path('platform_patches'),
            'seed' => self::path('platform_seed'),
            default => throw new \InvalidArgumentException('Unknown platform resource: ' . $resource),
        };

        foreach (self::platformPathCandidates($resource) as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $canonical;
    }

    /**
     * @return list<string>
     */
    private static function platformPathCandidates(string $resource): array
    {
        $root = self::rootPath();
        $core = self::corePath();

        return match ($resource) {
            'migrations' => [
                self::path('platform_migrations'),
                $core . '/database/migrations',
                $root . '/vendor/pinoox/pincore/database/migrations',
                $root . '/pincore/database/migrations',
                $root . '/system/database/migrations',
            ],
            'patches' => [
                self::path('platform_patches'),
                $core . '/patches',
                $root . '/vendor/pinoox/pincore/patches',
                $root . '/pincore/patches',
                $root . '/system/patches',
            ],
            'seed' => [
                self::path('platform_seed'),
                $core . '/database/seeders',
                $root . '/vendor/pinoox/pincore/database/seeders',
                $root . '/pincore/database/seeders',
                $core . '/database/seed',
                $root . '/vendor/pinoox/pincore/database/seed',
                $root . '/pincore/database/seed',
                $root . '/system/database/seed',
            ],
            default => [],
        };
    }

    public static function rawPath(string $key, ?string $default = null): string
    {
        $key = self::PATH_KEY_ALIASES[$key] ?? $key;

        return (string)self::get('paths', $key, $default ?? $key);
    }

    public static function rootPath(): string
    {
        $basePath = Loader::getBasePath();

        if (is_string($basePath) && $basePath !== '') {
            return rtrim(str_replace('\\', '/', $basePath), '/');
        }

        return defined('PINOOX_BASE_PATH')
            ? rtrim(str_replace('\\', '/', \PINOOX_BASE_PATH), '/')
            : dirname(__DIR__, 2);
    }

    public static function corePath(string $path = ''): string
    {
        $corePath = defined('PINOOX_CORE_PATH')
            ? rtrim(str_replace('\\', '/', \PINOOX_CORE_PATH), '/')
            : self::resolveBasePath(self::env('PINOOX_CORE_PATH', 'pincore'));

        return self::join($corePath, $path);
    }

    public static function configPath(string $path = ''): string
    {
        $override = self::env('PINOOX_CONFIG_PATH');

        if (is_string($override) && $override !== '') {
            return self::join(self::resolveBasePath($override), $path);
        }

        return self::corePath(self::join('config', $path));
    }

    public static function projectConfigPath(string $path = ''): string
    {
        $override = self::env('PINOOX_PROJECT_CONFIG_PATH');

        if (is_string($override) && $override !== '') {
            return self::join(self::resolvePath($override), $path);
        }

        $base = '~/config';

        if (array_key_exists('paths', self::$cache) && is_array(self::$cache['paths'])) {
            $configured = self::$cache['paths']['project_config'] ?? null;

            if (is_string($configured) && $configured !== '') {
                $base = $configured;
            }
        }

        return self::join(self::resolvePath($base), $path);
    }

    public static function projectLayerConfigFile(string $config): string
    {
        if (in_array($config, self::MERGED_PLATFORM_CONFIGS, true)) {
            return self::platformPinooxManifestFile();
        }

        return self::resolveConfigFile($config);
    }

    /**
     * Resolve a config file: {project}/platform/{name}.config.php when present,
     * otherwise pincore/config/{name}.config.php.
     *
     * {@see CORE_ONLY_CONFIGS} and {@see MERGED_PLATFORM_CONFIGS} use dedicated rules.
     */
    public static function resolveConfigFile(string $config): string
    {
        if (in_array($config, self::CORE_ONLY_CONFIGS, true)) {
            return self::configPath($config . '.config.php');
        }

        $projectFile = self::projectConfigPath($config . '.config.php');

        if (is_file($projectFile)) {
            return $projectFile;
        }

        return self::configPath($config . '.config.php');
    }

    public static function platformPinooxTemplateFile(): string
    {
        return self::corePath('config/pinoox.config.php');
    }

    public static function platformPinooxManifestFile(): string
    {
        return self::projectConfigPath('pinoox.config.php');
    }

    public static function ensureProjectConfigFiles(): void
    {
        foreach (self::PROJECT_LAYER_CONFIGS as $config) {
            self::ensureProjectConfigFile($config, self::corePath('config/' . $config . '.config.php'));
        }

        self::ensureProjectConfigFile('pinoox', self::corePath('stubs/pinoox.config.stub'));
    }

    private static function ensureProjectConfigFile(string $config, string $stub): void
    {
        $projectFile = self::projectConfigPath($config . '.config.php');

        if (is_file($projectFile) || !is_file($stub)) {
            return;
        }

        $directory = dirname($projectFile);

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        if (is_dir($directory)) {
            @copy($stub, $projectFile);
        }
    }

    public static function pinkerConfigPath(string $path = ''): string
    {
        return self::join(self::path('pinker_config'), $path);
    }

    public static function pinkerStateConfigPath(string $config): string
    {
        return self::join(self::path('pinker'), 'state/platform/' . $config . '.config.php');
    }

    /**
     * @return list<string>
     */
    public static function pinkerStateConfigCandidates(string $config): array
    {
        $pinker = self::path('pinker');

        return [
            self::pinkerStateConfigPath($config),
            self::join($pinker, 'state/config/' . $config . '.config.php'),
        ];
    }

    /** @deprecated v3 — use {@see configPath()} */
    public static function systemPath(string $path = ''): string
    {
        return self::configPath($path);
    }

    public static function resolvePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            return self::rootPath();
        }

        if ($path === '~') {
            return self::rootPath();
        }

        if (str_starts_with($path, '~/')) {
            return self::join(self::rootPath(), substr($path, 2));
        }

        foreach ([
            '~config' => self::configPath(),
            '~system' => self::configPath(),
            '~project' => is_dir(self::join(self::rootPath(), 'platform'))
                ? self::join(self::rootPath(), 'platform')
                : self::join(self::rootPath(), 'config'),
            '~pincore' => self::corePath(),
            '~pinker' => self::pathWithoutAlias('pinker', 'pinker'),
            '~storage' => self::pathWithoutAlias('storage', 'storage'),
        ] as $alias => $basePath) {
            if ($path === $alias) {
                return $basePath;
            }

            if (str_starts_with($path, $alias . '/')) {
                return self::join($basePath, substr($path, strlen($alias) + 1));
            }
        }

        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return self::join(self::rootPath(), $path);
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string)$value)) {
            'true', '(true)', '1', '(1)' => true,
            'false', '(false)', '0', '(0)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function load(string $config): array
    {
        if (array_key_exists($config, self::$cache)) {
            return self::$cache[$config];
        }

        if (
            (in_array($config, self::PROJECT_LAYER_CONFIGS, true) || in_array($config, self::MERGED_PLATFORM_CONFIGS, true))
            && !array_key_exists('paths', self::$cache)
        ) {
            self::load('paths');
        }

        if (in_array($config, self::MERGED_PLATFORM_CONFIGS, true)) {
            return self::$cache[$config] = self::loadMergedPlatformConfig($config);
        }

        $mainFile = self::resolveConfigFile($config);

        return self::$cache[$config] = self::loadConfigFromFile($config, $mainFile);
    }

    private static function loadMergedPlatformConfig(string $config): array
    {
        $templateFile = self::corePath('config/' . $config . '.config.php');
        $base = self::loadConfigFromFile($config, $templateFile);

        $manifestFile = self::projectConfigPath($config . '.config.php');

        if (!is_file($manifestFile)) {
            return $base;
        }

        $manifest = require $manifestFile;

        if (!is_array($manifest)) {
            return $base;
        }

        return array_replace_recursive($base, $manifest);
    }

    private static function loadConfigFromFile(string $config, string $mainFile): array
    {
        if (!is_file($mainFile)) {
            return [];
        }

        if (self::shouldLoadViaPinker($config, $mainFile)) {
            $bakedFile = self::pinkerConfigPath($config . '.config.php');
            $loaded = Pinker::create($mainFile, $bakedFile)->pickup();

            if (is_array($loaded)) {
                return $loaded;
            }
        }

        $loaded = require $mainFile;

        return is_array($loaded) ? $loaded : [];
    }

    private static function shouldLoadViaPinker(string $config, string $mainFile): bool
    {
        if (in_array($config, self::DIRECT_LOAD_CONFIGS, true)) {
            return false;
        }

        $bakedFile = self::pinkerConfigPath($config . '.config.php');

        if ($bakedFile === $mainFile) {
            return false;
        }

        $stateFile = self::join(
            self::pathWithoutAlias('pinker', 'pinker'),
            'state/platform/' . $config . '.config.php',
        );
        $legacyStateFile = self::join(
            self::pathWithoutAlias('pinker', 'pinker'),
            'state/config/' . $config . '.config.php',
        );
        $legacyBakedFile = self::join(self::path('pinker'), 'config/' . $config . '.config.php');

        return is_file($stateFile)
            || is_file($legacyStateFile)
            || is_file($bakedFile)
            || is_file($legacyBakedFile)
            || \Pinoox\Component\Store\Baker\EnvSensitiveConfig::sourceUsesEnv($mainFile);
    }

    private static function pathWithoutAlias(string $key, string $default): string
    {
        $value = self::get('paths', $key, $default);
        $value = trim(str_replace('\\', '/', (string)$value));

        if ($value === '~') {
            return self::rootPath();
        }

        if (str_starts_with($value, '~/')) {
            return self::join(self::rootPath(), substr($value, 2));
        }

        if (str_starts_with($value, '~config')) {
            return self::join(self::configPath(), substr($value, strlen('~config')));
        }

        if (str_starts_with($value, '~system')) {
            return self::join(self::configPath(), substr($value, strlen('~system')));
        }

        if (str_starts_with($value, '~pincore')) {
            return self::join(self::corePath(), substr($value, strlen('~pincore')));
        }

        return self::resolveBasePath($value);
    }

    private static function resolveBasePath(mixed $path): string
    {
        $path = trim(str_replace('\\', '/', (string)$path));

        if ($path === '' || $path === '~') {
            return self::rootPath();
        }

        if (str_starts_with($path, '~/')) {
            return self::join(self::rootPath(), substr($path, 2));
        }

        if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return self::join(self::rootPath(), $path);
    }

    private static function join(string $basePath, string $path = ''): string
    {
        $basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? $basePath : $basePath . '/' . $path;
    }
}
