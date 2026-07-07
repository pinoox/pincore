<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Component\Server\ServerPort;
use Pinoox\Portal\App\App;
use Pinoox\Support\ProjectCli;

class FrontendConfig
{
    public const STACK_TWIG = 'twig';

    public const STACK_VITE = 'vite';

    public const STACK_VUE = 'vue';

    public const STACK_REACT = 'react';

    /** @deprecated Legacy webpack/mix builds — use vite/vue/react + vite_tags() instead. */
    public const STACK_WEBPACK = 'webpack';

    public const VITE_MANIFEST = 'dist/.vite/manifest.json';

    /** Default path (relative to theme/) where Vite writes the dev-server URL for HMR. */
    public const DEFAULT_HOT_FILE = 'dist/hot';

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
            $config['entries'] ??= [self::defaultEntry($stack)];
            $config['refresh'] ??= self::defaultRefreshPaths();
            $config['manifest'] ??= self::VITE_MANIFEST;
            $config['mount'] ??= '#app';
            $config['pinoox'] ??= 'pinoox';
            $config['dev'] = array_replace([
                'enabled' => self::isDevFlagActive(),
                'url' => rtrim((string) _env('VITE_DEV_SERVER', ''), '/'),
                'hot' => self::resolveHotPathFromEnv(),
                'port' => self::readRawDevPort($themePath) ?? self::readEnvDevPort(),
                'prefer_manifest' => self::envBool('VITE_PREFER_MANIFEST', false),
                'force' => self::envBool('VITE_DEV_FORCE', false),
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
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function entries(array $config): array
    {
        if (!empty($config['entries']) && is_array($config['entries'])) {
            $entries = array_values(array_filter(
                $config['entries'],
                static fn ($entry): bool => is_string($entry) && trim($entry) !== '',
            ));

            if ($entries !== []) {
                return $entries;
            }
        }

        $entry = $config['entry'] ?? null;

        if (is_string($entry) && trim($entry) !== '') {
            return [ltrim(str_replace('\\', '/', trim($entry)), '/')];
        }

        $stack = (string) ($config['stack'] ?? self::STACK_VITE);

        return [match ($stack) {
            'react' => 'src/main.jsx',
            'next' => 'src/app/page.tsx',
            'nuxt' => 'src/main.js',
            default => 'src/main.js',
        }];
    }

    /**
     * Twig paths watched for full-page reload during `fe dev` (Laravel refresh-style).
     *
     * @return list<string>
     */
    public static function defaultRefreshPaths(): array
    {
        return [
            '**/*.twig',
            'partials/**/*.twig',
            'layouts/**/*.twig',
            'views/**/*.twig',
        ];
    }

    /**
     * Absolute globs for app PHP that affects rendered pages (Flow, routes, controllers, …).
     * Passed via VITE_DEV_REFRESH during `fe dev` for full-page reload in the browser.
     *
     * @return list<string>
     */
    public static function appBackendRefreshGlobs(string $themePath): array
    {
        $appPath = dirname(dirname(rtrim(str_replace('\\', '/', $themePath), '/')));

        $globs = array_merge(
            self::directoryRefreshGlobs($appPath . '/Flow'),
            self::directoryRefreshGlobs($appPath . '/routes'),
            self::directoryRefreshGlobs($appPath . '/router'),
            self::directoryRefreshGlobs($appPath . '/Controller'),
            self::directoryRefreshGlobs($appPath . '/Component'),
            self::directoryRefreshGlobs($appPath . '/Portal'),
            self::directoryRefreshGlobs($appPath . '/config'),
            self::directoryRefreshGlobs($appPath . '/lang'),
        );

        foreach (['app.php', 'boot.php', 'func.php', 'schedule.php'] as $file) {
            $target = $appPath . '/' . $file;

            if (!is_file($target)) {
                continue;
            }

            $resolved = realpath($target);

            if ($resolved !== false) {
                $globs[] = str_replace('\\', '/', $resolved);
            }
        }

        return $globs;
    }

    /**
     * @return list<string>
     */
    public static function flowRefreshGlobs(string $themePath): array
    {
        return self::appBackendRefreshGlobs($themePath);
    }

    /**
     * @return list<string>
     */
    private static function directoryRefreshGlobs(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $resolved = realpath($dir);

        if ($resolved === false) {
            return [];
        }

        $base = rtrim(str_replace('\\', '/', $resolved), '/');

        return [
            $base . '/*.php',
            $base . '/**/*.php',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function refreshPaths(array $config): array
    {
        $refresh = $config['refresh'] ?? null;

        if ($refresh === false) {
            return [];
        }

        if (is_array($refresh)) {
            $paths = array_values(array_filter(
                $refresh,
                static fn ($path): bool => is_string($path) && trim($path) !== '',
            ));

            return $paths !== [] ? $paths : self::defaultRefreshPaths();
        }

        return self::defaultRefreshPaths();
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

        $entries = self::entries($config);
        $entry = $entries[0];
        $twig = count($entries) === 1
            ? "{{ vite_tags('" . $entry . "')|raw }}"
            : "{{ vite_tags(['" . implode("', '", $entries) . "'])|raw }}";

        $next = [];
        if ($package !== '') {
            $next[] = ProjectCli::autoFormat('fe ' . $package . ' install --theme=' . $themeName);
            $next[] = ProjectCli::autoFormat('fe ' . $package . ' dev --theme=' . $themeName);
            $next[] = ProjectCli::autoFormat('fe ' . $package . ' watch --theme=' . $themeName . '  # rebuild on file changes');
        }

        return [
            'twig' => $twig,
            'assets_hint' => 'partials/scripts.twig: pinoox_bootstrap() then vite_tags(). Twig, Flow, routes, and Controller edits auto-refresh in dev (pinooxRefresh).',
            'next_steps' => $next,
        ];
    }

    public static function isDevEnabled(array $config): bool
    {
        if (!self::viteDevAllowed()) {
            return false;
        }

        if (!empty($config['dev']['enabled'])) {
            return true;
        }

        return self::isDevFlagActive();
    }

    private static function isDevFlagActive(): bool
    {
        if (self::envBool('VITE_DEV', false)) {
            return true;
        }

        return trim((string) _env('VITE_DEV_SERVER', '')) !== '';
    }

    /**
     * Vite HMR is only active in non-production runtime (ignores hot file in production).
     */
    public static function viteDevAllowed(): bool
    {
        return RuntimeMode::normalize(RuntimeMode::fromEnv()) !== RuntimeMode::PRODUCTION;
    }

    /**
     * Port from frontend.config.php only (not merged defaults).
     */
    public static function readRawDevPort(string $themePath): ?int
    {
        $file = rtrim(str_replace('\\', '/', $themePath), '/') . '/frontend.config.php';

        if (!is_file($file)) {
            return null;
        }

        $raw = include $file;

        if (!is_array($raw)) {
            return null;
        }

        $dev = is_array($raw['dev'] ?? null) ? $raw['dev'] : [];
        $port = $dev['port'] ?? null;

        return is_numeric($port) && (int) $port > 0 ? (int) $port : null;
    }

    public static function hasExplicitDevPort(string $themePath): bool
    {
        return self::readRawDevPort($themePath) !== null;
    }

    /**
     * Pick a free Vite port — honors an explicit frontend.config.php port when set.
     *
     * @param list<int> $reservedPorts
     */
    public static function allocateDevPort(
        string $themePath,
        array $reservedPorts = [],
        string $host = '127.0.0.1',
    ): int {
        $explicit = self::readRawDevPort($themePath);

        if ($explicit !== null) {
            $port = $explicit;

            while ($port <= 65535 && (in_array($port, $reservedPorts, true) || !ServerPort::isAvailable($host, $port))) {
                $port++;
            }

            if ($port > 65535) {
                throw new \RuntimeException(sprintf(
                    'Could not find a free Vite port near configured %d.',
                    $explicit,
                ));
            }

            return $port;
        }

        $port = ServerPort::DEFAULT_VITE_PORT;

        while ($port <= 65535 && (in_array($port, $reservedPorts, true) || !ServerPort::isAvailable($host, $port))) {
            $port++;
        }

        if ($port > 65535) {
            throw new \RuntimeException(sprintf(
                'Could not find a free Vite port (starting at %d).',
                ServerPort::DEFAULT_VITE_PORT,
            ));
        }

        return $port;
    }

    /**
     * Resolved port for PHP asset tags: cache from fe dev → explicit config → env → default.
     *
     * @param array<string, mixed> $config
     */
    public static function resolveRuntimeDevPort(string $themePath, array $config): int
    {
        $cached = FrontendDevSync::readDevPortCache($themePath, $config);

        if ($cached !== null) {
            return $cached;
        }

        $explicit = self::readRawDevPort($themePath);

        if ($explicit !== null) {
            return $explicit;
        }

        $fromEnv = self::readEnvDevPort();

        if ($fromEnv !== null) {
            return $fromEnv;
        }

        $port = $config['dev']['port'] ?? null;

        if (is_numeric($port) && (int) $port > 0) {
            return (int) $port;
        }

        return ServerPort::DEFAULT_VITE_PORT;
    }

    /**
     * Vite dev-server port (explicit config, fe dev cache, env, or default 5173).
     *
     * @param array<string, mixed> $config
     */
    public static function devPort(array $config, ?string $themePath = null): int
    {
        if ($themePath !== null && $themePath !== '') {
            return self::resolveRuntimeDevPort($themePath, $config);
        }

        $port = $config['dev']['port'] ?? null;

        return is_numeric($port) && (int) $port > 0 ? (int) $port : ServerPort::DEFAULT_VITE_PORT;
    }

    /**
     * Vite dev-server bind host (env VITE_DEV_HOST, default 127.0.0.1).
     * Use 0.0.0.0 or true for LAN (--vite-network).
     *
     * @param array<string, mixed> $config
     */
    public static function devHost(array $config): string
    {
        $host = $config['dev']['host'] ?? null;

        if (is_string($host) && trim($host) !== '') {
            return trim($host);
        }

        return '127.0.0.1';
    }

    /**
     * Hide Vite Local/Network URL spam in the terminal (default true).
     *
     * @param array<string, mixed> $config
     */
    public static function devQuiet(array $config): bool
    {
        $quiet = $config['dev']['quiet'] ?? null;

        if ($quiet === null) {
            return true;
        }

        if (is_bool($quiet)) {
            return $quiet;
        }

        return filter_var((string) $quiet, FILTER_VALIDATE_BOOLEAN);
    }

    private static function resolveHotPathFromEnv(): string
    {
        $fromEnv = _env('VITE_HOT_FILE');

        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return ltrim(str_replace('\\', '/', trim($fromEnv)), '/');
        }

        return self::DEFAULT_HOT_FILE;
    }

    private static function resolveDevPortFromEnv(): int
    {
        return self::readEnvDevPort() ?? ServerPort::DEFAULT_VITE_PORT;
    }

    private static function readEnvDevPort(): ?int
    {
        $port = _env('VITE_DEV_PORT');

        if ($port === null || $port === '') {
            return null;
        }

        return is_numeric($port) && (int) $port > 0 ? (int) $port : null;
    }

    private static function envBool(string $key, bool $default = false): bool
    {
        $value = _env($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Relative hot-file path from theme root (frontend.config.php dev.hot).
     *
     * @param array<string, mixed> $config
     */
    public static function hotRelativePath(array $config): string
    {
        $hot = $config['dev']['hot'] ?? self::DEFAULT_HOT_FILE;

        return is_string($hot) && $hot !== '' ? ltrim(str_replace('\\', '/', $hot), '/') : self::DEFAULT_HOT_FILE;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function hotAbsolutePath(string $themePath, array $config): string
    {
        return rtrim(str_replace('\\', '/', $themePath), '/') . '/' . self::hotRelativePath($config);
    }

    /**
     * Resolve Vite dev-server URL: hot file → dev fallback → null (use manifest).
     *
     * @param array<string, mixed> $config
     */
    public static function resolveDevServerUrl(string $themePath, array $config, ?string $manifestRelative = null): ?string
    {
        if (!self::viteDevAllowed()) {
            return null;
        }

        $themePath = rtrim(str_replace('\\', '/', $themePath), '/');
        $hotFile = self::hotAbsolutePath($themePath, $config);

        if (is_file($hotFile)) {
            $url = trim((string) file_get_contents($hotFile));

            if ($url !== '') {
                return rtrim($url, '/');
            }
        }

        $devUrl = self::resolveConfiguredDevServerUrl($config, $themePath);

        if ($devUrl === null) {
            return null;
        }

        $manifestRelative ??= self::manifestRelativePath($config);
        $manifestPath = $manifestRelative !== null
            ? $themePath . '/' . ltrim($manifestRelative, '/')
            : null;
        $manifestExists = $manifestPath !== null && is_file($manifestPath);
        $forceDev = !empty($config['dev']['force']) || self::envBool('VITE_DEV_FORCE', false);
        $preferManifest = ($config['dev']['prefer_manifest'] ?? false) && !$forceDev;
        $devEnabled = self::isDevEnabled($config);

        if ($forceDev) {
            return $devUrl;
        }

        if (!$manifestExists) {
            return $devUrl;
        }

        if ($devEnabled && !$preferManifest) {
            return $devUrl;
        }

        return null;
    }

    /**
     * Dev-server URL from env, frontend.config.php dev.url, or dev.host + dev.port.
     *
     * @param array<string, mixed> $config
     */
    public static function resolveConfiguredDevServerUrl(array $config, ?string $themePath = null): ?string
    {
        $fromEnv = trim((string) _env('VITE_DEV_SERVER', ''));

        if ($fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $fromConfig = trim((string) ($config['dev']['url'] ?? ''));

        if ($fromConfig !== '') {
            return rtrim($fromConfig, '/');
        }

        if (!self::usesViteAssets($config)) {
            return null;
        }

        $host = self::devHost($config);

        if ($host === '0.0.0.0' || $host === '[::]') {
            $host = '127.0.0.1';
        }

        $port = $themePath !== null && $themePath !== ''
            ? self::resolveRuntimeDevPort($themePath, $config)
            : self::devPort($config);

        return 'http://' . $host . ':' . $port;
    }

    public static function isSsrEnabled(array $config): bool
    {
        return ThemeSsr::isEnabled($config);
    }
}

