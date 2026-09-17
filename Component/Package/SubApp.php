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
     * @param string $package Guest package name
     * @param string $mountPath Relative mount path (e.g. '/pay' or 'app/com_test')
     * @param Request|null $request The incoming HTTP request (or active request if null)
     * @param array<string, mixed> $options Options: config, share_auth, attributes, throw_on_error
     * @return Response
     * @throws RuntimeException
     */
    public static function run(
        string $package,
        string $mountPath = '/',
        ?Request $request = null,
        array $options = [],
    ): Response {
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
     * Check if a guest app can be mounted by the host app.
     */
    public static function canMount(string $guestPackage, ?string $hostPackage = null): bool
    {
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
    public static function isSubAppOnly(string $packageName): bool
    {
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
}
