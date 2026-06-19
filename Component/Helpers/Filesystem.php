<?php

namespace Pinoox\Component\Helpers;

/**
 * Cross-platform file removal with Windows lock/retry handling.
 */
final class Filesystem
{
    public static function removeFile(string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }

        self::makeWritable($path);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            if (self::unlinkSilently($path)) {
                return true;
            }

            if (!is_file($path)) {
                return true;
            }

            usleep(25_000 * ($attempt + 1));
            self::makeWritable($path);
        }

        return !is_file($path);
    }

    public static function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                self::removeEmptyDirectory($path);
            } else {
                self::removeFile($path);
            }
        }

        return self::removeEmptyDirectory($dir);
    }

    private static function removeEmptyDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        self::makeWritable($path);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            if (self::rmdirSilently($path)) {
                return true;
            }

            if (!is_dir($path)) {
                return true;
            }

            usleep(20_000 * ($attempt + 1));
        }

        return !is_dir($path);
    }

    private static function makeWritable(string $path): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        if (is_file($path) || is_dir($path)) {
            @chmod($path, is_dir($path) ? 0777 : 0666);
            clearstatcache(true, $path);
        }
    }

    private static function unlinkSilently(string $path): bool
    {
        $removed = false;

        set_error_handler(static function (int $severity) use (&$removed): bool {
            return $severity === E_WARNING;
        });

        try {
            $removed = @unlink($path);
        } finally {
            restore_error_handler();
        }

        return $removed;
    }

    private static function rmdirSilently(string $path): bool
    {
        $removed = false;

        set_error_handler(static function (int $severity) use (&$removed): bool {
            return $severity === E_WARNING;
        });

        try {
            $removed = @rmdir($path);
        } finally {
            restore_error_handler();
        }

        return $removed;
    }
}
