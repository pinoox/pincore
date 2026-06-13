<?php

namespace Pinoox\Support;

class AppPackagePath
{
    public static function configFile(string $package): ?string
    {
        $package = trim($package);

        if ($package === '') {
            return null;
        }

        if (class_exists(\Pinoox\Portal\App\AppEngine::class)) {
            try {
                if (\Pinoox\Portal\App\AppEngine::exists($package)) {
                    $file = rtrim(str_replace('\\', '/', \Pinoox\Portal\App\AppEngine::path($package)), '/')
                        . '/app.php';

                    if (is_file($file)) {
                        return $file;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $fallback = rtrim(str_replace('\\', '/', SystemConfig::path('apps')), '/')
            . '/' . $package . '/app.php';

        return is_file($fallback) ? $fallback : null;
    }

    public static function fromDataFile(string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        $file = str_replace('\\', '/', $file);
        $appsRoot = rtrim(str_replace('\\', '/', SystemConfig::path('apps')), '/');

        if (str_starts_with($file, $appsRoot . '/')) {
            $remainder = substr($file, strlen($appsRoot) + 1);

            if (preg_match('#^([^/]+)/(?:database/(?:seed|migrations)|patches)/#', $remainder, $matches) === 1) {
                return self::normalizePackageFolder($matches[1]);
            }
        }

        $root = rtrim(str_replace('\\', '/', SystemConfig::rootPath()), '/');
        $relative = str_starts_with($file, $root . '/')
            ? substr($file, strlen($root) + 1)
            : $file;

        if (preg_match('#^database/(?:seed|migrations)/#', $relative) === 1
            || preg_match('#^patches/#', $relative) === 1) {
            $fromRootApp = self::packageFromAppFile($root . '/app.php');

            if ($fromRootApp !== null) {
                return $fromRootApp;
            }
        }

        $corePath = rtrim(str_replace('\\', '/', SystemConfig::corePath()), '/');

        if (str_starts_with($file, $corePath . '/database/migrations/')
            || str_starts_with($file, $corePath . '/database/seed/')
            || str_starts_with($file, $corePath . '/patches/')) {
            return 'platform';
        }

        return self::packageFromAppFile(self::findAppFile(dirname($file)));
    }

    public static function fromTestsFile(string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        $file = str_replace('\\', '/', $file);
        $appsRoot = rtrim(str_replace('\\', '/', SystemConfig::path('apps')), '/');

        if (str_starts_with($file, $appsRoot . '/')) {
            $remainder = substr($file, strlen($appsRoot) + 1);

            if (preg_match('#^([^/]+)/tests/#', $remainder, $matches) === 1) {
                return $matches[1];
            }
        }

        $dir = dirname($file);

        while ($dir !== '' && $dir !== '.' && $dir !== '/') {
            if (basename($dir) === 'tests') {
                $appDir = dirname($dir);
                $appFile = $appDir . '/app.php';

                if (!is_file($appFile)) {
                    break;
                }

                $config = require $appFile;

                if (is_array($config) && is_string($config['package'] ?? null) && $config['package'] !== '') {
                    return $config['package'];
                }

                break;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    private static function normalizePackageFolder(string $folder): ?string
    {
        $folder = trim($folder);

        if ($folder === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $folder) !== 1) {
            return null;
        }

        return self::packageFromAppFile(self::configFile($folder)) ?? $folder;
    }

    private static function packageFromAppFile(?string $appFile): ?string
    {
        if ($appFile === null || !is_file($appFile)) {
            return null;
        }

        $config = require $appFile;

        if (!is_array($config)) {
            return null;
        }

        $package = $config['package'] ?? null;

        return is_string($package) && $package !== '' ? $package : null;
    }

    private static function findAppFile(string $startDir): ?string
    {
        $dir = rtrim(str_replace('\\', '/', $startDir), '/');
        $root = rtrim(str_replace('\\', '/', SystemConfig::rootPath()), '/');

        while ($dir !== '' && $dir !== '.' && $dir !== '/') {
            $appFile = $dir . '/app.php';

            if (is_file($appFile)) {
                return $appFile;
            }

            if ($dir === $root) {
                break;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }
}
