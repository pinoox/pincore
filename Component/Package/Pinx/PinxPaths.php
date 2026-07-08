<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Support\SystemConfig;

final class PinxPaths
{
    public const WORKSPACE_DIR = 'pinx';

    public const KEYS_DIR = 'pinx/keys';

    public const EXPORT_DIR = 'pinx/export';

    public const LEGACY_RELEASES_DIR = 'pinx/releases';

    public const KEY_FILE = 'sign.key.json';

    public const LEGACY_KEY_RELATIVE = 'pinx/sign.key.json';

    public const LEGACY_EXPORT_DIR = 'export';

    public static function workspaceDir(string $appPath): string
    {
        return rtrim(str_replace('\\', '/', $appPath), '/') . '/' . self::WORKSPACE_DIR;
    }

    public static function keysDir(string $appPath): string
    {
        return self::workspaceDir($appPath) . '/keys';
    }

    public static function exportDir(string $appPath): string
    {
        return self::workspaceDir($appPath) . '/export';
    }

    /**
     * @deprecated use exportDir()
     */
    public static function releasesDir(string $appPath): string
    {
        return self::exportDir($appPath);
    }

    public static function legacyReleasesDir(string $appPath): string
    {
        return self::workspaceDir($appPath) . '/releases';
    }

    public static function defaultKeyPath(string $appPath): string
    {
        return self::keysDir($appPath) . '/' . self::KEY_FILE;
    }

    public static function legacyKeyPath(string $appPath): string
    {
        return rtrim(str_replace('\\', '/', $appPath), '/') . '/' . self::LEGACY_KEY_RELATIVE;
    }

    public static function legacyExportDir(string $appPath): string
    {
        return rtrim(str_replace('\\', '/', $appPath), '/') . '/' . self::LEGACY_EXPORT_DIR;
    }

    public static function defaultKeyRelative(): string
    {
        return self::KEYS_DIR . '/' . self::KEY_FILE;
    }

    /**
     * Resolve an existing signing key or the preferred path for a new key.
     */
    public static function resolveKeyPath(string $appPath, ?string $configured = null): string
    {
        if (is_string($configured) && trim($configured) !== '') {
            $configured = trim(str_replace('\\', '/', $configured));
            $absolute = str_starts_with($configured, '/')
                || preg_match('/^[A-Za-z]:\//', $configured) === 1
                ? $configured
                : rtrim(str_replace('\\', '/', $appPath), '/') . '/' . ltrim($configured, '/');

            if (is_file($absolute)) {
                return $absolute;
            }
        }

        foreach ([self::defaultKeyPath($appPath), self::legacyKeyPath($appPath)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $globalDir = SystemConfig::resolvePath(PinxSignConfig::system()['keys_path']);
        $package = basename(rtrim(str_replace('\\', '/', $appPath), '/'));

        return rtrim($globalDir, '/\\') . '/' . $package . '.key.json';
    }

    public static function ensureKeysDir(string $appPath): string
    {
        $dir = self::keysDir($appPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function ensureExportDir(string $appPath): string
    {
        $dir = self::exportDir($appPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * @deprecated use ensureExportDir()
     */
    public static function ensureReleasesDir(string $appPath): string
    {
        return self::ensureExportDir($appPath);
    }

    public static function defaultReleaseFilename(string $package, PinxManifest $manifest): string
    {
        $suffix = $manifest->isTheme()
            ? $manifest->themeName() . '_theme'
            : $package;

        return $suffix . '_v' . $manifest->versionCode() . '_' . date('Ymd_His') . '.pinx';
    }

    public static function defaultReleasePath(string $appPath, string $package, PinxManifest $manifest): string
    {
        return self::ensureExportDir($appPath) . '/' . self::defaultReleaseFilename($package, $manifest);
    }

    /**
     * @return list<string>
     */
    public static function buildExcludePatterns(): array
    {
        return [
            self::WORKSPACE_DIR,
            self::WORKSPACE_DIR . '/*',
            self::LEGACY_EXPORT_DIR,
            self::LEGACY_EXPORT_DIR . '/*',
        ];
    }

    /**
     * Directory names excluded at any depth during pinx file selection.
     *
     * @return list<string>
     */
    public static function directoryExcludes(): array
    {
        return [
            'node_modules',
            'vendor',
            'pinker',
            'storage',
            'pinx',
            'export',
            '.pinx-build',
            'tests',
            '.github',
            'bin',
            'launcher',
            'platform',
            '.git',
            '.idea',
            '.vscode',
            '.phpunit.cache',
            'coverage',
        ];
    }

    /**
     * @return list<string>
     */
    public static function collectReleaseFiles(string $appPath): array
    {
        $files = [];
        $patterns = ['*.pinx', '*.zip', '*.json'];

        foreach ([self::exportDir($appPath), self::legacyReleasesDir($appPath), self::legacyExportDir($appPath)] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                foreach (glob($directory . '/' . $pattern) ?: [] as $file) {
                    if (is_file($file)) {
                        $files[] = $file;
                    }
                }
            }
        }

        $files = array_values(array_unique($files));
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }
}
