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

    /** Default Vite build output directory (relative to theme root). */
    public const DEFAULT_BUILD_OUT_DIR = 'dist';

    /** Shared dev/build state between PHP and @pinooxhq/vite-plugin. */
    public const PINOOX_DEV_STATE = FrontendDevState::RELATIVE_PATH;

    /**
     * PHP process flag: {@see viteHmrMode()} — set by `fe dev` / `pinoox dev`, cleared by `pinoox serve`.
     */
    public const VITE_HMR_ENV = 'PINOOX_VITE_HMR';

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
    public static function manifestRelativePath(array $config, ?string $themePath = null): ?string
    {
        if (self::usesViteAssets($config)) {
            $manifest = $config['manifest'] ?? null;

            if (is_string($manifest) && $manifest !== '') {
                return ltrim(str_replace('\\', '/', $manifest), '/');
            }

            return self::manifestPathForOutDir(self::buildOutDir($config, $themePath ?? ''));
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
            $config['mount'] ??= '#app';
            $config['pinoox'] ??= 'pinoox';
            $config['dev'] = array_replace([
                'enabled' => self::isDevFlagActive(),
                'url' => rtrim((string) _env('VITE_DEV_SERVER', ''), '/'),
                'port' => self::readRawDevPort($themePath) ?? self::readEnvDevPort(),
                'prefer_manifest' => self::envBool('VITE_PREFER_MANIFEST', false),
                'force' => self::envBool('VITE_DEV_FORCE', false),
            ], is_array($config['dev'] ?? null) ? $config['dev'] : []);

            $config = self::applyViteBuildDirDefaults($config, $themePath);
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

    /**
     * Whether the active PHP process should load Vite HMR assets (not built manifest).
     *
     * - `pinoox serve` sets {@see VITE_HMR_ENV}=0 → manifest from dist
     * - `fe dev` / `pinoox dev` sets {@see VITE_HMR_ENV}=1 → `.pinoox/dev.json` / Vite dev server
     * - Unset: legacy fallback via VITE_DEV / VITE_DEV_SERVER (MAMP + npm run dev)
     */
    public static function viteHmrMode(): bool
    {
        if (self::viteHmrEnvExplicitlySet()) {
            return self::envBool(self::VITE_HMR_ENV, false);
        }

        return self::isDevFlagActive();
    }

    private static function viteHmrEnvExplicitlySet(): bool
    {
        return self::runtimeEnv(self::VITE_HMR_ENV) !== null;
    }

    /**
     * Process env (PINOOX_VITE_HMR, VITE_DEV, …) may exist only in getenv() on Windows when $_ENV is empty.
     */
    private static function runtimeEnv(string $key): ?string
    {
        $value = _env($key);

        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        $fromGetenv = getenv($key);

        if (is_string($fromGetenv) && $fromGetenv !== '') {
            return $fromGetenv;
        }

        return null;
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

        return trim((string) (self::runtimeEnv('VITE_DEV_SERVER') ?? '')) !== '';
    }

    /**
     * Vite HMR is only active in non-production runtime (ignores dev state in production).
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
        $fromState = FrontendDevState::port($themePath);

        if ($fromState !== null) {
            return $fromState;
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

    /**
     * Resolved Vite build output directory (relative to theme root).
     *
     * @param array<string, mixed> $config
     */
    public static function buildOutDir(array $config, string $themePath = ''): string
    {
        if ($themePath !== '') {
            $rawOutDir = self::readRawBuildOutDir($themePath);

            if ($rawOutDir !== null) {
                return $rawOutDir;
            }
        }

        $fromConfig = is_array($config['build'] ?? null) ? ($config['build']['outDir'] ?? null) : null;

        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return self::normalizeRelativePath($fromConfig);
        }

        $fromEnv = trim((string) _env('VITE_BUILD_OUT_DIR', ''));

        if ($fromEnv !== '') {
            return self::normalizeRelativePath($fromEnv);
        }

        if ($themePath !== '') {
            $fromState = FrontendDevState::outDir($themePath);

            if ($fromState !== null) {
                return $fromState;
            }
        }

        $manifest = $config['manifest'] ?? null;

        if (is_string($manifest) && $manifest !== '') {
            $derived = self::outDirFromManifestPath($manifest);

            if ($derived !== null) {
                return $derived;
            }
        }

        return self::DEFAULT_BUILD_OUT_DIR;
    }

    public static function manifestPathForOutDir(string $outDir): string
    {
        return self::normalizeRelativePath($outDir) . '/.vite/manifest.json';
    }

    public static function outDirFromManifestPath(string $manifest): ?string
    {
        $manifest = ltrim(str_replace('\\', '/', $manifest), '/');
        $suffix = '/.vite/manifest.json';

        if (!str_ends_with($manifest, $suffix)) {
            return null;
        }

        $outDir = substr($manifest, 0, -strlen($suffix));

        return $outDir !== '' ? $outDir : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function readRawFrontendConfig(string $themePath): array
    {
        $file = rtrim(str_replace('\\', '/', $themePath), '/') . '/frontend.config.php';

        if (!is_file($file)) {
            return [];
        }

        $raw = include $file;

        return is_array($raw) ? $raw : [];
    }

    public static function readRawBuildOutDir(string $themePath): ?string
    {
        $raw = self::readRawFrontendConfig($themePath);
        $build = is_array($raw['build'] ?? null) ? $raw['build'] : [];
        $outDir = $build['outDir'] ?? null;

        if (!is_string($outDir) || trim($outDir) === '') {
            return null;
        }

        return self::normalizeRelativePath($outDir);
    }

    public static function writeBuildOutDirCache(string $themePath, string $outDir): void
    {
        FrontendDevState::write($themePath, outDir: $outDir);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function applyViteBuildDirDefaults(array $config, string $themePath): array
    {
        $outDir = self::buildOutDir($config, $themePath);
        $raw = self::readRawFrontendConfig($themePath);

        if (!isset($raw['manifest'])) {
            $config['manifest'] = self::manifestPathForOutDir($outDir);
        }

        $config['build'] = array_replace(
            ['outDir' => $outDir],
            is_array($config['build'] ?? null) ? $config['build'] : [],
        );
        $config['build']['outDir'] = $outDir;

        return $config;
    }

    private static function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
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
        $value = self::runtimeEnv($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function devStateRelativePath(): string
    {
        return FrontendDevState::RELATIVE_PATH;
    }

    public static function devStateAbsolutePath(string $themePath): string
    {
        return FrontendDevState::absolutePath($themePath);
    }

    /**
     * Resolve Vite dev-server URL: `.pinoox/dev.json` → dev fallback → null (use manifest).
     *
     * @param array<string, mixed> $config
     */
    public static function resolveDevServerUrl(string $themePath, array $config, ?string $manifestRelative = null): ?string
    {
        if (!self::viteDevAllowed()) {
            return null;
        }

        if (!self::viteHmrMode()) {
            return null;
        }

        $themePath = rtrim(str_replace('\\', '/', $themePath), '/');
        $fromState = FrontendDevState::viteUrl($themePath);

        if ($fromState !== null) {
            return $fromState;
        }

        $devUrl = self::resolveConfiguredDevServerUrl($config, $themePath);

        if ($devUrl === null) {
            return null;
        }

        if (self::viteHmrEnvExplicitlySet() && self::envBool(self::VITE_HMR_ENV, false)) {
            return $devUrl;
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
        $fromEnv = trim((string) (self::runtimeEnv('VITE_DEV_SERVER') ?? ''));

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

