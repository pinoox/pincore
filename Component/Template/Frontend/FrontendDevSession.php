<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Package\PackageName;
use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Component\Server\ServerPort;

/**
 * Resolved PHP + Vite dev targets for theme:frontend dev (serve host/port, app URL, proxy prefixes).
 */
final class FrontendDevSession
{
    public const SERVE_PLATFORM = 'platform';

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
        public readonly string $viteHost = '127.0.0.1',
        public readonly bool $viteQuiet = true,
        public readonly bool $platformServe = false,
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
        ?string $viteHost = null,
        ?bool $viteQuiet = null,
    ): self {
        $host = self::normalizeHost(
            ($serveHost !== null && trim((string) $serveHost) !== '')
                ? trim((string) $serveHost)
                : (string) _env('SERVER_HOST', '127.0.0.1'),
        );
        $port = self::resolveServePort($servePort, $host);
        $vitePort = self::resolveVitePort($vitePort, $config, $viteHost ?? '127.0.0.1');
        $viteHost = $viteHost ?? FrontendConfig::devHost($config);
        $viteQuiet = $viteQuiet ?? FrontendConfig::devQuiet($config);
        $bindingInput = trim((string) ($serveAppBinding ?? ($withServe ? $package : '')));
        $platformServe = $bindingInput === self::SERVE_PLATFORM;
        $locked = $withServe && !$platformServe && $bindingInput !== '';
        $binding = $locked ? $bindingInput : '';

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
            $viteHost,
            $viteQuiet,
            $platformServe,
        );
    }

    public function phpOrigin(): string
    {
        return 'http://' . $this->displayHost() . ':' . $this->servePort;
    }

    public function viteDevServerUrl(): string
    {
        $host = $this->viteHost;

        if ($host === '0.0.0.0' || $host === 'true' || $host === '[::]') {
            $host = '127.0.0.1';
        }

        return 'http://' . $host . ':' . $this->vitePort;
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

    public function localPhpAppUrl(): string
    {
        $parsed = parse_url($this->displayAppUrl());
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return 'http://127.0.0.1:' . $this->servePort . $path . $query . $fragment;
    }

    public function displayAppUrl(): string
    {
        return $this->displayAppUrls()[0];
    }

    /**
     * @return list<string>
     */
    public function displayAppUrls(): array
    {
        $routerUrls = self::appRouterUrlsForPackage($this->package, $this->serveHost, $this->servePort);

        if ($routerUrls !== []) {
            return $routerUrls;
        }

        $origin = rtrim($this->phpOrigin(), '/');
        $url = rtrim($this->phpAppUrl, '/');

        if ($url !== $origin) {
            return [$this->phpAppUrl];
        }

        return [self::resolvePublicAppUrl($this->package, $this->serveHost, $this->servePort)];
    }

    /**
     * @return list<string>
     */
    public static function appRouterPathsForPackage(string $package): array
    {
        if (!PackageName::looksLike($package)) {
            return [];
        }

        try {
            $canonical = PackageName::canonical($package);
            $routes = \Pinoox\Portal\App\AppRouter::routes();
            $paths = [];

            foreach ($routes as $routePath => $routePackage) {
                if (!is_string($routePackage) || !PackageName::equals($routePackage, $canonical)) {
                    continue;
                }

                $normalized = AppRouteMatcher::normalize((string) $routePath);
                $paths[] = $normalized === '' ? '/' : $normalized;
            }

            $paths = array_values(array_unique($paths));
            usort($paths, static function (string $a, string $b): int {
                if ($a === '/') {
                    return -1;
                }

                if ($b === '/') {
                    return 1;
                }

                return strcmp($a, $b);
            });

            return $paths;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    public static function appRouterUrlsForPackage(string $package, string $serveHost, int $servePort): array
    {
        $origin = rtrim('http://' . self::publicHostForUrl($serveHost) . ':' . $servePort, '/');
        $paths = self::appRouterPathsForPackage($package);

        if ($paths === []) {
            return [];
        }

        $urls = [];

        foreach ($paths as $path) {
            $urls[] = rtrim($origin, '/') . ($path === '/' ? '' : $path);
        }

        return array_values(array_unique($urls));
    }

    public static function resolvePublicAppUrl(string $package, string $serveHost, int $servePort): string
    {
        $origin = rtrim('http://' . self::publicHostForUrl($serveHost) . ':' . $servePort, '/');

        return self::resolveRouterAppUrl($package, $origin);
    }

    public function serveAppLabel(): string
    {
        if ($this->platformServe) {
            return self::SERVE_PLATFORM;
        }

        if ($this->serveAppLocked) {
            return $this->serveAppBinding ?? $this->package;
        }

        return $this->package;
    }

    /**
     * @param list<string>          $forceKeys  auto values that override theme `.env`
     *
     * @return array<string, string>
     */
    public function npmEnvironment(array $config, array $themeEnv = [], string $themePath = '', array $forceKeys = []): array
    {
        $env = [
            'VITE_HOT_FILE' => FrontendConfig::hotRelativePath($config),
            'PINOOX_CORE_PATH' => FrontendDevSync::resolveCorePath(),
            'VITE_DEV_PORT' => (string) $this->vitePort,
            'VITE_DEV_HOST' => $this->viteHost,
            'VITE_DEV_QUIET' => $this->viteQuiet ? 'true' : 'false',
            'VITE_DEV_SERVER' => $this->viteDevServerUrl(),
            'VITE_SERVER_URL' => $this->phpAppUrl,
            'VITE_SERVE_APP' => $this->serveAppLabel(),
            'VITE_DEV' => 'true',
            'VITE_DEV_FORCE' => 'false',
        ];

        if ($this->proxyPrefixes !== []) {
            $env['VITE_DEV_PROXY'] = implode(',', $this->proxyPrefixes);
        }

        if ($this->viteHost === '0.0.0.0' || $this->serveHost === '0.0.0.0') {
            $env['VITE_DEV_NETWORK'] = 'true';
        }

        if ($themePath !== '') {
            $backendRefresh = FrontendConfig::appBackendRefreshGlobs($themePath);

            if ($backendRefresh !== []) {
                $env['VITE_DEV_REFRESH'] = implode(',', $backendRefresh);
            }
        }

        return FrontendDevSync::mergeThemeEnvOverrides($env, $themeEnv, $forceKeys);
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

        if ($this->platformServe) {
            $lines[] = [
                'level' => 'info',
                'message' => 'Pinoox serve: platform (all apps) at ' . $this->phpOrigin(),
            ];
        } elseif ($this->serveAppLocked) {
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
        $origin = 'http://' . self::publicHostForUrl($host) . ':' . $port;

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

        return PackageName::looksLike($binding) ? '/' : AppRouteMatcher::normalize('/' . ltrim($binding, '/'));
    }

    private static function resolveRouterAppUrl(string $package, string $origin): string
    {
        $paths = self::appRouterPathsForPackage($package);

        if ($paths !== []) {
            $primary = self::primaryAppRouterPath($paths);

            return rtrim($origin, '/') . ($primary === '/' ? '' : $primary);
        }

        try {
            $url = \Pinoox\Portal\Url::appUrl($package);

            if (is_string($url) && $url !== '') {
                return self::normalizeAppUrl($url, $origin);
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            $routes = \Pinoox\Portal\App\AppRouter::routes();
            $resolved = ServeAppBinding::resolveBinding($package, $routes);

            if ($resolved !== null) {
                $path = $resolved['path'];

                return rtrim($origin, '/') . ($path === '/' ? '' : $path);
            }
        } catch (\Throwable) {
            // fall through
        }

        return rtrim($origin, '/');
    }

    /**
     * @param list<string> $paths
     */
    private static function primaryAppRouterPath(array $paths): string
    {
        $nonRoot = array_values(array_filter($paths, static fn (string $path): bool => $path !== '/'));

        if (count($nonRoot) === 1) {
            return $nonRoot[0];
        }

        if ($nonRoot !== []) {
            usort($nonRoot, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            return $nonRoot[0];
        }

        return '/';
    }

    /**
     * @return list<string>
     */
    private static function mountPathsForPackage(string $package): array
    {
        $paths = self::appRouterPathsForPackage($package);

        if ($paths === []) {
            return [];
        }

        if (count($paths) === 1 && $paths[0] === '/') {
            return [];
        }

        return array_values(array_filter($paths, static fn (string $path): bool => $path !== '/'));
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

    private static function normalizeAppUrl(string $url, string $fallbackOrigin): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || trim((string) $parts['host']) === '') {
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

            return rtrim($fallbackOrigin, '/') . ($path !== '' && $path !== '/' ? $path : '');
        }

        return rtrim($url, '/');
    }

    private static function normalizeHost(string $host): string
    {
        $host = trim($host);

        return $host !== '' ? $host : '127.0.0.1';
    }

    private static function resolveServePort(?int $explicit, string $host): int
    {
        return ServerPort::resolve($explicit, $host, ServerPort::preferredServePort());
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveVitePort(?int $explicit, array $config, string $host): int
    {
        if ($explicit !== null && $explicit > 0) {
            return ServerPort::resolve($explicit, $host, null);
        }

        return ServerPort::resolve(null, $host, FrontendConfig::devPort($config));
    }

    /**
     * Hostname for URLs shown to the developer (LAN IP when bound to 0.0.0.0).
     */
    private static function publicHostForUrl(string $host): string
    {
        $host = trim($host);

        if ($host === '0.0.0.0' || $host === '[::]') {
            return self::detectLanIp() ?? '127.0.0.1';
        }

        return self::normalizeHost($host);
    }

    public static function detectLanIp(): ?string
    {
        return self::pickBestLanIp(self::collectLanIps());
    }

    /**
     * @return list<string>
     */
    private static function collectLanIps(): array
    {
        $ips = [];

        if (function_exists('socket_create')) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if ($socket !== false) {
                @socket_connect($socket, '8.8.8.8', 80);

                if (@socket_getsockname($socket, $address) && is_string($address) && $address !== '') {
                    $ips[] = $address;
                }

                socket_close($socket);
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('ipconfig') ?? '';

            if (preg_match_all('/IPv4 Address[^:\r\n]*:\s*(\d+\.\d+\.\d+\.\d+)/i', $output, $matches)) {
                foreach ($matches[1] as $ip) {
                    $ips[] = (string) $ip;
                }
            }
        } else {
            $hostname = gethostname();

            if (is_string($hostname) && $hostname !== '') {
                $resolved = gethostbyname($hostname);

                if ($resolved !== $hostname && filter_var($resolved, FILTER_VALIDATE_IP)) {
                    $ips[] = $resolved;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param list<string> $ips
     */
    private static function pickBestLanIp(array $ips): ?string
    {
        $ips = array_values(array_filter($ips, static fn (string $ip): bool => filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4,
        ) !== false && $ip !== '127.0.0.1'));

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (str_starts_with($ip, '192.168.')) {
                return $ip;
            }
        }

        foreach ($ips as $ip) {
            if (str_starts_with($ip, '10.')) {
                return $ip;
            }
        }

        foreach ($ips as $ip) {
            if (!self::isLikelyVirtualAdapterIp($ip)) {
                return $ip;
            }
        }

        return $ips[0];
    }

    private static function isLikelyVirtualAdapterIp(string $ip): bool
    {
        if (!preg_match('/^172\.(\d+)\./', $ip, $matches)) {
            return false;
        }

        $second = (int) $matches[1];

        return $second >= 16 && $second <= 31;
    }
}
