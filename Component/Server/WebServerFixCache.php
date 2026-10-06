<?php

namespace Pinoox\Component\Server;

use Pinoox\Component\Cache\AppCachePath;
use Pinoox\Component\Cache\PhpCacheFile;
use Pinoox\Support\SystemConfig;

final class WebServerFixCache
{
    private const STORE = 'web_server_fix';

    /** @var array<string, list<array{relative: string, name: string, full?: string|null}>> */
    private static array $runtimeCache = [];

    public static function resetRuntimeCache(): void
    {
        self::$runtimeCache = [];
    }

    public static function path(string $package): string
    {
        return AppCachePath::store($package, self::STORE);
    }

    /**
     * @return list<array{relative: string, name: string, full?: string}>
     */
    public static function load(string $package): array
    {
        if (isset(self::$runtimeCache[$package])) {
            return self::$runtimeCache[$package];
        }

        $data = PhpCacheFile::read(self::path($package));

        if (!is_array($data)) {
            return self::$runtimeCache[$package] = [];
        }

        $paths = $data['paths'] ?? $data;

        if (!is_array($paths)) {
            return self::$runtimeCache[$package] = [];
        }

        $entries = [];

        foreach ($paths as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $relative = $entry['relative'] ?? null;

            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $entries[] = [
                'relative' => WebServerFix::normalizePath($relative),
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : '',
                'full' => is_string($entry['full'] ?? null) ? WebServerFix::normalizePath($entry['full']) : null,
            ];
        }

        return self::$runtimeCache[$package] = $entries;
    }

    /**
     * @param list<array{relative: string, name: string, full?: string|null}> $entries
     */
    public static function save(string $package, array $entries): void
    {
        AppCachePath::ensureDir($package);

        self::$runtimeCache[$package] = array_values($entries);

        PhpCacheFile::write(self::path($package), [
            'paths' => array_values($entries),
        ]);

        WebServerFix::resetResolvedPaths();
    }

    /**
     * @param list<array{relative: string, name: string, full?: string|null}> $entries
     */
    public static function merge(string $package, array $entries): void
    {
        $existing = [];

        foreach (self::load($package) as $entry) {
            $existing[$entry['relative']] = $entry;
        }

        foreach ($entries as $entry) {
            $relative = WebServerFix::normalizePath($entry['relative']);
            $existing[$relative] = [
                'relative' => $relative,
                'name' => $entry['name'] ?? ($existing[$relative]['name'] ?? ''),
                'full' => $entry['full'] ?? ($existing[$relative]['full'] ?? null),
            ];
        }

        self::save($package, array_values($existing));
    }

    public static function hasRelativePath(string $package, string $path): bool
    {
        $normalized = WebServerFix::normalizePath($path);

        foreach (self::load($package) as $entry) {
            $relative = is_array($entry) ? ($entry['relative'] ?? null) : null;
            if (is_string($relative) && WebServerFix::normalizePath($relative) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function packagesWithRelativePath(string $path): array
    {
        $normalized = WebServerFix::normalizePath($path);
        $packages = [];

        foreach (self::packagesWithCache() as $package) {
            if (self::hasRelativePath($package, $normalized)) {
                $packages[$package] = $package;
            }
        }

        return array_values($packages);
    }

    /**
     * @return list<string>
     */
    public static function allRelativePaths(): array
    {
        $paths = [];

        foreach (self::packagesWithCache() as $package) {
            foreach (self::load($package) as $entry) {
                $paths[$entry['relative']] = $entry['relative'];
            }
        }

        return array_values($paths);
    }

    /**
     * @return list<string>
     */
    private static function packagesWithCache(): array
    {
        $packages = [];

        $roots = [
            rtrim(str_replace('\\', '/', SystemConfig::path('pinker')), '/') . '/bake/apps',
            rtrim(str_replace('\\', '/', SystemConfig::path('pinker')), '/') . '/apps',
        ];

        foreach ($roots as $appsRoot) {
            if (is_dir($appsRoot)) {
                foreach (scandir($appsRoot) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    if (PhpCacheFile::exists(self::path($entry))) {
                        $packages[$entry] = $entry;
                    }
                }
            }
        }

        foreach (WebServerFix::routerMap() as $package) {
            if (is_string($package) && $package !== '') {
                $packages[$package] = $package;
            }
        }

        return array_values($packages);
    }
}
