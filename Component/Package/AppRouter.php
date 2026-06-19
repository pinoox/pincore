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

use Exception;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\Engine\EngineInterface;
use Pinoox\Component\Package\Routing\AppResolution;
use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Component\Package\Routing\Domain;
use Pinoox\Component\Package\Routing\DomainMatch;
use Pinoox\Component\Package\Routing\FrontControllerAppResolver;
use Pinoox\Component\Server\ServeAppBinding;
use Pinoox\Component\Store\Config\ConfigInterface;

class AppRouter
{
    private ?AppLayer $resolved = null;

    public function __construct(
        private ConfigInterface $appRouteConfig,
        private EngineInterface $appEngine,
        private Request         $request,
    )
    {
    }

    public function setDefault(string $packageName): void
    {
        $this->set('*', $packageName);
    }

    /**
     * Resolve the active app for the current HTTP request.
     *
     * Order:
     * 1. Host mapping from {project}/config/domain.config.php (explicit hosts only)
     * 2. Longest path prefix from {project}/config/app-router.config.php
     * 3. Default route "/"
     * 4. Wildcard route "*"
     * 5. Unresolved app (help page: not configured / missing / disabled)
     *
     * Any host that is not listed in domain.config.php is treated as the
     * default domain and follows path routing. Configure `default` for the
     * canonical public hostname used by CLI/docs.
     */
    public function find(string|null $url = null): AppLayer
    {
        $pathInfo = $url ?? $this->requestPathInfo();
        $host = $this->host();
        $routes = $this->routes();
        $domainContext = $this->domainContext($host);

        $serveApp = ServeAppBinding::resolveLayer(
            $routes,
            fn (string $package): bool => $this->stable($package),
        );

        if ($serveApp !== null) {
            return $this->resolved = new AppLayer(
                $serveApp->getPath(),
                $serveApp->getPackageName(),
                array_merge($domainContext, $serveApp->context()),
            );
        }

        $domainMatch = Domain::match($host);
        if ($domainMatch !== null && $this->stable($domainMatch->package)) {
            return $this->resolved = $this->layerFromDomain($domainMatch);
        }

        $normalizedPath = AppRouteMatcher::normalize(
            $pathInfo === '' ? '/' : '/' . ltrim($pathInfo, '/'),
        );

        $frontController = FrontControllerAppResolver::resolve($this, $normalizedPath);

        if ($frontController !== null) {
            return $this->resolved = new AppLayer(
                $frontController->getPath(),
                $frontController->getPackageName(),
                array_merge($domainContext, $frontController->context()),
            );
        }

        $pathMatch = AppRouteMatcher::match($pathInfo, $routes, fn(string $package): bool => $this->stable($package));
        if ($pathMatch !== null) {
            return $this->resolved = new AppLayer(
                $pathMatch['path'],
                $pathMatch['package'],
                array_merge($domainContext, [
                    'matched_by' => Domain::isDefaultHost($host) ? 'default_domain' : 'path',
                ]),
            );
        }

        if (isset($routes['*']) && $this->stable($routes['*'])) {
            return $this->resolved = new AppLayer('/', $routes['*'], array_merge($domainContext, [
                'matched_by' => Domain::isDefaultHost($host) ? 'default_domain' : 'wildcard',
            ]));
        }

        if (isset($routes['/']) && $this->stable($routes['/'])) {
            return $this->resolved = new AppLayer('/', $routes['/'], array_merge($domainContext, [
                'matched_by' => Domain::isDefaultHost($host) ? 'default_domain' : 'default',
            ]));
        }

        return $this->resolved = $this->resolveUnresolved(
            $pathInfo,
            $routes,
            $domainMatch,
            $normalizedPath,
            $domainContext,
        );
    }

    public function resolved(): ?AppLayer
    {
        return $this->resolved;
    }

    public function host(): string
    {
        return Domain::normalizeHost($this->request->getHost());
    }

    public function subdomain(): ?string
    {
        return $this->resolved?->subdomain() ?? Domain::match($this->host())?->subdomain;
    }

    public function domainMatch(): ?DomainMatch
    {
        return Domain::match($this->host());
    }

    public function stable(string $packageName): bool
    {
        if (!$this->appEngine->exists($packageName)) {
            return false;
        }

        try {
            return (bool)$this->appEngine->config($packageName)->get('enable');
        } catch (Exception) {
        }

        return false;
    }

    public function set(string $url, string $packageName): void
    {
        $url = $url === '*' ? '*' : AppRouteMatcher::normalize($url);

        $this->appRouteConfig
            ->set($url, $packageName)
            ->save();
    }

    public function delete(string $url): void
    {
        $url = $url === '*' ? '*' : AppRouteMatcher::normalize($url);

        $this->appRouteConfig
            ->remove($url)
            ->save();
    }

    public function deletePackage(string $packageName): void
    {
        $routes = $this->routes();
        foreach (array_keys($routes, $packageName, true) as $key) {
            unset($routes[$key]);
        }

        $this->appRouteConfig
            ->setData($routes)
            ->save();
    }

    public function get(?string $value = null, mixed $default = null): mixed
    {
        return $this->appRouteConfig->get($value, $default);
    }

