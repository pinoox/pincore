<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Portal\App\App;

class FrontendConfig
{
    public const STACK_TWIG = 'twig';

    public const STACK_VITE = 'vite';

    public const STACK_VUE = 'vue';

    public const STACK_REACT = 'react';

    /** @deprecated Legacy webpack/mix builds — use vite/vue/react + vite_tags() instead. */
    public const STACK_WEBPACK = 'webpack';

    public const VITE_MANIFEST = 'dist/.vite/manifest.json';

    public const WEBPACK_MANIFEST = 'dist/mix-manifest.json';

    /**
     * @return array<string, mixed>
     */
    public static function forThemePath(string $themePath): array
    {
        $themePath = rtrim(str_replace('\\', '/', $themePath), '/');
        $file = $themePath . '/frontend.config.php';

        if (is_file($file)) {
            $config = require $file;

            return self::normalize(is_array($config) ? $config : [], $themePath);
        }

        return self::normalize([], $themePath);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function normalize(array $overrides, string $themePath): array
    {
        $detected = self::detectStack($themePath);

        $config = array_replace_recursive([
            'stack' => $detected,
            'ssr' => [
                'enabled' => false,
                'mode' => 'hybrid',
                'strategy' => ThemeSsr::STRATEGY_AUTO,
                'fragment' => 'dist/ssr/app.html',
                'meta' => 'dist/ssr/meta.json',
                'server' => 'dist/server/entry-server.mjs',
                'fallback' => ThemeSsr::FALLBACK_CSR,
                'node' => null,
            ],
            'seo' => [
                'defaults' => [],
            ],
        ], $overrides);

        try {
            if (self::themePathBelongsToActiveApp($themePath)) {
                $appFrontend = App::get('frontend');
                if (is_array($appFrontend)) {
                    $appFrontend = self::filterNullValues($appFrontend);
                    $config = array_replace_recursive($config, $appFrontend);
                }
            }
        } catch (\Throwable) {
        }

        if (empty($config['stack'])) {
            $config['stack'] = $detected;
        }

        return self::applyStackDefaults($config, $themePath);
    }

    /**
     * Vite-powered stacks render assets with vite_tags() / vite_js_tags() — not webpack mix-manifest.
     *
     * @param array<string, mixed> $config
     */
    public static function usesViteAssets(array $config): bool
    {
        return in_array(strtolower((string) ($config['stack'] ?? self::STACK_TWIG)), [
            self::STACK_VITE,
            self::STACK_VUE,
            self::STACK_REACT,
            'nuxt',
            'next',
        ], true);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function usesLegacyWebpack(array $config): bool
    {
        return strtolower((string) ($config['stack'] ?? '')) === self::STACK_WEBPACK;
    }

    /**
     * Relative path to the build manifest from the theme root, or null for twig-only themes.
     *
     * @param array<string, mixed> $config
     */
    public static function manifestRelativePath(array $config): ?string
    {
        if (self::usesViteAssets($config)) {
            $manifest = $config['manifest'] ?? null;

            return is_string($manifest) && $manifest !== '' ? $manifest : self::VITE_MANIFEST;
        }

        if (self::usesLegacyWebpack($config)) {
            $manifest = $config['manifest'] ?? null;

            return is_string($manifest) && $manifest !== '' ? $manifest : self::WEBPACK_MANIFEST;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function manifestAbsolutePath(string $themePath, array $config): ?string
    {
        $relative = self::manifestRelativePath($config);

        if ($relative === null) {
            return null;
        }

        return rtrim(str_replace('\\', '/', $themePath), '/') . '/' . ltrim($relative, '/');
    }

    /**
     * Load the Vite manifest only (never webpack mix-manifest).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function loadViteManifest(string $themePath, array $config): array
    {
        if (!self::usesViteAssets($config)) {
            return [];
        }

        $path = self::manifestAbsolutePath($themePath, $config);

        if ($path === null || !is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function applyStackDefaults(array $config, string $themePath): array
    {
        $stack = (string) ($config['stack'] ?? self::detectStack($themePath));

        if (self::usesViteAssets(['stack' => $stack])) {
            $config['entry'] ??= self::defaultEntry($stack);
            $config['manifest'] ??= self::VITE_MANIFEST;
            $config['mount'] ??= '#app';
            $config['pinoox'] ??= 'pinoox';
            $config['dev'] = array_replace([
                'enabled' => (bool) _env('VITE_DEV', false),
                'url' => rtrim((string) _env('VITE_DEV_SERVER', 'http://127.0.0.1:5173'), '/'),
            ], is_array($config['dev'] ?? null) ? $config['dev'] : []);
        } elseif (self::usesLegacyWebpack(['stack' => $stack])) {
            $config['manifest'] ??= self::WEBPACK_MANIFEST;
            $config['entry'] ??= 'dist/pinoox.js';
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function filterNullValues(array $values): array
    {
        $filtered = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $filtered[$key] = is_array($value) ? self::filterNullValues($value) : $value;
        }

        return $filtered;
    }

    public static function detectStack(string $themePath): string
    {
        $packageFile = $themePath . '/package.json';
        if (!is_file($packageFile)) {
            return 'twig';
        }

        $package = json_decode((string) file_get_contents($packageFile), true);
        if (!is_array($package)) {
            return 'twig';
        }

        $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

        if (isset($deps['nuxt'])) {
            return 'nuxt';
        }

        if (isset($deps['next'])) {
            return 'next';
        }

        if (isset($deps['react']) || isset($deps['react-dom'])) {
            return 'react';
        }

        if (isset($deps['vue'])) {
            return 'vue';
        }

        if (isset($deps['vite'])) {
            return 'vite';
        }

        return 'twig';
    }

    private static function themePathBelongsToActiveApp(string $themePath): bool
    {
        try {
            $appThemeRoot = rtrim(str_replace('\\', '/', App::path('theme')), '/');
            $themePath = rtrim(str_replace('\\', '/', $themePath), '/');

            return $appThemeRoot !== '' && str_starts_with($themePath, $appThemeRoot);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function defaultEntry(string $stack): string
    {
        return match ($stack) {
            'react' => 'src/main.jsx',
            'next' => 'src/app/page.tsx',
            'nuxt' => 'src/main.js',
            default => 'src/main.js',
        };
    }

    /**
     * Default stack when creating a new theme (npm + vite_tags scaffold).
     */
    public static function defaultStackForNewTheme(): string
    {
        return self::STACK_VUE;
    }

    /**
     * Simple Twig / CLI hints for the active stack (prefer vite_tags for Vite stacks).
     *
     * @param array<string, mixed> $config
     * @return array{twig: string, assets_hint: string|null, next_steps: list<string>}
     */
    public static function recommendations(array $config, string $package = '', string $themeName = 'default'): array
    {
        if (!self::usesViteAssets($config)) {
            return [
                'twig' => "{{ assets('assets/app.css') }}",
                'assets_hint' => null,
                'next_steps' => [],
            ];
        }

        $entry = (string) ($config['entry'] ?? self::defaultEntry((string) ($config['stack'] ?? self::STACK_VITE)));

        $next = [];
        if ($package !== '') {
            $next[] = 'php pinoox fe ' . $package . ' install --theme=' . $themeName;
            $next[] = 'php pinoox fe ' . $package . ' dev --theme=' . $themeName;
        }

        return [
            'twig' => "{{ vite_tags('" . $entry . "')|raw }}",
            'assets_hint' => 'Optional: vite_css_tags in head + vite_js_tags before </body> for stricter HTML.',
            'next_steps' => $next,
        ];
    }

    public static function isDevEnabled(array $config): bool
    {
        return !empty($config['dev']['enabled']);
    }

    public static function isSsrEnabled(array $config): bool
    {
        return ThemeSsr::isEnabled($config);
    }
}

