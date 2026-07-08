<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Package\AppComposerVendor;
use Pinoox\Support\SystemConfig;

final class PlatformBuildConfig
{
    public const CONFIG_FILE = 'build.config.php';

    public const BUILD_DIR = 'storage/.platform-build';

    public const STAGING_DIR = 'storage/.platform-build/staging';

    public const SKELETON_DIR = 'storage/.platform-build/skeleton';

    public static function buildPath(string $projectRoot, string $relative = ''): string
    {
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($relative === '') {
            return $root . '/' . self::BUILD_DIR;
        }

        return $root . '/' . trim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * Paths always excluded from platform .zip builds unless overridden in build.config.php.
     *
     * @return list<string>
     */
    public static function defaultExcludes(): array
    {
        return [
            'pincore',
            'packages',
            'pinx',
            'pinker',
            'uploads',
            'downloads',
            'storage',
            AppComposerVendor::BUILD_DIR,
            '.env',
            'phpunit.xml',
            '.phpunit.result.cache',
            'MAMP_php_error_log_MAMP',
            'sample-dev-app.pinx',
            ...PinxPaths::buildExcludePatterns(),
        ];
    }

    /**
     * Directory names excluded at any depth during platform archive selection.
     *
     * @return list<string>
     */
    public static function directoryExcludes(): array
    {
        return [
            'node_modules',
            'vendor',
            'pinker',
            'pinx',
            'export',
            '.platform-build',
            AppComposerVendor::BUILD_DIR,
            'tests',
            '.github',
            '.git',
            '.idea',
            '.vscode',
            '.cursor',
            '.phpunit.cache',
            'coverage',
            'pincore',
            'packages',
            'uploads',
            'downloads',
        ];
    }

    /**
     * @return array{
     *     gitignore: bool,
     *     exclude: list<string>,
     *     include: list<string>,
     *     composer: bool,
     *     app_composer: bool,
     *     exclude_theme_src: bool,
     *     strip_require_dev: bool,
     *     vendor_prune: bool,
     *     output_dir: ?string,
     *     manifest: bool
     * }
     */
    public static function resolve(?string $projectRoot = null): array
    {
        $projectRoot ??= SystemConfig::rootPath();
        $raw = self::rawConfig($projectRoot);

        $customExcludes = self::stringList($raw['exclude'] ?? []);
        $exclude = array_values(array_unique(array_merge(
            self::defaultExcludes(),
            $customExcludes,
        )));

        $outputDir = trim((string) ($raw['output_dir'] ?? ''));

        return [
            'gitignore' => array_key_exists('gitignore', $raw)
                ? (bool) $raw['gitignore']
                : true,
            'exclude' => $exclude,
            'include' => self::stringList($raw['include'] ?? []),
            'composer' => array_key_exists('composer', $raw)
                ? (bool) $raw['composer']
                : true,
            'app_composer' => array_key_exists('app_composer', $raw)
                ? (bool) $raw['app_composer']
                : true,
            'exclude_theme_src' => array_key_exists('exclude_theme_src', $raw)
                ? (bool) $raw['exclude_theme_src']
                : true,
            'strip_require_dev' => array_key_exists('strip_require_dev', $raw)
                ? (bool) $raw['strip_require_dev']
                : true,
            'vendor_prune' => array_key_exists('vendor_prune', $raw)
                ? (bool) $raw['vendor_prune']
                : true,
            'output_dir' => $outputDir !== '' ? SystemConfig::resolvePath($outputDir) : null,
            'manifest' => array_key_exists('manifest', $raw)
                ? (bool) $raw['manifest']
                : true,
        ];
    }

    public static function configFile(?string $projectRoot = null): string
    {
        $projectRoot ??= SystemConfig::rootPath();

        return rtrim(str_replace('\\', '/', $projectRoot), '/')
            . '/platform/'
            . self::CONFIG_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rawConfig(?string $projectRoot = null): array
    {
        $file = self::configFile($projectRoot);

        if (!is_file($file)) {
            return [];
        }

        $data = include $file;

        return is_array($data) ? $data : [];
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
