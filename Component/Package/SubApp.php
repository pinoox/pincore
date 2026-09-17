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

use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\Response;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Transport\TransportConfig;
use Pinoox\Component\Transport\TransportContext;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Auth;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use RuntimeException;
use Throwable;

class SubApp
{
    /**
     * Run a sub-app under a given mount path and return the response.
     *
     * @param string $package Guest package name or directory path
     * @param string $mountPath Relative mount path (e.g. '/pay' or 'app/com_test')
     * @param Request|null $request The incoming HTTP request (or active request if null)
     * @param array<string, mixed> $options Options: config, share_auth, attributes, throw_on_error, path
     * @return Response
     * @throws RuntimeException
     */
    public static function run(
        string $package,
        string $mountPath = '/',
        ?Request $request = null,
        array $options = [],
    ): Response {
        $package = self::resolvePackage($package, $options);

        if (!AppEngine::exists($package)) {
            throw new RuntimeException("SubApp package '{$package}' not found.");
        }

        $hostPackage = App::package() ?? '';

        if (!self::canMount($package, $hostPackage)) {
            throw new RuntimeException("Host app '{$hostPackage}' is not allowed to mount sub-app '{$package}'.");
        }

        $hostRoute = (string) App::pathRoute();
        $mountPath = trim($mountPath, '/');
        $layerPath = $mountPath === ''
            ? ($hostRoute === '' ? '/' : $hostRoute)
            : rtrim($hostRoute, '/') . '/' . $mountPath;

        $shareAuth = $options['share_auth'] ?? TransportConfig::sharesAuthWith($package, $hostPackage);

        if (!$shareAuth) {
            Auth::reset();
        } else {
            Auth::boot();
        }

        $hasConfig = !empty($options['config']) && is_array($options['config']);
        if ($hasConfig) {
            AppEngine::pushConfig($package, $options['config']);
        }

        $request ??= AppProvider::getRequest();
        $subRequest = $request->duplicate();
        $subRequest->clearSession();
        $subRequest->attributes = new ParameterBag();

        try {
            $response = App::meeting($package, static function () use ($subRequest, $options) {
                $attributes = $options['attributes'] ?? [];
                if ($attributes === []) {
                    $attributes = App::router()->matchRequest($subRequest);
                }

                $subRequest->attributes->add($attributes);

                return AppProvider::handle($subRequest, HttpKernelInterface::SUB_REQUEST);
            }, $layerPath);

            return $response instanceof Response
                ? $response
                : new Response((string) $response->getContent(), $response->getStatusCode(), $response->headers->all());
        } catch (Throwable $e) {
            if (!empty($options['throw_on_error'])) {
                throw $e;
            }
            throw new RuntimeException("Error executing sub-app '{$package}': " . $e->getMessage(), 0, $e);
        } finally {
            if ($hasConfig) {
                AppEngine::popConfig($package);
            }
        }
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
     * Read a context value for the active app layer.
     */
    public static function context(?string $key = null, mixed $default = null): mixed
    {
        return App::context($key, $default);
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
        $explicitPath = $options['path'] ?? $options['app_path'] ?? null;
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
                return $packageName;
            }
        }

        // 3. Already registered and exists in AppEngine (e.g. apps/ or dynamic arrayLoader)
        if (AppEngine::exists($packageOrPath)) {
            return $packageOrPath;
        }

        // 4. Auto-detect from conventional host sub-directories or project structure
        $detectedPath = self::detectAppPath($packageOrPath);
        if ($detectedPath !== null) {
            $manifest = self::readManifest($detectedPath);
            $canonicalPackage = !empty($manifest['package']) ? (string) $manifest['package'] : $packageOrPath;

            self::registerApp($canonicalPackage, $detectedPath);
            if ($canonicalPackage !== $packageOrPath && AppEngine::checkName($packageOrPath)) {
                self::registerApp($packageOrPath, $detectedPath);
            }

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

        // 1) Relative to active host app
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
            }
        } catch (Throwable) {
        }

        // 2) Relative to project root
        $basePath = (string) Loader::getBasePath();
        if ($basePath !== '') {
            $candidates[] = $basePath . '/sub_apps/' . $package;
            $candidates[] = $basePath . '/sub-apps/' . $package;
            $candidates[] = $basePath . '/apps/' . $package;
        }

        // 3) AppEngine pathApps
        try {
            $pathApps = AppEngine::getPathApps();
            if ($pathApps !== '') {
                $candidates[] = $pathApps . '/' . $package;
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
        if (is_file($file)) {
            try {
                $data = include $file;
                if (is_array($data)) {
                    return $data;
                }
            } catch (Throwable) {
            }
        }

        return null;
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
