<?php

namespace Pinoox\Component\Server;

use Pinoox\Support\SystemConfig;

/**
 * Routes whose path looks like a static file must bypass the web-server static handler.
 *
 * Registered automatically when a route path has a file extension, or explicitly via
 * {@see \Pinoox\Component\Router\RouteBuilder::fixWebServer()}.
 */
final class WebServerFix
{
    /** @var list<string> */
    public const EXTENSIONS = [
        'atom', 'avif', 'cjs', 'css', 'csv', 'eot', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'json',
        'map', 'mjs', 'otf', 'png', 'rss', 'svg', 'ttf', 'txt', 'webmanifest', 'webp', 'woff',
        'woff2', 'xml', 'yaml', 'yml',
    ];

    /** @var list<string>|null */
    private static ?array $resolvedFullPaths = null;

    public static function pathHasStaticExtension(string $path): bool
    {
        if (str_contains($path, '{') || str_contains($path, '*')) {
            return false;
        }

        $segment = basename(str_replace('\\', '/', $path));

        if ($segment === '' || $segment === '/') {
            return false;
        }

        $dot = strrpos($segment, '.');

        if ($dot === false || $dot === 0) {
            return false;
        }

        $ext = strtolower(substr($segment, $dot + 1));

        return in_array($ext, self::EXTENSIONS, true);
    }

    public static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', rawurldecode($path)));

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }

    public static function joinMount(string $mount, string $relative): string
    {
        $mount = rtrim(self::normalizePath($mount), '/');
        $relative = self::normalizePath($relative);

        if ($mount === '' || $mount === '/') {
            return $relative;
        }

        if ($relative === '/') {
            return $mount;
        }

        return self::normalizePath($mount . '/' . ltrim($relative, '/'));
    }

    public static function relativeToMount(string $mount, string $fullPath): string
    {
        $mount = rtrim(self::normalizePath($mount), '/');
        $full = self::normalizePath($fullPath);

        if ($mount === '' || $mount === '/') {
            return $full;
        }

        if ($full === $mount) {
            return '/';
        }

        if (str_starts_with($full, $mount . '/')) {
            return self::normalizePath(substr($full, strlen($mount)));
        }

        return $full;
    }

    /**
     * @return list<string>
     */
    public static function fullPaths(?string $documentRoot = null): array
    {
        if (self::$resolvedFullPaths !== null) {
            return self::$resolvedFullPaths;
        }

        $paths = [];

        foreach (self::routerMap($documentRoot) as $mount => $package) {
            if (!is_string($mount) || !is_string($package) || $mount === '*') {
                continue;
            }

            foreach (WebServerFixCache::load($package) as $entry) {
                $relative = is_array($entry) ? ($entry['relative'] ?? null) : null;

                if (!is_string($relative) || $relative === '') {
                    continue;
                }

                $paths[self::joinMount($mount, $relative)] = true;
            }
        }

        return self::$resolvedFullPaths = array_keys($paths);
    }

    public static function resetResolvedPaths(): void
    {
        self::$resolvedFullPaths = null;
        WebServerFixCache::resetRuntimeCache();
    }

    public static function isRootFixPath(string $path): bool
    {
        $normalized = self::normalizePath($path);

        foreach (WebServerFixCache::allRelativePaths() as $relative) {
            if ($normalized === self::normalizePath($relative)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $uri, ?string $documentRoot = null): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return false;
        }

        $normalized = self::normalizePath($path);

        foreach (self::fullPaths($documentRoot) as $fullPath) {
            if ($normalized === self::normalizePath($fullPath)) {
                return true;
            }
        }

        return false;
    }

    public static function shouldRoute(string $uri, ?string $documentRoot = null): bool
    {
        if (self::matches($uri, $documentRoot)) {
            return true;
        }

        if ($documentRoot === null) {
            return false;
        }

        return self::fallbackExtensionRoute($uri, $documentRoot);
    }

    public static function applyServerGlobals(string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['PATH_INFO'] = $path;

        unset($_SERVER['REDIRECT_URL'], $_SERVER['REDIRECT_STATUS']);
    }

    /** @var array<string, string>|null */
    private static ?array $customRouterMap = null;

    /** @var (callable(?string): array<string, string>)|null */
    private static mixed $routerMapResolver = null;

    /**
     * Programmatically override the router map (e.g. for testing, runtime hooks, or dynamic multi-tenancy).
     *
     * @param array<string, string>|null $map
     */
    public static function setRouterMap(?array $map): void
    {
        self::$customRouterMap = $map;
        self::resetResolvedPaths();
    }

    /**
     * Set a dynamic resolver callable that returns the router map.
     *
     * @param (callable(?string): array<string, string>)|null $resolver
     */
    public static function setRouterMapResolver(?callable $resolver): void
    {
        self::$routerMapResolver = $resolver;
        self::resetResolvedPaths();
    }

    /**
     * @return array<string, string>
     */
    public static function routerMap(?string $documentRoot = null): array
    {
        if (self::$customRouterMap !== null) {
            return self::$customRouterMap;
        }

        if (self::$routerMapResolver !== null) {
            $resolved = (self::$routerMapResolver)($documentRoot);
            if (is_array($resolved)) {
                return $resolved;
            }
        }

        $root = $documentRoot !== null
            ? rtrim(str_replace('\\', '/', $documentRoot), '/')
            : self::guessDocumentRoot();

        $projectRouter = SystemConfig::path('project_router');
        $systemRouter = SystemConfig::path('system_router');

        if ($documentRoot !== null) {
            // When an explicit documentRoot is passed (e.g. sandbox or dev-server), prioritize paths in that root
            $candidates = [
                $root . '/platform/app-router.config.php',
                $root . '/config/app-router.config.php',
                $root . '/pinker/bake/platform/app-router.config.php',
                $root . '/pinker/state/platform/app-router.config.php',
                $root . '/pinker/platform/app-router.config.php',
                $projectRouter,
                $systemRouter,
                $root . '/vendor/pinoox/pincore/config/app-router.config.php',
                $root . '/pincore/config/app-router.config.php',
                $root . '/pinker/config/app-router.config.php',
                $root . '/pinker/system/config/app/router.config.php',
            ];
        } else {
            // Default project runtime: prioritize canonical project router config
            $candidates = [
                $projectRouter,
                $systemRouter,
                $root . '/platform/app-router.config.php',
                $root . '/config/app-router.config.php',
                $root . '/pinker/bake/platform/app-router.config.php',
                $root . '/pinker/state/platform/app-router.config.php',
                $root . '/pinker/platform/app-router.config.php',
                $root . '/vendor/pinoox/pincore/config/app-router.config.php',
                $root . '/pincore/config/app-router.config.php',
                $root . '/pinker/config/app-router.config.php',
                $root . '/pinker/system/config/app/router.config.php',
            ];
        }

        $candidates = array_unique(array_filter($candidates));

        foreach ($candidates as $file) {
            if (!is_string($file) || $file === '' || !is_file($file)) {
                continue;
            }

            $routes = require $file;

            return is_array($routes) ? $routes : [];
        }

        return [];
    }

    private static function fallbackExtensionRoute(string $uri, string $documentRoot): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || !self::pathHasStaticExtension($path)) {
            return false;
        }

        $target = rtrim(str_replace('\\', '/', $documentRoot), '/') . ($path === '/' ? '' : $path);

        return !is_file($target);
    }

    private static function guessDocumentRoot(): string
    {
        $cwd = getcwd();

        return is_string($cwd) ? rtrim(str_replace('\\', '/', $cwd), '/') : '';
    }
}
