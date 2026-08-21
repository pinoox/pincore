<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use ZipArchive;

/**
 * Inspect a platform distribution .zip produced by pinx:build platform.
 */
final class PlatformArchive
{
    public const MANIFEST_ENTRY = 'storage/BUILD.json';

    public const PINX_MANIFEST_ENTRY = 'manifest.json';

    /**
     * Host paths that must not be replaced from a distribution zip.
     *
     * @return list<string>
     */
    public static function preservePrefixes(): array
    {
        return [
            '.env',
            'storage',
            'uploads',
            'downloads',
            'pinker',
            'pinx',
            'pinroll',
            '.pinoox',
            'packages',
            'pincore',
            'pingate.php',
            '.git',
            '.github',
            ...PlatformPinkerGuard::runtimeConfigFiles(),
        ];
    }

    /**
     * @param string|null $projectRoot When set, runtime configs (app-router, domain, apps)
     *                                 are seeded from the zip if the host file is missing.
     */
    public static function shouldPreserve(string $relativePath, ?string $projectRoot = null): bool
    {
        $relativePath = self::normalizeRelative($relativePath);

        if ($relativePath === '' || $relativePath === self::MANIFEST_ENTRY) {
            return false;
        }

        if ($relativePath === '.env' || str_starts_with($relativePath, '.env.')) {
            return true;
        }

        if (PlatformPinkerGuard::shouldPreserveRuntimeConfig($relativePath)) {
            if ($projectRoot === null || $projectRoot === '') {
                return true;
            }

            return PlatformPinkerGuard::hostHasRuntimeConfig($projectRoot, $relativePath);
        }

        foreach (self::preservePrefixes() as $prefix) {
            if ($prefix === '.env' || PlatformPinkerGuard::shouldPreserveRuntimeConfig($prefix)) {
                continue;
            }

            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readManifest(string $archivePath): array
    {
        $zip = self::open($archivePath);

        try {
            $entries = self::entryNames($zip);
            $prefix = self::archivePrefix($entries);
            $manifestEntry = $prefix . self::MANIFEST_ENTRY;
            $raw = $zip->getFromName($manifestEntry);

            if (!is_string($raw) || $raw === '') {
                return [
                    'prefix' => $prefix,
                ];
            }

            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                throw new Exception('Invalid platform BUILD.json in archive.');
            }

            $decoded['prefix'] = $prefix;

            return $decoded;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    public static function listApps(string $archivePath): array
    {
        $zip = self::open($archivePath);

        try {
            return self::appsFromEntries(self::entryNames($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    public static function appsFromEntries(array $entries): array
    {
        $prefix = self::archivePrefix($entries);
        $apps = [];

        foreach ($entries as $entry) {
            $relative = $prefix === '' ? $entry : substr($entry, strlen($prefix));
            $relative = self::normalizeRelative($relative);

            if (preg_match('#^apps/([^/]+)/app\\.php$#', $relative, $match) !== 1) {
                continue;
            }

            $package = trim((string) $match[1]);

            if ($package !== '') {
                $apps[] = $package;
            }
        }

        $apps = array_values(array_unique($apps));
        sort($apps);

        return $apps;
    }

    /**
     * Single wrapping folder (pinoox/…) when the zip was created from a directory.
     *
     * @param list<string> $entries
     */
    public static function archivePrefix(array $entries): string
    {
        $files = [];

        foreach ($entries as $entry) {
            $entry = self::normalizeRelative($entry);

            if ($entry === '' || str_ends_with($entry, '/')) {
                continue;
            }

            $files[] = $entry;
        }

        if ($files === []) {
            return '';
        }

        if (in_array('index.php', $files, true) || in_array(self::MANIFEST_ENTRY, $files, true)) {
            return '';
        }

        $slash = strpos($files[0], '/');

        if ($slash === false) {
            return '';
        }

        $root = substr($files[0], 0, $slash + 1);

        foreach ($files as $file) {
            if (!str_starts_with($file, $root)) {
                return '';
            }
        }

        $index = $root . 'index.php';
        $manifest = $root . self::MANIFEST_ENTRY;

        if (!in_array($index, $files, true) && !in_array($manifest, $files, true)) {
            return '';
        }

        return $root;
    }

    public static function isPinxPackageArchive(string $archivePath): bool
    {
        if (!is_file($archivePath)) {
            return false;
        }

        try {
            $zip = self::open($archivePath);
        } catch (\Throwable) {
            return false;
        }

        try {
            $entries = self::entryNames($zip);
            $prefix = self::archivePrefix($entries);

            foreach ([$prefix . self::PINX_MANIFEST_ENTRY, self::PINX_MANIFEST_ENTRY] as $entry) {
                $raw = $zip->getFromName($entry);

                if (!is_string($raw) || $raw === '') {
                    continue;
                }

                $decoded = json_decode($raw, true);

                if (!is_array($decoded)) {
                    continue;
                }

                if (($decoded['format'] ?? null) === PinxManifest::FORMAT) {
                    return true;
                }
            }

            foreach ($entries as $entry) {
                $relative = $prefix === '' ? $entry : substr($entry, strlen($prefix));
                $relative = self::normalizeRelative($relative);

                if (str_starts_with($relative, PinxManifest::PAYLOAD_PREFIX)) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    public static function isPlatformArchive(string $archivePath): bool
    {
        if (!is_file($archivePath)) {
            return false;
        }

        if (self::isPinxPackageArchive($archivePath)) {
            return false;
        }

        try {
            $manifest = self::readManifest($archivePath);
        } catch (\Throwable) {
            return false;
        }

        if (($manifest['type'] ?? null) === 'platform') {
            return true;
        }

        $zip = self::open($archivePath);

        try {
            $entries = self::entryNames($zip);
            $prefix = self::archivePrefix($entries);
            $hasIndex = $zip->locateName($prefix . 'index.php') !== false;
            $hasCore = $zip->locateName($prefix . 'vendor/autoload.php') !== false
                || $zip->locateName($prefix . 'vendor/pinoox/pincore/composer.json') !== false;

            return $hasIndex && $hasCore;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{name: string, code: ?int}
     */
    public static function versionFromManifest(array $manifest): array
    {
        $name = trim((string) ($manifest['version_name'] ?? ''));
        $rawCode = $manifest['version_code'] ?? null;
        $code = null;

        if ($rawCode !== null && $rawCode !== '') {
            $code = (int) $rawCode;
        }

        return [
            'name' => $name,
            'code' => $code,
        ];
    }

    public static function isNewerOrEqual(?int $incomingCode, ?int $installedCode): bool
    {
        if ($incomingCode === null || $installedCode === null) {
            return true;
        }

        return $incomingCode >= $installedCode;
    }

    private static function open(string $archivePath): ZipArchive
    {
        if (!class_exists(ZipArchive::class)) {
            throw new Exception('The ext-zip extension is required to update from a platform archive.');
        }

        if (!is_file($archivePath)) {
            throw new Exception('Platform archive not found: ' . $archivePath);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath);

        if ($opened !== true) {
            throw new Exception('Unable to open platform archive: ' . $archivePath);
        }

        return $zip;
    }

    /**
     * @return list<string>
     */
    private static function entryNames(ZipArchive $zip): array
    {
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (!is_string($name) || $name === '') {
                continue;
            }

            $entries[] = self::normalizeRelative($name);
        }

        return $entries;
    }

    public static function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        return ltrim($path, '/');
    }
}