    public function setData(mixed $data = null): void
    {
        $routes = is_array($data) ? AppRouteMatcher::normalizeRoutes($data) : [];

        $this->appRouteConfig
            ->setData($routes)
            ->save();
    }

    /**
     * @return array<string, string>
     */
    public function routes(): array
    {
        $routes = $this->get();

        return is_array($routes) ? AppRouteMatcher::normalizeRoutes($routes) : [];
    }

    /**
     * @return array<string, string>
     */
    public function getByPackage(string $packageName): array
    {
        return array_filter(
            $this->routes(),
            static fn(string $routePackage): bool => $routePackage === $packageName,
        );
    }

    public function exists(string $url): bool
    {
        $url = $url === '*' ? '*' : AppRouteMatcher::normalize($url);

        return !empty($this->get($url));
    }

    public function existByPackage(string $packageName): bool
    {
        return $this->getByPackage($packageName) !== [];
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->resolved = null;
    }

    public function config(): ConfigInterface
    {
        return $this->appRouteConfig;
    }

    private function requestPathInfo(): string
    {
        return trim($this->request->getPathInfo(), '/');
    }

    private function layerFromDomain(DomainMatch $match): AppLayer
    {
        return new AppLayer(
            $match->path,
            $match->package,
            array_merge($this->domainContext($match->host), [
                'matched_by' => 'domain',
                'subdomain' => $match->subdomain,
                'domain_pattern' => $match->pattern,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function domainContext(string $host): array
    {
        $context = [
            'host' => $host,
            'is_default_domain' => Domain::isDefaultHost($host),
        ];

        $canonical = Domain::defaultHost();
        if ($canonical !== null) {
            $context['canonical_default_host'] = $canonical;
            $context['is_canonical_default'] = Domain::isCanonicalDefaultHost($host);
        }

        return $context;
    }

    /**
     * @param array<string, string> $routes
     */
    private function resolveUnresolved(
        string $pathInfo,
        array $routes,
        ?DomainMatch $domainMatch,
        string $normalizedPath,
        array $domainContext,
    ): AppLayer {
        $binding = $this->findConfiguredBinding($pathInfo, $routes, $domainMatch);

        if ($binding === null) {
            return $this->makeUnresolvedLayer(
                path: $normalizedPath,
                package: null,
                reason: AppResolution::NOT_CONFIGURED,
                domainContext: $domainContext,
                extras: [
                    'matched_by' => 'none',
                    'request_path' => $normalizedPath,
                ],
            );
        }

        $package = $binding['package'];
        $reason = AppResolution::NOT_CONFIGURED;

        if ($package !== '') {
            if (!$this->appEngine->exists($package)) {
                $reason = AppResolution::APP_MISSING;
            } elseif (!$this->stable($package)) {
                $reason = AppResolution::APP_DISABLED;
            }
        }

        $extras = [
            'matched_by' => $binding['matched_by'] ?? 'none',
            'request_path' => $normalizedPath,
        ];

        if (isset($binding['subdomain'])) {
            $extras['subdomain'] = $binding['subdomain'];
        }

        if (isset($binding['domain_pattern'])) {
            $extras['domain_pattern'] = $binding['domain_pattern'];
        }

        return $this->makeUnresolvedLayer(
            path: $binding['path'],
            package: $package !== '' ? $package : null,
            reason: $reason,
            domainContext: $domainContext,
            extras: $extras,
        );
    }

    /**
     * @param array<string, string> $routes
     * @return array{path: string, package: string, matched_by: string, subdomain?: string, domain_pattern?: string}|null
     */
    private function findConfiguredBinding(
        string $pathInfo,
        array $routes,
        ?DomainMatch $domainMatch,
    ): ?array {
        if ($domainMatch !== null) {
            return [
                'path' => $domainMatch->path,
                'package' => $domainMatch->package,
                'matched_by' => 'domain',
                'subdomain' => $domainMatch->subdomain,
                'domain_pattern' => $domainMatch->pattern,
            ];
        }

        $pathMatch = AppRouteMatcher::match($pathInfo, $routes);

        if ($pathMatch !== null) {
            return [
                'path' => $pathMatch['path'],
                'package' => $pathMatch['package'],
                'matched_by' => Domain::isDefaultHost($this->host()) ? 'default_domain' : 'path',
            ];
        }

        if (isset($routes['*'])) {
            return [
                'path' => '/',
                'package' => $routes['*'],
                'matched_by' => Domain::isDefaultHost($this->host()) ? 'default_domain' : 'wildcard',
            ];
        }

        if (isset($routes['/'])) {
            return [
                'path' => '/',
                'package' => $routes['/'],
                'matched_by' => Domain::isDefaultHost($this->host()) ? 'default_domain' : 'default',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $domainContext
     * @param array<string, mixed> $extras
     */
    private function makeUnresolvedLayer(
        string $path,
        ?string $package,
        string $reason,
        array $domainContext,
        array $extras = [],
    ): AppLayer {
        return new AppLayer($path, AppResolution::BOOTSTRAP_PACKAGE, array_merge($domainContext, $extras, [
            'resolution' => $reason,
            'configured_package' => $package,
            'configured_path' => $path,
        ]));
    }
}

