<?php

namespace Pinoox\Component\Cache;

class AppCacheFingerprint
{
    /**
     * @param list<string> $files
     */
    public static function files(array $files): string
    {
        $files = array_values(array_unique(array_filter($files, static fn ($file) => is_string($file) && is_file($file))));
        sort($files);

        $parts = [];
        foreach ($files as $file) {
            $parts[] = str_replace('\\', '/', $file) . ':' . (filemtime($file) ?: 0) . ':' . (filesize($file) ?: 0);
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @param list<string> $files
     */
    public static function isFresh(string $package, string $store, array $files): bool
    {
        $storePath = AppCachePath::store($package, $store);
        if (!PhpCacheFile::exists($storePath)) {
            return false;
        }

        $meta = AppCacheManifest::storeMeta($package, $store);
        if ($meta === null) {
            return false;
        }

        // In production mode, pre-baked caches are trusted without scanning disk for sha1 checksums on every request
        if (AppCacheConfig::resolve($package)['mode'] === AppCacheConfig::MODE_PRODUCTION) {
            return true;
        }

        $checksum = self::files($files);

        return ($meta['checksum'] ?? '') === $checksum;
    }
}

