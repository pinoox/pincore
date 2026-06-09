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
}
