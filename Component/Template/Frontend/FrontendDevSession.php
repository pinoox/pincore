<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Component\Server\ServeAppBinding;

/**
 * Resolved PHP + Vite dev targets for `php pinoox fe dev` (serve host/port, app URL, proxy prefixes).
 */
final class FrontendDevSession
{
    public function __construct(
        public readonly string $package,
        public readonly string $serveHost,
        public readonly int $servePort,
        public readonly bool $serveAppLocked,
        public readonly ?string $serveAppBinding,
        public readonly int $vitePort,
        public readonly string $phpAppUrl,
        /** @var list<string> */
        public readonly array $proxyPrefixes,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromOptions(
        string $package,
        array $config,
        ?string $serveHost = null,
        ?int $servePort = null,
        ?string $serveAppBinding = null,
        bool $withServe = true,
        ?int $vitePort = null,
    ): self {
        $host = self::normalizeHost($serveHost ?? (string) _env('SERVER_HOST', '127.0.0.1'));
        $port = $servePort ?? (int) _env('SERVER_PORT', 8000);
        $vitePort = $vitePort ?? FrontendConfig::devPort($config);
        $binding = $withServe ? trim((string) ($serveAppBinding ?? $package)) : '';
        $locked = $withServe && $binding !== '';

        [$appUrl, $prefixes] = self::resolveAppUrlAndProxy($package, $host, $port, $locked, $binding);
        [$appUrl, $prefixes] = self::applyConfigOverrides($appUrl, $prefixes, $config);

        return new self(
            $package,
            $host,
            $port,
            $locked,
            $locked ? $binding : null,
            $vitePort,
            $appUrl,
            $prefixes,
        );
    }

    public function phpOrigin(): string
    {
        return 'http://' . $this->displayHost() . ':' . $this->servePort;
    }

    public function viteDevServerUrl(): string
    {
        return 'http://' . $this->displayHost() . ':' . $this->vitePort;
    }

    public function displayHost(): string
    {
        if ($this->serveHost === '0.0.0.0' || $this->serveHost === '[::]') {
            return '127.0.0.1';
        }

        if (str_starts_with($this->serveHost, '[') && str_ends_with($this->serveHost, ']')) {
            return $this->serveHost;
        }

        return $this->serveHost;
    }

    /**
     * @param array<string, string> $themeEnv parsed theme `.env` (manual values win over auto)
     *
     * @return array<string, string>
     */
    public function npmEnvironment(array $config, array $themeEnv = [], string $themePath = ''): array
    {
        $env = [
            'VITE_HOT_FILE' => FrontendConfig::hotRelativePath($config),
            'PINOOX_CORE_PATH' => FrontendDevSync::resolveCorePath(),
            'VITE_DEV_PORT' => (string) $this->vitePort,
            'VITE_DEV_SERVER' => $this->viteDevServerUrl(),
            'VITE_SERVER_URL' => $this->phpAppUrl,
            'VITE_DEV' => 'true',
            'VITE_DEV_FORCE' => 'false',
        ];

        if ($this->proxyPrefixes !== []) {
            $env['VITE_DEV_PROXY'] = implode(',', $this->proxyPrefixes);
        }

        if ($themePath !== '') {
            $backendRefresh = FrontendConfig::appBackendRefreshGlobs($themePath);

            if ($backendRefresh !== []) {
                $env['VITE_DEV_REFRESH'] = implode(',', $backendRefresh);
            }
        }

        return FrontendDevSync::mergeThemeEnvOverrides($env, $themeEnv);
    }

    /**
     * @return list<array{level: string, message: string}>
     */
    public function reportLines(): array
    {
        $lines = [
            ['level' => 'info', 'message' => 'PHP app URL: ' . $this->phpAppUrl],
            ['level' => 'info', 'message' => 'Vite dev server: ' . $this->viteDevServerUrl()],
            ['level' => 'info', 'message' => 'Hot file: dist/hot (written when Vite starts)'],
        ];

        if ($this->serveAppLocked) {
            $lines[] = [
                'level' => 'info',
                'message' => 'Pinoox serve locked to ' . ($this->serveAppBinding ?? $this->package) . ' at ' . $this->phpOrigin(),
            ];
        }

        if ($this->proxyPrefixes !== []) {
            $lines[] = [
                'level' => 'info',
                'message' => 'Vite proxy prefixes: ' . implode(', ', $this->proxyPrefixes),
            ];
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function resolveAppUrlAndProxy(
        string $package,
        string $host,
        int $port,
        bool $locked,
        string $binding,
    ): array {
        $origin = 'http://' . self::normalizeHost($host) . ':' . $port;

        if (!$locked) {
            return [
                self::resolveRouterAppUrl($package, $origin),
                self::mountPathsForPackage($package),
            ];
        }

        $mount = self::resolveServeMountPath($binding);
        $appUrl = rtrim($origin, '/') . ($mount === '/' ? '' : $mount);

        if ($mount === '/') {
            return [$appUrl, self::proxyPrefixesForPackage($package, ['/api'])];
        }

        return [$appUrl, self::proxyPrefixesForPackage($package, [$mount])];
    }

    /**
     * @param list<string> $defaults
     * @return list<string>
     */
    private static function proxyPrefixesForPackage(string $package, array $defaults): array
    {
        $prefixes = array_merge($defaults, self::mountPathsForPackage($package));
        $prefixes = array_values(array_unique(array_filter($prefixes)));

        return $prefixes !== [] ? $prefixes : $defaults;
    }

    private static function resolveServeMountPath(string $binding): string
    {
        try {
            $routes = \Pinoox\Portal\App\AppRouter::routes();
            $resolved = ServeAppBinding::resolveBinding($binding, $routes);

            if ($resolved !== null) {
                $path = AppRouteMatcher::normalize($resolved['path']);

                return $path === '' ? '/' : $path;
            }
        } catch (\Throwable) {
            // fall through
        }

        if (str_contains($binding, '@')) {
            [, $path] = explode('@', $binding, 2);

            return AppRouteMatcher::normalize(trim($path));
        }

        $paths = self::mountPathsForPackage($binding);

        if ($paths !== []) {
            return $paths[0];
        }

        return str_starts_with($binding, 'com_') ? '/' : AppRouteMatcher::normalize('/' . ltrim($binding, '/'));
    }

    private static function resolveRouterAppUrl(string $package, string $origin): string
    {
        try {
            $url = \Pinoox\Portal\Url::appUrl($package);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable) {
            // fall through
        }

        $paths = self::mountPathsForPackage($package);

        if ($paths !== []) {
            return rtrim($origin, '/') . $paths[0];
        }

        return rtrim($origin, '/');
    }

    /**
     * @return list<string>
     */
    private static function mountPathsForPackage(string $package): array
    {
        try {
            $routes = \Pinoox\Portal\App\AppRouter::getByPackage($package);
            $paths = [];

            foreach (array_keys($routes) as $routePath) {
                $normalized = AppRouteMatcher::normalize((string) $routePath);

                if ($normalized !== '/') {
                    $paths[] = $normalized;
                }
            }

            $paths = array_values(array_unique($paths));

            return $paths;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Manual overrides from theme frontend.config.php dev.* (merged after router auto-detection).
     *
     * @param list<string> $prefixes
     * @return array{0: string, 1: list<string>}
     */
    private static function applyConfigOverrides(string $appUrl, array $prefixes, array $config): array
    {
        $dev = is_array($config['dev'] ?? null) ? $config['dev'] : [];

        if (isset($dev['server_url']) && is_string($dev['server_url']) && trim($dev['server_url']) !== '') {
            $appUrl = rtrim(trim($dev['server_url']), '/');
        }

        if (isset($dev['proxy'])) {
            $prefixes = self::normalizeProxyList($dev['proxy']);
        }

        if (isset($dev['proxy_extra'])) {
            $prefixes = array_values(array_unique(array_merge(
                $prefixes,
                self::normalizeProxyList($dev['proxy_extra']),
            )));
        }

        return [$appUrl, $prefixes];
    }

    /**
     * @return list<string>
     */
    private static function normalizeProxyList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $prefixes = [];

        foreach ($value as $prefix) {
            if (!is_string($prefix) || trim($prefix) === '') {
                continue;
            }

            $normalized = '/' . trim($prefix, '/');

            if ($normalized !== '/') {
                $prefixes[] = $normalized;
            }
        }

        return array_values(array_unique($prefixes));
    }

    private static function normalizeHost(string $host): string
    {
        $host = trim($host);

        return $host !== '' ? $host : '127.0.0.1';
    }
}
