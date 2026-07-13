<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Template\Theme\ThemeStack;
use Pinoox\Portal\App\AppEngine;

/**
 * Resolve theme directories from AppEngine (apps/, apps.config.php ~, external registry, …).
 */
final class ThemeFrontendPaths
{
    public static function appRoot(string $package): string
    {
        return rtrim(str_replace('\\', '/', AppEngine::path($package)), '/');
    }

    public static function themesRoot(string $package): string
    {
        $segment = ThemeStack::pathTheme($package);

        return rtrim(str_replace('\\', '/', AppEngine::path($package, $segment)), '/');
    }

    public static function themeDir(string $package, string $themeFolder): string
    {
        $folder = trim($themeFolder, '/');

        return $folder === ''
            ? self::themesRoot($package)
            : rtrim(self::themesRoot($package) . '/' . $folder, '/');
    }

    public static function themesRootLabel(string $package): string
    {
        $root = self::themesRoot($package);
        $projectRoot = rtrim(str_replace('\\', '/', (string) Loader::getBasePath()), '/');

        if ($projectRoot !== '' && str_starts_with($root, $projectRoot . '/')) {
            return ltrim(substr($root, strlen($projectRoot) + 1), '/');
        }

        return $root;
    }
}
