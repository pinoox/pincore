<?php

namespace Pinoox\Component\Package;

use Pinoox\Component\Kernel\Exception;

/**
 * Guardrails for build flows that bundle an existing Composer vendor tree.
 */
final class ComposerVendorGuard
{
    public static function vendorDir(string $basePath): string
    {
        return rtrim(str_replace('\\', '/', $basePath), '/') . '/vendor';
    }

    public static function isInstalled(string $basePath): bool
    {
        return is_file(self::vendorDir($basePath) . '/autoload.php');
    }

    public static function installCommand(): string
    {
        return 'composer install --no-dev --optimize-autoloader --no-interaction';
    }

    public static function requireInstalled(string $basePath, string $label = 'project'): void
    {
        if (self::isInstalled($basePath)) {
            return;
        }

        $path = rtrim(str_replace('\\', '/', $basePath), '/');

        throw new Exception(sprintf(
            "Composer vendor is not installed for this %s.\nRun the following in your terminal, then build again:\n\n  cd %s\n  %s",
            $label,
            $path,
            self::installCommand(),
        ));
    }

    public static function assertProductionVendor(string $basePath, string $label = 'project'): void
    {
        $composerFile = rtrim(str_replace('\\', '/', $basePath), '/') . '/composer.json';

        if (!is_file($composerFile)) {
            return;
        }

        $raw = file_get_contents($composerFile);
        $composer = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($composer)) {
            return;
        }

        $devPackages = array_keys($composer['require-dev'] ?? []);

        if ($devPackages === []) {
            return;
        }

        $installedFile = self::vendorDir($basePath) . '/composer/installed.json';

        if (!is_file($installedFile)) {
            return;
        }

        $installedRaw = file_get_contents($installedFile);
        $installed = is_string($installedRaw) ? json_decode($installedRaw, true) : null;

        if (!is_array($installed)) {
            return;
        }

        $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : $installed;
        $installedNames = [];

        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name'])) {
                $installedNames[] = (string) $package['name'];
            }
        }

        $found = array_values(array_intersect($devPackages, $installedNames));

        if ($found === []) {
            return;
        }

        $path = rtrim(str_replace('\\', '/', $basePath), '/');

        throw new Exception(sprintf(
            "Dev Composer packages are installed (%s) for this %s.\nRun the following in your terminal, then build again:\n\n  cd %s\n  %s",
            implode(', ', $found),
            $label,
            $path,
            self::installCommand(),
        ));
    }

    public static function copyVendorTree(string $sourceVendor, string $targetVendor): void
    {
        $sourceVendor = rtrim(str_replace('\\', '/', $sourceVendor), '/');
        $targetVendor = rtrim(str_replace('\\', '/', $targetVendor), '/');

        if (!is_file($sourceVendor . '/autoload.php')) {
            throw new Exception('Source vendor/autoload.php was not found: ' . $sourceVendor);
        }

        self::removeDirectory($targetVendor);

        if (!mkdir($targetVendor, 0777, true) && !is_dir($targetVendor)) {
            throw new Exception('Failed to create vendor directory: ' . $targetVendor);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceVendor, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $absolutePath = str_replace('\\', '/', $item->getPathname());
            $relativePath = ltrim(substr($absolutePath, strlen($sourceVendor)), '/');

            if ($relativePath === '' || str_ends_with($relativePath, '.gitignore')) {
                continue;
            }

            $targetPath = $targetVendor . '/' . $relativePath;

            if ($item->isDir() && !$item->isLink()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0777, true);
                }

                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            if (!copy($item->getPathname(), $targetPath)) {
                throw new Exception('Failed to copy vendor file: ' . $targetPath);
            }
        }
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
