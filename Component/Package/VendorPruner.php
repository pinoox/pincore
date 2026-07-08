<?php

namespace Pinoox\Component\Package;

/**
 * Remove non-runtime files from a bundled Composer vendor tree.
 */
final class VendorPruner
{
    /** @var list<string> */
    public const DIRECTORY_NAMES = [
        'tests',
        'test',
        'docs',
        'doc',
        '.github',
        '.gitlab',
    ];

    public static function shouldSkipPath(string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '' || str_starts_with($relativePath, 'composer/')) {
            return false;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if (self::isPrunableDirectoryName($segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int number of removed directory entries
     */
    public static function prune(string $vendorDir): int
    {
        $vendorDir = rtrim(str_replace('\\', '/', $vendorDir), '/');

        if (!is_dir($vendorDir)) {
            return 0;
        }

        $removed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($vendorDir)), '/');

            if (!self::shouldSkipPath($relativePath)) {
                continue;
            }

            if (!self::isPrunableDirectoryName($item->getFilename())) {
                continue;
            }

            self::removeDirectory($absolutePath);
            $removed++;
        }

        return $removed;
    }

    private static function isPrunableDirectoryName(string $name): bool
    {
        foreach (self::DIRECTORY_NAMES as $directoryName) {
            if (str_starts_with($directoryName, '.')) {
                if ($name === $directoryName) {
                    return true;
                }

                continue;
            }

            if (strcasecmp($name, $directoryName) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath) && !is_link($fullPath)) {
                self::removeDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
