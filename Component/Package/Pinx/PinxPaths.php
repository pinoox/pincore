<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Support\SystemConfig;

final class PinxPaths
{
    public const WORKSPACE_DIR = 'pinx';

    public const KEYS_DIR = 'keys';

    public const EXPORT_DIR = 'export';

    public const KEY_FILE = 'sign.key.json';

    public const LEGACY_APP_WORKSPACE = 'pinx';

    public const LEGACY_KEY_RELATIVE = 'pinx/sign.key.json';

    public const LEGACY_APP_EXPORT = 'pinx/export';

    public const LEGACY_APP_RELEASES = 'pinx/releases';

    public const LEGACY_ROOT_EXPORT = 'export';

    public static function workspaceRoot(): string
    {
        return SystemConfig::path('pinx', '~/pinx');
    }

    public static function keysDir(string $package): string
    {
        return self::join(self::workspaceRoot(), self::KEYS_DIR, $package);
    }

    public static function exportDir(string $package): string
    {
        return self::join(self::workspaceRoot(), self::EXPORT_DIR, $package);
    }

    /**
     * @deprecated use exportDir()
     */
    public static function releasesDir(string $package): string
    {
        return self::exportDir($package);
    }

    public static function defaultKeyPath(string $package): string
    {
        return self::join(self::keysDir($package), self::KEY_FILE);
    }

    public static function defaultKeyRelative(string $package): string
    {
        return '~pinx/' . self::KEYS_DIR . '/' . $package . '/' . self::KEY_FILE;
    }

    public static function legacyAppWorkspaceDir(string $appPath): string
    {
        return self::join(self::normalize($appPath), self::LEGACY_APP_WORKSPACE);
    }

    public static function legacyAppKeysDir(string $appPath): string
    {
        return self::join(self::legacyAppWorkspaceDir($appPath), self::KEYS_DIR);
    }

    public static function legacyAppExportDir(string $appPath): string
    {
        return self::join(self::legacyAppWorkspaceDir($appPath), self::EXPORT_DIR);
    }

    public static function legacyAppReleasesDir(string $appPath): string
    {
        return self::join(self::legacyAppWorkspaceDir($appPath), 'releases');
    }

    public static function legacyKeyPath(string $appPath): string
    {
        return self::join(self::normalize($appPath), self::LEGACY_KEY_RELATIVE);
    }

    public static function legacyRootExportDir(string $appPath): string
    {
        return self::join(self::normalize($appPath), self::LEGACY_ROOT_EXPORT);
    }

    /**
     * @deprecated use legacyAppWorkspaceDir()
     */
    public static function workspaceDir(string $appPath): string
    {
        return self::legacyAppWorkspaceDir($appPath);
    }

    /**
     * @deprecated use legacyRootExportDir()
     */
    public static function legacyExportDir(string $appPath): string
    {
        return self::legacyRootExportDir($appPath);
    }

    /**
     * @deprecated use legacyAppReleasesDir()
     */
    public static function legacyReleasesDir(string $appPath): string
    {
        return self::legacyAppReleasesDir($appPath);
    }

    /**
     * Resolve an existing signing key or the preferred path for a new key.
     */
    public static function resolveKeyPath(string $package, string $appPath, ?string $configured = null): string
    {
        foreach (self::keyPathCandidates($package, $appPath, $configured) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (is_string($configured) && trim($configured) !== '') {
            return self::resolveConfiguredKeyPath($package, $appPath, $configured);
        }

        return self::defaultKeyPath($package);
    }

    /**
     * @return list<string>
     */
    public static function keyPathCandidates(string $package, string $appPath, ?string $configured = null): array
    {
        $candidates = [];

        if (is_string($configured) && trim($configured) !== '') {
            $candidates[] = self::resolveConfiguredKeyPath($package, $appPath, $configured);
        }

        $candidates[] = self::defaultKeyPath($package);
        $candidates[] = self::join(self::legacyAppKeysDir($appPath), self::KEY_FILE);
        $candidates[] = self::legacyKeyPath($appPath);

        $globalDir = SystemConfig::resolvePath(PinxSignConfig::system()['keys_path']);
        $candidates[] = rtrim($globalDir, '/\\') . '/' . $package . '.key.json';
        $candidates[] = rtrim($globalDir, '/\\') . '/' . $package . '/' . self::KEY_FILE;

        return array_values(array_unique($candidates));
    }

    public static function ensureKeysDir(string $package): string
    {
        $dir = self::keysDir($package);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function ensureExportDir(string $package): string
    {
        $dir = self::exportDir($package);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * @deprecated use ensureExportDir()
     */
    public static function ensureReleasesDir(string $package): string
    {
        return self::ensureExportDir($package);
    }

    public static function defaultReleaseFilename(string $package, PinxManifest $manifest): string
    {
        $suffix = $manifest->isTheme()
            ? $manifest->themeName() . '_theme'
            : $package;

        return $suffix . '_v' . $manifest->versionCode() . '_' . date('Ymd_His') . '.pinx';
    }

    public static function defaultReleasePath(string $package, PinxManifest $manifest): string
    {
        return self::ensureExportDir($package) . '/' . self::defaultReleaseFilename($package, $manifest);
    }

    public static function platformExportDir(): string
    {
        return self::join(self::workspaceRoot(), self::EXPORT_DIR, 'platform');
    }

    public static function ensurePlatformExportDir(): string
    {
        $dir = self::platformExportDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    public static function defaultPlatformReleaseFilename(): string
    {
        $version = PinxVersion::platform();
        $name = trim((string) ($version['name'] ?? ''));
        $code = (int) ($version['code'] ?? 0);
        $label = $name !== '' ? str_replace('.', '_', $name) : 'platform';

        return 'pinoox_' . $label . '_v' . $code . '_' . date('Ymd_His') . '.zip';
    }

    public static function defaultPlatformReleasePath(): string
    {
        return self::ensurePlatformExportDir() . '/' . self::defaultPlatformReleaseFilename();
    }

    /**
     * @return list<string>
     */
    public static function buildExcludePatterns(): array
    {
        return [
            self::LEGACY_APP_WORKSPACE,
            self::LEGACY_APP_WORKSPACE . '/*',
            self::LEGACY_ROOT_EXPORT,
            self::LEGACY_ROOT_EXPORT . '/*',
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
    public static function collectReleaseFiles(string $package, string $appPath): array
    {
        $files = [];
        $patterns = ['*.pinx', '*.zip', '*.json'];

        foreach ([
            self::exportDir($package),
            self::legacyAppExportDir($appPath),
            self::legacyAppReleasesDir($appPath),
            self::legacyRootExportDir($appPath),
        ] as $directory) {
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

    private static function resolveConfiguredKeyPath(string $package, string $appPath, string $configured): string
    {
        $configured = trim(str_replace('\\', '/', $configured));

        if (str_starts_with($configured, '~')) {
            $resolved = SystemConfig::resolvePath($configured);

            return str_replace('{package}', $package, $resolved);
        }

        if (preg_match('/^[A-Za-z]:\//', $configured) === 1 || str_starts_with($configured, '/')) {
            return $configured;
        }

        return self::join(self::normalize($appPath), $configured);
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function join(string ...$parts): string
    {
        $normalized = [];

        foreach ($parts as $part) {
            $part = trim(str_replace('\\', '/', $part), '/');
            if ($part !== '') {
                $normalized[] = $part;
            }
        }

        return implode('/', $normalized);
    }
}
