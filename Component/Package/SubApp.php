<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Package;

use Closure;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\Response;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Template\Theme\ThemeContext;
use Pinoox\Component\Transport\TransportConfig;
use Pinoox\Component\Transport\TransportContext;
use Pinoox\Component\User\AuthSession;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Auth;
use Pinoox\Component\Router\Router;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use RuntimeException;
use Throwable;

class SubApp
{
    /** @var array<string, string> */
    private static array $resolvedPaths = [];

    /** @var array<string, array<string, mixed>|null> */
    private static array $manifestCache = [];

    /**
     * Clear in-memory package discovery and manifest caches (useful in tests).
     */
    public static function clearCache(): void
    {
        self::$resolvedPaths = [];
        self::$manifestCache = [];
    }

    /**
     * Parse optional "package:mount" syntax (e.g. "com_pinoox_pay:public") without breaking filesystem paths.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function parsePackageTarget(string $packageOrTarget): array
    {
        if (!self::looksLikePath($packageOrTarget) && str_contains($packageOrTarget, ':')) {
            [$pkg, $mount] = explode(':', $packageOrTarget, 2);
            $pkg = trim($pkg);
            $mount = trim($mount);
            if ($pkg !== '' && $mount !== '') {
                return [$pkg, $mount];
            }
        }

        return [$packageOrTarget, null];
    }

    /**
     * Run a sub-app under a given mount path and return the response.
     *
     * @param string $package Guest package name (with optional ":mount" preset) or directory path
     * @param string $mountPath Relative mount path (e.g. '/pay' or 'app/com_test')
     * @param Request|null $request The incoming HTTP request (or active request if null)
     * @param array<string, mixed> $options Options: config, share_auth, auth, share_session, theme_context, mount, attributes, throw_on_error, expose_headers, path, dir
     * @return SymfonyResponse
     * @throws RuntimeException
     */
    public static function run(
        string $package,
        string $mountPath = '/',
        ?Request $request = null,
        array $options = [],
    ): SymfonyResponse {
        [$package, $inlineMount] = self::parsePackageTarget($package);
        if ($inlineMount !== null && empty($options['mount'])) {
            $options['mount'] = $inlineMount;
        }

        $package = self::resolvePackage($package, $options);

        if (!AppEngine::exists($package)) {
            throw new RuntimeException("SubApp package '{$package}' not found.");
        }

        $hostPackage = App::package() ?? '';

        if (!self::canMount($package, $hostPackage)) {
            throw new RuntimeException("Host app '{$hostPackage}' is not allowed to mount sub-app '{$package}'.");
        }

        if (!empty($options['mount']) && is_string($options['mount'])) {
            $options = self::applyMountPreset($package, $options['mount'], $options);
        }

        $hostRoute = (string) App::pathRoute();
        $mountPath = trim($mountPath, '/');
        $layerPath = $mountPath === ''
            ? ($hostRoute === '' ? '/' : $hostRoute)
            : rtrim($hostRoute, '/') . '/' . $mountPath;

        $shareAuth = self::resolveShareAuth($options, $package, $hostPackage);
        $authSnapshot = null;

        if (!$shareAuth) {
            $authSnapshot = AuthSession::snapshot();
            Auth::reset();
        } else {
            Auth::boot();
        }

        $normalizedMountPath = '/' . ltrim($mountPath, '/');
        $fullMountPath = '/' . ltrim($layerPath, '/');
        $subAppContext = array_merge([
            'mount_path' => $normalizedMountPath,
            'full_mount_path' => $fullMountPath,
            'sub_app_base' => $layerPath,
        ], $options['context'] ?? []);

        $configOverrides = !empty($options['config']) && is_array($options['config']) ? $options['config'] : [];
        if (!empty($options['routes'])) {
            $configOverrides['router']['routes'] = (array) $options['routes'];
        }
        if (!empty($options['only_tags'])) {
            $configOverrides['router']['only_tags'] = (array) $options['only_tags'];
        }

        $themeContext = $options['theme_context'] ?? $options['theme-context'] ?? null;
        if (is_string($themeContext) && $themeContext !== '') {
            $configOverrides['theme-context'] = $themeContext;
        }

        $hasConfig = !empty($configOverrides);
        if ($hasConfig) {
            AppEngine::pushConfig($package, $configOverrides);
        }

        $request ??= AppProvider::getRequest();
        $subRequest = $request->duplicate();
        $shareSession = $options['share_session'] ?? $shareAuth;
        if (!$shareSession) {
            $subRequest->clearSession();
        }
        $subRequest->attributes = new ParameterBag();

        $subAppBaseUrl = null;
        $exposeHeaders = $options['expose_headers'] ?? true;

        try {
            $response = App::meeting($package, static function () use ($subRequest, $options, $themeContext, &$subAppBaseUrl) {
                $execute = static function () use ($subRequest, $options, &$subAppBaseUrl) {
                    $subAppBaseUrl = App::subAppBaseUrl();
                    $attributes = $options['attributes'] ?? [];
                    if ($attributes === []) {
                        $attributes = App::router()->matchRequest($subRequest);
                    }

                    $subRequest->attributes->add($attributes);

                    return AppProvider::handle($subRequest, HttpKernelInterface::SUB_REQUEST);
                };

                if (is_string($themeContext) && $themeContext !== '') {
                    return ThemeContext::using($themeContext, $execute);
                }

                return $execute();
            }, $layerPath, $subAppContext);

            if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse || $response instanceof Response) {
                $resp = $response;
            } else {
                $resp = new Response((string) $response->getContent(), $response->getStatusCode(), $response->headers->all());
            }

            if ($exposeHeaders) {
                $resp->headers->set('X-SubApp-Mount-Path', $normalizedMountPath);
                if ($subAppBaseUrl !== null) {
                    $resp->headers->set('X-SubApp-Base-Url', $subAppBaseUrl);
                }
                if ($hostPackage !== '') {
                    $resp->headers->set('X-SubApp-Parent', $hostPackage);
                }
            }

            return $resp;
        } catch (ResourceNotFoundException|MethodNotAllowedException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            if (!empty($options['throw_on_error'])) {
                throw $e;
            }
            throw new RuntimeException("Error executing sub-app '{$package}': " . $e->getMessage(), 0, $e);
        } finally {
            if ($hasConfig) {
                AppEngine::popConfig($package);
            }
            if ($authSnapshot !== null) {
                Auth::reset();
                AuthSession::restore($authSnapshot);
            }
        }
    }

    /**
     * Check if a sub-app is available and enabled (with auto-discovery and autoloading).
     *
     * @param string $package Guest package name or directory path
     * @param array<string, mixed> $options
     */
    public static function has(string $package, array $options = []): bool
    {
        [$package] = self::parsePackageTarget($package);
        $resolved = self::resolvePackage($package, $options);

        if (!AppEngine::exists($resolved)) {
            return false;
        }

        try {
            if (!(bool) AppEngine::config($resolved)->get('enable', true)) {
                return false;
            }
            App::autoloader($resolved);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Execute a programmatic callback inside a sub-app's isolated meeting context.
     * Automatically discovers the package, registers its PSR-4 autoloader, and applies optional config/context overlays.
     *
     * @template T
     * @param string $package Guest package name or directory path
     * @param Closure(): T $callback
     * @param array<string, mixed> $options Options: config, context, path, dir, theme_context
     * @return T
     * @throws RuntimeException
     */
    public static function call(string $package, Closure $callback, array $options = []): mixed
    {
        [$package, $inlineMount] = self::parsePackageTarget($package);
        if ($inlineMount !== null && empty($options['mount'])) {
            $options['mount'] = $inlineMount;
        }

        $resolved = self::resolvePackage($package, $options);
        if (!AppEngine::exists($resolved)) {
            throw new RuntimeException("SubApp package '{$resolved}' not found.");
        }

        App::autoloader($resolved);

        if (!empty($options['mount']) && is_string($options['mount'])) {
            $options = self::applyMountPreset($resolved, $options['mount'], $options);
        }

        $configOverrides = !empty($options['config']) && is_array($options['config']) ? $options['config'] : [];
        $themeContext = $options['theme_context'] ?? $options['theme-context'] ?? null;
        if (is_string($themeContext) && $themeContext !== '') {
            $configOverrides['theme-context'] = $themeContext;
        }

        $hasConfig = !empty($configOverrides);
        if ($hasConfig) {
            AppEngine::pushConfig($resolved, $configOverrides);
        }

        $context = !empty($options['context']) && is_array($options['context']) ? $options['context'] : [];
        $layerPath = isset($options['mount_path']) && is_string($options['mount_path']) ? $options['mount_path'] : '';

        try {
            return App::meeting($resolved, static function () use ($callback, $themeContext) {
                if (is_string($themeContext) && $themeContext !== '') {
                    return ThemeContext::using($themeContext, $callback);
                }

                return $callback();
            }, $layerPath, $context);
        } finally {
            if ($hasConfig) {
                AppEngine::popConfig($resolved);
            }
        }
    }

    /**
     * Safely execute a programmatic callback inside a sub-app if it is installed and enabled;
     * returns $default otherwise.
     *
     * @template T
     * @param string $package Guest package name or directory path
     * @param Closure(): T $callback
     * @param mixed $default Fallback value when the sub-app is not installed or disabled
     * @param array<string, mixed> $options
     * @return mixed
     */
    public static function tryCall(string $package, Closure $callback, mixed $default = null, array $options = []): mixed
    {
        if (!self::has($package, $options)) {
            return $default;
        }

        return self::call($package, $callback, $options);
    }

    private static function resolveShareAuth(array $options, string $package, string $hostPackage): bool
    {
        if (array_key_exists('share_auth', $options)) {
            return (bool) $options['share_auth'];
        }

        if (isset($options['auth']) && is_string($options['auth'])) {
            return match (strtolower($options['auth'])) {
                'share', 'shared', 'host' => true,
                'isolate', 'isolated', 'local', 'none' => false,
                default => TransportConfig::sharesAuthWith($package, $hostPackage),
            };
        }

        return TransportConfig::sharesAuthWith($package, $hostPackage);
    }

    /**
     * Merge a named mount preset declared in the guest's app.php (e.g. subapp.mounts.public).
     * Host-provided options always take precedence over preset defaults.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function applyMountPreset(string $package, string $mountName, array $options): array
    {
        try {
            $config = AppEngine::config($package);
            $preset = $config->get("subapp.mounts.{$mountName}")
                ?? $config->get("sub_app.mounts.{$mountName}")
                ?? $config->get("mounts.{$mountName}");

            if (!is_array($preset)) {
                return $options;
            }

            if (empty($options['routes']) && !empty($preset['routes'])) {
                $options['routes'] = (array) $preset['routes'];
            }

            if (empty($options['only_tags'])) {
                $presetTags = $preset['only'] ?? $preset['only_tags'] ?? null;
                if (!empty($presetTags)) {
                    $options['only_tags'] = is_array($presetTags) ? $presetTags : [$presetTags];
                }
            }

            if (empty($options['theme_context']) && empty($options['theme-context'])) {
                $presetTheme = $preset['theme_context'] ?? $preset['theme-context'] ?? null;
                if (is_string($presetTheme) && $presetTheme !== '') {
                    $options['theme_context'] = $presetTheme;
                }
            }

            if (!empty($preset['config']) && is_array($preset['config'])) {
                $options['config'] = array_replace_recursive($preset['config'], $options['config'] ?? []);
            }

            if (!empty($preset['context']) && is_array($preset['context'])) {
                $options['context'] = array_merge($preset['context'], $options['context'] ?? []);
            }

            if (!array_key_exists('share_auth', $options) && !isset($options['auth'])) {
                if (array_key_exists('share_auth', $preset)) {
                    $options['share_auth'] = (bool) $preset['share_auth'];
                } elseif (isset($preset['auth']) && is_string($preset['auth'])) {
                    $options['auth'] = $preset['auth'];
                }
            }
        } catch (Throwable) {
        }

        return $options;
    }

    /**
     * Get the relative mount path of the current sub-app (e.g. '/pay').
     */
    public static function mountPath(): string
    {
        return App::mountPath();
    }

    /**
     * Get the base URL of the current sub-app.
     */
    public static function baseUrl(): string
    {
        return App::subAppBaseUrl();
    }

    /**
     * Determine if current execution is running inside a sub-app.
     */
    public static function isSubApp(): bool
    {
        return TransportContext::inMeeting() || (bool) App::context('is_sub_app', false);
    }

    /**
     * Get the host/parent app package name if currently running as a sub-app.
     */
    public static function parent(): ?string
    {
        return TransportContext::host() ?? App::context('parent_app');
    }

    /**
     * Alias of parent().
     */
    public static function host(): ?string
    {
        return self::parent();
    }

    /**
     * Check if the current app is running as a sub-app of the given package(s).
     *
     * @param string|list<string> $packages
     */
    public static function isSubAppOf(string|array $packages): bool
    {
        if (!self::isSubApp()) {
            return false;
        }

        $parent = self::parent();
        if ($parent === null || $parent === '') {
            return false;
        }

        $packages = is_array($packages) ? $packages : [$packages];

        return in_array($parent, $packages, true);
    }

    /**
     * Get the filesystem path of a sub-app (auto-detecting and resolving via AppEngine).
     *
     * @param string $package Guest package name or directory path
     * @param string $path Optional relative sub-path inside the app
     * @param array<string, mixed> $options Optional resolution options (e.g. ['path' => '...'])
     * @return string
     */
    public static function path(string $package, string $path = '', array $options = []): string
    {
        $resolved = self::resolvePackage($package, $options);

        return AppEngine::path($resolved, $path);
    }

    /**
     * Check if a sub-app exists (auto-detecting via AppEngine).
     *
     * @param string $package Guest package name or directory path
     * @param array<string, mixed> $options Optional resolution options
     * @return bool
     */
    public static function exists(string $package, array $options = []): bool
    {
        $resolved = self::resolvePackage($package, $options);

        return AppEngine::exists($resolved);
    }

    /**
     * Check if a guest app can be mounted by the host app.
     */
    public static function canMount(string $guestPackage, ?string $hostPackage = null, array $options = []): bool
    {
        $guestPackage = self::resolvePackage($guestPackage, $options);

        if (!AppEngine::exists($guestPackage)) {
            return false;
        }

        $hostPackage = ($hostPackage !== null && $hostPackage !== '')
            ? $hostPackage
            : (App::package() ?? '');

        try {
            $config = AppEngine::config($guestPackage);
            if (!(bool) $config->get('enable', true)) {
                return false;
            }

            $allowedHosts = $config->get('allowed_hosts');
            if (is_array($allowedHosts) && $allowedHosts !== []) {
                if (!in_array($hostPackage, $allowedHosts, true) && !in_array('*', $allowedHosts, true)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if an app is marked as sub-app only (cannot run as a standalone root app).
     */
    public static function isSubAppOnly(string $packageName, array $options = []): bool
    {
        $packageName = self::resolvePackage($packageName, $options);

        if (!AppEngine::exists($packageName)) {
            return false;
        }

        try {
            $config = AppEngine::config($packageName);
            return (bool) $config->get('subapp_only', false) || $config->get('standalone') === false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Read and lazily resolve a context value for the active app layer.
     */
    public static function context(?string $key = null, mixed $default = null): mixed
    {
        return App::context($key, $default);
    }

    public static function resolveContext(?string $key = null, mixed $default = null): mixed
    {
        return App::resolveContext($key, $default);
    }

    public static function rawContext(?string $key = null, mixed $default = null): mixed
    {
        return App::rawContext($key, $default);
    }

    /**
     * Mount sub-apps declared in host app configuration (app.php).
     *
     * Supported formats in app.php:
     *
     * 1. Simple key-value (mount_path => guest_package_or_path):
     *    'sub_apps' => [
     *        '/pay' => 'pay',
     *        '/sms' => 'com_pinoox_sms',
     *    ]
     *
     * 2. Detailed options per mount_path:
     *    'sub_apps' => [
     *        '/pay' => [
     *            'app' => 'pay', // or 'package' => 'pay'
     *            'enable' => true,
     *            'routes' => 'routes/site/web.php',
     *            'only' => ['checkout'],
     *            'config' => ['theme' => 'light'],
     *            'context' => ['embed' => true],
     *            'share_auth' => true,
     *            'flows' => [...],
     *            'priority' => 2000,
     *            'name' => 'shop.pay',
     *            'methods' => ['GET', 'POST'],
     *            'path' => '../pay', // optional explicit path (relative or absolute)
     *        ],
     *    ]
     *
     * 3. List of items:
     *    'sub_apps' => [
     *        ['path' => '/pay', 'package' => 'pay', ...],
     *    ]
     *
     * @param Router $router
     * @param string $hostPackage
     */
    public static function mountFromConfig(Router $router, string $hostPackage): void
    {
        if (!AppEngine::exists($hostPackage)) {
            return;
        }

        try {
            $config = AppEngine::config($hostPackage);
            $subApps = $config->get('sub_apps') ?? $config->get('router.sub_apps');
            if (empty($subApps) || !is_array($subApps)) {
                return;
            }

            foreach ($subApps as $key => $value) {
                $mountPath = null;
                $guestPackage = null;
                $options = [];

                if (is_string($key)) {
                    $mountPath = $key;
                    if (is_string($value)) {
                        $guestPackage = $value;
                    } elseif (is_array($value)) {
                        $options = $value;
                        $guestPackage = $options['app'] ?? $options['package'] ?? $options['guest'] ?? $options['dir'] ?? $options['path'] ?? null;
                        unset($options['app'], $options['package'], $options['guest']);
                    }
                } elseif (is_array($value)) {
                    $options = $value;
                    $mountPath = $options['mount'] ?? $options['route'] ?? $options['url'] ?? $options['path'] ?? null;
                    $guestPackage = $options['app'] ?? $options['package'] ?? $options['guest'] ?? $options['dir'] ?? null;
                    // Avoid treating the URL mount path as a filesystem directory
                    if (isset($options['path']) && $options['path'] === $mountPath) {
                        unset($options['path']);
                    }
                    unset($options['mount'], $options['route'], $options['url'], $options['app'], $options['package'], $options['guest']);
                }

                if (empty($mountPath) || empty($guestPackage) || !is_string($mountPath) || !is_string($guestPackage)) {
                    continue;
                }

                if (isset($options['enable']) && !(bool) $options['enable']) {
                    continue;
                }
                if (isset($options['enabled']) && !(bool) $options['enabled']) {
                    continue;
                }

                $builder = $router->subApp($mountPath, $guestPackage);

                $explicitDir = $options['dir'] ?? $options['app_path'] ?? $options['path'] ?? null;
                if (is_string($explicitDir) && $explicitDir !== '') {
                    $builder->path($explicitDir);
                }
                if (!empty($options['mount']) && is_string($options['mount'])) {
                    $builder->mount($options['mount']);
                }
                if (!empty($options['config']) && is_array($options['config'])) {
                    $builder->config($options['config']);
                }
                if (!empty($options['routes'])) {
                    $builder->routes($options['routes']);
                }
                if (!empty($options['only'])) {
                    $builder->only($options['only']);
                } elseif (!empty($options['only_tags'])) {
                    $builder->only($options['only_tags']);
                }
                if (!empty($options['context']) && is_array($options['context'])) {
                    $builder->context($options['context']);
                }
                if (isset($options['share_auth'])) {
                    $builder->shareAuth((bool) $options['share_auth']);
                } elseif (isset($options['auth']) && is_string($options['auth'])) {
                    $builder->options(['auth' => $options['auth']]);
                }
                if (isset($options['share_session'])) {
                    $builder->shareSession((bool) $options['share_session']);
                }
                $themeCtx = $options['theme_context'] ?? $options['theme-context'] ?? null;
                if (is_string($themeCtx) && $themeCtx !== '') {
                    $builder->themeContext($themeCtx);
                }
                if (isset($options['expose_headers'])) {
                    $builder->exposeHeaders((bool) $options['expose_headers']);
                }
                if (!empty($options['flows']) && is_array($options['flows'])) {
                    $builder->flows($options['flows']);
                }
                if (isset($options['priority'])) {
                    $builder->priority((int) $options['priority']);
                }
                if (!empty($options['name'])) {
                    $builder->name((string) $options['name']);
                }
                if (!empty($options['methods'])) {
                    $builder->methods($options['methods']);
                }
                if (!empty($options['data']) && is_array($options['data'])) {
                    $builder->data($options['data']);
                }
                if (!empty($options['tags']) && is_array($options['tags'])) {
                    $builder->tags($options['tags']);
                }
                $builder->register();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Auto-detect and resolve the guest package name and its filesystem location.
     * Automatically registers the package with AppEngine (and autoloader) if not already registered.
     *
     * @param string $packageOrPath Package name or filesystem directory path
     * @param array<string, mixed> $options
     * @return string Canonical package name
     */
    public static function resolvePackage(string $packageOrPath, array &$options = []): string
    {
        // 1. Explicit path in options takes precedence
        $explicitPath = $options['dir'] ?? $options['path'] ?? $options['app_path'] ?? null;
        if (!empty($explicitPath) && is_string($explicitPath)) {
            $resolvedDir = self::normalizeDirectoryPath($explicitPath);
            if ($resolvedDir !== null) {
                $manifest = self::readManifest($resolvedDir);
                $packageName = !empty($manifest['package']) ? (string) $manifest['package'] : $packageOrPath;
                if (!AppEngine::checkName($packageName)) {
                    $packageName = basename($resolvedDir);
                }

                self::registerApp($packageName, $resolvedDir);
                if ($packageName !== $packageOrPath && AppEngine::checkName($packageOrPath)) {
                    self::registerApp($packageOrPath, $resolvedDir);
                }
                self::$resolvedPaths[$packageName] = $resolvedDir;

                return $packageName;
            }
        }

        // 2. $packageOrPath itself is a directory or path containing app.php
        if (self::looksLikePath($packageOrPath)) {
            $resolvedDir = self::normalizeDirectoryPath($packageOrPath);
            if ($resolvedDir !== null) {
                $manifest = self::readManifest($resolvedDir);
                $packageName = !empty($manifest['package']) ? (string) $manifest['package'] : basename($resolvedDir);
                if (!AppEngine::checkName($packageName)) {
                    $packageName = basename($resolvedDir);
                }

                self::registerApp($packageName, $resolvedDir);
                self::$resolvedPaths[$packageName] = $resolvedDir;
                return $packageName;
            }
        }

        // 3. Already registered and exists in AppEngine (e.g. apps/ or dynamic arrayLoader)
        if (AppEngine::exists($packageOrPath)) {
            return $packageOrPath;
        }

        // 4. Cached resolution hit (avoids repeating filesystem scan)
        if (isset(self::$resolvedPaths[$packageOrPath]) && is_dir(self::$resolvedPaths[$packageOrPath])) {
            $cachedDir = self::$resolvedPaths[$packageOrPath];
            self::registerApp($packageOrPath, $cachedDir);
            return $packageOrPath;
        }

        // 5. Auto-detect from conventional host sub-directories or project structure
        $detectedPath = self::detectAppPath($packageOrPath);
        if ($detectedPath !== null) {
            $manifest = self::readManifest($detectedPath);
            $canonicalPackage = !empty($manifest['package']) ? (string) $manifest['package'] : $packageOrPath;

            self::registerApp($canonicalPackage, $detectedPath);
            if ($canonicalPackage !== $packageOrPath && AppEngine::checkName($packageOrPath)) {
                self::registerApp($packageOrPath, $detectedPath);
            }
            self::$resolvedPaths[$canonicalPackage] = $detectedPath;
            self::$resolvedPaths[$packageOrPath] = $detectedPath;

            return $canonicalPackage;
        }

        return $packageOrPath;
    }

    private static function looksLikePath(string $value): bool
    {
        return str_contains($value, '/')
            || str_contains($value, '\\')
            || str_starts_with($value, '.')
            || str_starts_with($value, '~')
            || (bool) preg_match('/^[A-Za-z]:/', $value)
            || is_dir($value);
    }

    private static function normalizeDirectoryPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);

        if ($isAbsolute) {
            if (is_dir($path)) {
                return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
            }
            return null;
        }

        // Relative to active host app
        try {
            $hostAppPath = App::path();
            if ($hostAppPath !== '' && is_dir($hostAppPath . '/' . $path)) {
                return rtrim(str_replace('\\', '/', realpath($hostAppPath . '/' . $path) ?: ($hostAppPath . '/' . $path)), '/');
            }
        } catch (Throwable) {
        }

        // Relative to project base path
        $basePath = (string) Loader::getBasePath();
        if ($basePath !== '' && is_dir($basePath . '/' . $path)) {
            return rtrim(str_replace('\\', '/', realpath($basePath . '/' . $path) ?: ($basePath . '/' . $path)), '/');
        }

        if (is_dir($path)) {
            return rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
        }

        return null;
    }

    private static function detectAppPath(string $package): ?string
    {
        $appFile = 'app.php';
        try {
            $appFile = AppEngine::getAppFile();
        } catch (Throwable) {
        }

        $candidates = [];
        $shortName = str_replace(['com_pinoox_', 'com_'], '', $package);

        // 1) Environment variables
        $envKeys = [
            'PINOOX_' . strtoupper($package) . '_PATH',
            'PINOOX_' . strtoupper($shortName) . '_PATH',
        ];
        foreach ($envKeys as $envKey) {
            $envVal = getenv($envKey);
            if (is_string($envVal) && trim($envVal) !== '') {
                $candidates[] = trim($envVal);
            }
        }

        // 2) AppDevRegistry (cross-app discovery via global ~/.pinoox/dev_apps.json)
        try {
            if (class_exists(\Pinoox\Component\Server\AppDevRegistry::class)) {
                $devPath = \Pinoox\Component\Server\AppDevRegistry::path($package);
                if (!empty($devPath)) {
                    $candidates[] = $devPath;
                }
            }
        } catch (\Throwable) {
        }

        // 3) Host app's sub_apps configuration (app.php)
        try {
            $hostPackage = App::package();
            if ($hostPackage && AppEngine::exists($hostPackage)) {
                $subApps = AppEngine::config($hostPackage)->get('sub_apps') ?? AppEngine::config($hostPackage)->get('router.sub_apps') ?? [];
                if (is_array($subApps)) {
                    foreach ($subApps as $key => $subAppConfig) {
                        if (is_array($subAppConfig)) {
                            $target = $subAppConfig['app'] ?? $subAppConfig['package'] ?? $subAppConfig['guest'] ?? null;
                            $customPath = $subAppConfig['path'] ?? $subAppConfig['app_path'] ?? null;
                            if (($target === $package || $target === $shortName) && !empty($customPath) && is_string($customPath)) {
                                $candidates[] = $customPath;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        // 4) Relative to active host app
        try {
            $hostApp = App::path();
            if ($hostApp !== '') {
                $candidates[] = $hostApp . '/sub_apps/' . $package;
                $candidates[] = $hostApp . '/sub-apps/' . $package;
                $candidates[] = $hostApp . '/apps/' . $package;
                $candidates[] = $hostApp . '/subapp/' . $package;
                $candidates[] = $hostApp . '/packages/' . $package;
                $candidates[] = $hostApp . '/modules/' . $package;
                $candidates[] = $hostApp . '/' . $package;
                $candidates[] = $hostApp . '/' . $shortName;
            }
        } catch (Throwable) {
        }

        // 5) Relative to project root / sibling directories
        $basePath = (string) Loader::getBasePath();
        if ($basePath !== '') {
            $candidates[] = $basePath . '/sub_apps/' . $package;
            $candidates[] = $basePath . '/sub-apps/' . $package;
            $candidates[] = $basePath . '/apps/' . $package;

            $parentDir = dirname($basePath);
            if ($parentDir !== '' && is_dir($parentDir)) {
                $candidates[] = $parentDir . '/' . $package;
                $candidates[] = $parentDir . '/' . $shortName;
                $candidates[] = $parentDir . '/apps/' . $package;
                $candidates[] = $parentDir . '/apps/' . $shortName;
            }
        }

        // 6) Platform standard apps directory
        try {
            $pathApps = AppEngine::getPathApps();
            if ($pathApps !== '') {
                $candidates[] = $pathApps . '/' . $package;
                $candidates[] = $pathApps . '/' . $shortName;
            }
        } catch (Throwable) {
        }

        try {
            if (function_exists('path')) {
                $p1 = path('~apps/' . $package);
                if ($p1 !== '') $candidates[] = $p1;
                $p2 = path('apps/' . $package);
                if ($p2 !== '') $candidates[] = $p2;
            }
        } catch (Throwable) {
        }

        foreach ($candidates as $candidate) {
            $candidateNorm = rtrim(str_replace('\\', '/', $candidate), '/');
            if (is_dir($candidateNorm) && is_file($candidateNorm . '/' . $appFile)) {
                return realpath($candidateNorm) ? rtrim(str_replace('\\', '/', realpath($candidateNorm)), '/') : $candidateNorm;
            }
        }

        return null;
    }

    private static function readManifest(string $dir): ?array
    {
        $appFile = 'app.php';
        try {
            $appFile = AppEngine::getAppFile();
        } catch (Throwable) {
        }

        $file = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $appFile;
        if (array_key_exists($file, self::$manifestCache)) {
            return self::$manifestCache[$file];
        }

        if (is_file($file)) {
            try {
                $data = include $file;
                if (is_array($data)) {
                    return self::$manifestCache[$file] = $data;
                }
            } catch (Throwable) {
            }
        }

        return self::$manifestCache[$file] = null;
    }

    private static function registerApp(string $package, string $path): void
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        try {
            App::addPackage($package, $path);
        } catch (Throwable) {
            AppEngine::add($package, $path);
        }
    }
}
