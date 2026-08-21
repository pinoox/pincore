<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Store\Config\ConfigInterface;
use Pinoox\Component\Template\Theme\ThemeManifest;
use Pinoox\Support\SystemConfig;

class PinxBuildConfig
{
    /**
     * Paths always excluded from app .pinx builds unless the app adds them to include overrides.
     *
     * @return list<string>
     */
    public static function defaultAppExcludes(): array
    {
        return [
            'tests',
            '.github',
            ...PinxPaths::buildExcludePatterns(),
            // Single-app host/runtime — platform already provides these.
            'platform',
            'platform/**',
            'storage',
            'storage/**',
            'composer.json',
            'composer.lock',
            '.env',
            '.env.example',
            '.gitignore',
            '.htaccess',
            'README.md',
            'index.php',
            'debug-pinx-files.php',
            'boot-test.php',
            'MAMP_php_error_log_MAMP',
            // Production packages ship built theme assets (dist/), not Vite source.
            'theme/*/src',
            'theme/*/src/**',
            'theme/*/package.json',
            'theme/*/package-lock.json',
            'theme/*/yarn.lock',
            'theme/*/pnpm-lock.yaml',
            'theme/*/vite.config.js',
            'theme/*/vite.config.ts',
            'theme/*/vite.config.mjs',
            'theme/*/vite.config.cjs',
            'theme/*/tsconfig.json',
            'theme/*/jsconfig.json',
        ];
    }

    /**
     * Force-include production theme builds even when dist/ is gitignored.
     *
     * @return list<string>
     */
    public static function defaultAppIncludes(): array
    {
        return [
            'theme/*/dist',
            'theme/*/dist/**',
        ];
    }

    /**
     * Paths always excluded from theme-only .pinx builds.
     *
     * @return list<string>
     */
    public static function defaultThemeExcludes(): array
    {
        return [
            'src',
            'src/**',
            'package.json',
            'package-lock.json',
            'yarn.lock',
            'pnpm-lock.yaml',
            'vite.config.js',
            'vite.config.ts',
            'vite.config.mjs',
            'vite.config.cjs',
            'tsconfig.json',
            'jsconfig.json',
            '.env',
            '.env.example',
            '.gitignore',
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultThemeIncludes(): array
    {
        return [
            'dist',
            'dist/**',
        ];
    }

    /**
     * @return array{
     *     type: string,
     *     target_app: string,
     *     theme_name: string,
     *     minpin: int,
     *     requirements: array<string, string>,
     *     gitignore: bool,
     *     exclude: list<string>,
     *     include: list<string>,
     *     include_themes: list<string>,
     *     composer: bool,
     *     output_dir: ?string,
     *     sign: array{enabled: bool, require_signature: bool, key_path: ?string, key_id: ?string}
     * }
     */
    public static function resolve(AppEngine $engine, string $package): array
    {
        $raw = self::rawAppConfig($engine, $package);
        $config = $engine->config($package);
        $pinx = is_array($raw['pinx'] ?? null) ? $raw['pinx'] : self::arrayValue($config, 'pinx');
        $build = array_key_exists('build', $raw) && is_array($raw['build'])
            ? $raw['build']
            : [];
        $sign = PinxSignConfig::app($pinx);

        $resolvedType = (string) ($pinx['type'] ?? PinxManifest::TYPE_APP);
        $packageName = (string) ($raw['package'] ?? $config->get('package', $package));
        $pathTheme = (string) ($raw['path-theme'] ?? $config->get('path-theme', 'theme'));
        $themeName = (string) ($pinx['theme_name'] ?? $raw['theme'] ?? $config->get('theme', 'default'));

        if ($resolvedType === PinxManifest::TYPE_THEME) {
            $themePath = rtrim(str_replace('\\', '/', $engine->path($packageName, $pathTheme . '/' . $themeName)), '/');
            $manifestFile = $themePath . '/' . ThemeManifest::FILE;

            if (!is_file($manifestFile)) {
                throw new \Pinoox\Component\Kernel\Exception(
                    'Theme pinx build requires theme.php at ' . $pathTheme . '/' . $themeName . '/theme.php',
                );
            }

            $themeManifest = ThemeManifest::fromPath($themePath, $packageName, $themeName);
            $themeManifest?->validate($packageName);
        }

        $type = in_array($resolvedType, [PinxManifest::TYPE_APP, PinxManifest::TYPE_THEME], true)
            ? $resolvedType
            : PinxManifest::TYPE_APP;

        $defaultExcludes = $type === PinxManifest::TYPE_APP
            ? self::defaultAppExcludes()
            : self::defaultThemeExcludes();
        $customExcludes = self::stringList($build['exclude'] ?? []);
        $exclude = array_values(array_unique(array_merge(
            $defaultExcludes,
            $customExcludes,
            [
                'pinx/keys/' . PinxSignKey::KEY_FILE,
                'pinx/' . PinxSignKey::KEY_FILE,
                '.pinx',
                '.pinx/*',
            ],
        )));

        $defaultIncludes = $type === PinxManifest::TYPE_APP
            ? self::defaultAppIncludes()
            : self::defaultThemeIncludes();
        $include = array_values(array_unique(array_merge(
            $defaultIncludes,
            self::stringList($build['include'] ?? []),
        )));

        $outputDir = trim((string) ($build['output_dir'] ?? ''));

        return [
            'type' => $type,
            'target_app' => (string) ($pinx['target_app'] ?? $packageName),
            'theme_name' => $themeName,
            'minpin' => (int) ($pinx['minpin'] ?? $raw['minpin'] ?? $config->get('minpin', 0)),
            'requirements' => PinxRequirements::normalize($pinx['requirements'] ?? []),
            'gitignore' => array_key_exists('gitignore', $build)
                ? (bool) $build['gitignore']
                : true,
            'exclude' => $exclude,
            'include' => $include,
            'include_themes' => self::stringList($build['include_themes'] ?? []),
            'composer' => array_key_exists('composer', $build)
                ? (bool) $build['composer']
                : true,
            'output_dir' => $outputDir !== '' ? SystemConfig::resolvePath($outputDir) : null,
            'sign' => $sign,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function appConfigArray(AppEngine $engine, string $package): array
    {
        return self::rawAppConfig($engine, $package);
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawAppConfig(AppEngine $engine, string $package): array
    {
        $appFile = $engine->path($package, 'app.php');
        if (!is_file($appFile)) {
            return [];
        }

        $data = include $appFile;

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayValue(ConfigInterface $config, string $key): array
    {
        $value = $config->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($item) => trim((string) $item), $value)));
    }
}

