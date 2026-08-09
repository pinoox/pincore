<?php

namespace Pinoox\Component\Package\Lifecycle;

final class AppLifecyclePath
{
    /**
     * Resolve lifecycle.php like boot.php: true → lifecycle.php, false → skip, string → custom path.
     */
    public static function resolve(mixed $config, string $appRoot): ?string
    {
        if ($config === false) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $appRoot), '/');

        if (is_string($config) && $config !== '') {
            $path = $root . '/' . ltrim(str_replace('\\', '/', $config), '/');

            return is_file($path) ? $path : null;
        }

        $default = $root . '/lifecycle.php';

        return is_file($default) ? $default : null;
    }
}
