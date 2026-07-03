<?php

namespace Pinoox\Component\Template\Frontend;

/**
 * Keeps Vite HMR assets in a theme aligned with pincore (hot plugin, optional .env seed).
 */
final class FrontendDevSync
{
    private const HOT_BUNDLE_THEME = 'vite.pinoox.mjs';

    private const HOT_BUNDLE_CORE = 'resources/frontend/vite-pinoox.mjs';

    /**
     * @return array{pinoox_bundle: bool, env_seeded: bool, hot_path: string}
     */
    public static function sync(string $themePath, array $config, ?string $corePath = null): array
    {
        $themePath = rtrim(str_replace('\\', '/', $themePath), '/');
        $corePath = $corePath ?? self::resolveCorePath();

        return [
            'pinoox_bundle' => self::syncCoreFile($themePath, $corePath, $config, self::HOT_BUNDLE_CORE, self::HOT_BUNDLE_THEME),
            'env_seeded' => self::seedThemeEnv($themePath),
            'hot_path' => FrontendConfig::hotRelativePath($config),
        ];
    }

    public static function syncHotPlugin(string $themePath, ?string $corePath, array $config): bool
    {
        $corePath ??= self::resolveCorePath();

        return self::syncCoreFile($themePath, $corePath, $config, self::HOT_BUNDLE_CORE, self::HOT_BUNDLE_THEME);
    }

    private static function syncCoreFile(
        string $themePath,
        ?string $corePath,
        array $config,
        string $coreRelative,
        string $themeFile,
    ): bool {
        if (!FrontendConfig::usesViteAssets($config)) {
            return false;
        }

        $corePath ??= self::resolveCorePath();
        $source = $corePath . '/' . $coreRelative;

        if (!is_file($source)) {
            return false;
        }

        $target = $themePath . '/' . $themeFile;

        if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $source)) {
            return true;
        }

        return copy($source, $target);
    }

    public static function seedThemeEnv(string $themePath): bool
    {
        $env = $themePath . '/.env';
        $example = $themePath . '/.env.example';

        if (is_file($env) || !is_file($example)) {
            return false;
        }

        return copy($example, $env);
    }

    public static function removeHotFile(string $themePath, array $config): void
    {
        $hotFile = FrontendConfig::hotAbsolutePath($themePath, $config);

        if (is_file($hotFile)) {
            @unlink($hotFile);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function npmDevEnvironment(array $config, ?string $corePath = null, ?string $package = null): array
    {
        $env = [
            'PINOOX_HOT_FILE' => FrontendConfig::hotRelativePath($config),
            'PINOOX_CORE_PATH' => $corePath ?? self::resolveCorePath(),
            'VITE_DEV_PORT' => (string) FrontendConfig::devPort($config),
        ];

        $serverUrl = self::resolveAppServerUrl($package);

        if ($serverUrl !== null) {
            $env['VITE_SERVER_URL'] = $serverUrl;
        }

        $proxy = self::resolveDevProxyPrefixes($package);

        if ($proxy !== null) {
            $env['VITE_DEV_PROXY'] = $proxy;
        }

        return $env;
    }

    /**
     * Comma-separated mount paths for Vite dev proxy (app router + transport-linked apps).
     */
    private static function resolveDevProxyPrefixes(?string $package): ?string
    {
        if ($package === null || $package === '') {
            return null;
        }

        try {
            $paths = self::mountPathsForPackage($package);

            $transport = \Pinoox\Portal\App\AppEngine::config($package)->get('transport');

            if (is_array($transport)) {
                foreach (['auth_config', 'auth_cookie', 'user', 'session_token'] as $key) {
                    $linked = $transport[$key] ?? null;

                    if (!is_string($linked) || $linked === '' || $linked === 'platform') {
                        continue;
                    }

                    if (!\Pinoox\Portal\App\AppEngine::exists($linked)) {
                        continue;
                    }

                    $paths = array_merge($paths, self::mountPathsForPackage($linked));
                }
            }

            $paths = array_values(array_unique(array_filter($paths)));

            return $paths === [] ? null : implode(',', $paths);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function mountPathsForPackage(string $package): array
    {
        $routes = \Pinoox\Portal\App\AppRouter::getByPackage($package);
        $paths = [];

        foreach (array_keys($routes) as $routePath) {
            $normalized = '/' . trim((string) $routePath, '/');

            if ($normalized !== '/') {
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    private static function resolveAppServerUrl(?string $package): ?string
    {
        if ($package === null || $package === '') {
            return null;
        }

        try {
            $url = \Pinoox\Portal\Url::appUrl($package);

            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function resolveCorePath(): string
    {
        if (defined('PINOOX_CORE_PATH')) {
            return rtrim(str_replace('\\', '/', (string) PINOOX_CORE_PATH), '/');
        }

        return dirname(__DIR__, 3);
    }
}
