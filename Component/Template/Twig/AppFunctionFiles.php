<?php

namespace Pinoox\Component\Template\Twig;

use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;

final class AppFunctionFiles
{
    /**
     * App PHP function files for Twig (loader @ entries + func.php / functions.php).
     *
     * @return list<string>
     */
    public static function resolve(?string $package = null): array
    {
        $package ??= App::package();
        if (!is_string($package) || $package === '') {
            return [];
        }

        $files = [];
        $seen = [];

        $loader = App::get('loader');
        if (is_array($loader)) {
            foreach ($loader as $key => $path) {
                if (!is_string($key) || !str_starts_with($key, '@') || !is_string($path) || trim($path) === '') {
                    continue;
                }

                self::push($files, $seen, self::absolute($package, $path));
            }
        }

        foreach (['func.php', 'functions.php'] as $conventional) {
            self::push($files, $seen, self::absolute($package, $conventional));
        }

        return $files;
    }

    private static function absolute(string $package, string $relative): ?string
    {
        try {
            $path = AppEngine::path($package, ltrim(str_replace('\\', '/', $relative), '/'));
        } catch (\Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * @param list<string> $files
     * @param array<string, true> $seen
     */
    private static function push(array &$files, array &$seen, ?string $path): void
    {
        if ($path === null || isset($seen[$path])) {
            return;
        }

        $seen[$path] = true;
        $files[] = $path;
    }
}
