<?php

namespace Pinoox\Support;

use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Package\Engine\EngineInterface;

/**
 * Resolve web-visible path prefixes for app assets from AppEngine layout.
 */
final class AppPublicPath
{
    /**
     * Public path from project root to an app root (e.g. apps/com_foo or empty for in-tree ~ apps).
     */
    public static function prefix(EngineInterface $engine, string $package, string $projectRoot): string
    {
        if ($package === '') {
            return self::appsDirectoryPrefix($projectRoot);
        }

        if (!$engine->exists($package)) {
            return self::join(self::appsDirectoryPrefix($projectRoot), $package);
        }

        try {
            $appRoot = $engine->path($package);
        } catch (\Throwable) {
            return self::join(self::appsDirectoryPrefix($projectRoot), $package);
        }

        return self::relativeToRoot($appRoot, $projectRoot)
            ?? self::join(self::appsDirectoryPrefix($projectRoot), $package);
    }

    /**
     * Find the registered package that owns a filesystem path (longest app-root match wins).
     */
    public static function packageForPath(EngineInterface $engine, string $filesystemPath, string $projectRoot): ?string
    {
        $filesystemPath = rtrim(str_replace('\\', '/', $filesystemPath), '/');
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($filesystemPath !== $projectRoot && !str_starts_with($filesystemPath, $projectRoot . '/')) {
            return null;
        }

        if ($engine instanceof AppEngine) {
            $matched = null;
            $matchedLength = -1;

            foreach ($engine->packagePaths() as $package => $appRoot) {
                $appRoot = rtrim(str_replace('\\', '/', (string) $appRoot), '/');

                if ($appRoot === '' || ($filesystemPath !== $appRoot && !str_starts_with($filesystemPath, $appRoot . '/'))) {
                    continue;
                }

                $length = strlen($appRoot);
                if ($length > $matchedLength) {
                    $matchedLength = $length;
                    $matched = $package;
                }
            }

            return $matched;
        }

        $relative = $filesystemPath === $projectRoot
            ? ''
            : ltrim(substr($filesystemPath, strlen($projectRoot)), '/');
        $appsPrefix = self::appsDirectoryPrefix($projectRoot);

        if (preg_match('#^' . preg_quote($appsPrefix, '#') . '/([^/]+)(?:/|$)#', $relative, $matches) === 1) {
            $package = $matches[1];

            return is_string($package) && $package !== '' ? $package : null;
        }

        return null;
    }

    public static function appsDirectoryPrefix(string $projectRoot): string
    {
        $appsRoot = rtrim(str_replace('\\', '/', SystemConfig::path('apps')), '/');
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($projectRoot !== '' && str_starts_with($appsRoot, $projectRoot . '/')) {
            return ltrim(substr($appsRoot, strlen($projectRoot)), '/');
        }

        return 'apps';
    }

    private static function relativeToRoot(string $path, string $root): ?string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if ($path === $root) {
            return '';
        }

        if (str_starts_with($path, $root . '/')) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return null;
    }

    private static function join(string ...$segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            $segment = trim(str_replace('\\', '/', $segment), '/');
            if ($segment !== '') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }
}
