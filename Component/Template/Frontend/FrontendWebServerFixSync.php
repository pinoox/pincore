<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Server\WebServerFix;
use Pinoox\Component\Server\WebServerFixCache;

/**
 * Register hashed Vite build assets for WebServerFix (subfolder / rewrite installs).
 *
 * Vite/vue/react themes load assets via vite_tags() — never sync legacy webpack mix-manifest paths.
 */
final class FrontendWebServerFixSync
{
    /**
     * @param array<string, mixed> $config
     */
    public static function syncFromThemeConfig(string $package, string $themePath, array $config): void
    {
        if (!FrontendConfig::usesViteAssets($config)) {
            return;
        }

        $manifest = FrontendConfig::loadViteManifest($themePath, $config);

        if ($manifest === []) {
            return;
        }

        $manifestRel = FrontendConfig::manifestRelativePath($config) ?? FrontendConfig::VITE_MANIFEST;
        $distRoot = self::distRootFromManifest($manifestRel);
        $entries = [];

        foreach ($manifest as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['file']) && is_string($item['file'])) {
                $entries[] = self::entry($distRoot, $item['file']);
            }

            foreach ($item['css'] ?? [] as $css) {
                if (is_string($css) && $css !== '') {
                    $entries[] = self::entry($distRoot, $css);
                }
            }
        }

        if ($entries === []) {
            return;
        }

        WebServerFixCache::merge($package, $entries);
        WebServerFix::resetResolvedPaths();
    }

    private static function distRootFromManifest(string $manifestRel): string
    {
        $parts = explode('/', trim(str_replace('\\', '/', $manifestRel), '/'));

        return $parts[0] ?? 'dist';
    }

    /**
     * @return array{relative: string, name: string}
     */
    private static function entry(string $distRoot, string $file): array
    {
        return [
            'relative' => WebServerFix::normalizePath('/' . $distRoot . '/' . ltrim(str_replace('\\', '/', $file), '/')),
            'name' => 'frontend:' . basename($file),
        ];
    }
}
