<?php

namespace Pinoox\Component\Kernel\Listener;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Kernel;
use Pinoox\Component\Package\AppLayer;
use Pinoox\Component\Package\Routing\AppResolution;
use Pinoox\Portal\App\App as AppPortal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppRouter;
use Pinoox\Portal\Lang;
use Pinoox\Portal\Path;
use Pinoox\Portal\View;
use Pinoox\Support\StaticAssetRequest;
use Pinoox\Support\SystemConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Pinoox\Component\Http\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class NoAppListener implements EventSubscriberInterface
{
    private const MANAGER_PACKAGE = 'com_pinoox_manager';

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request instanceof Request) {
            return;
        }

        if (StaticAssetRequest::shouldReturnPlainNotFound($request)) {
            return;
        }

        $layer = AppPortal::current();

        if (!$layer->isUnresolved()) {
            return;
        }

        $event->setResponse($this->createResponse($request, $layer));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Kernel::HANDLE_BEFORE => ['onKernelRequest', 40],
        ];
    }

    private function createResponse(Request $request, AppLayer $layer): Response
    {
        $resolution = (string) ($layer->resolution() ?? AppResolution::NOT_CONFIGURED);
        $locales = $this->availableLocales();
        $locale = $this->resolveLocale($request, $locales);

        Lang::setLocale($locale);

        View::changeTheme(Path::get('~pincore/resource/views/no-app/'));

        return new Response(View::render('home', array_merge(
            $this->viewContext($layer, $resolution),
            [
                'locale' => $locale,
                'dir' => (string) Lang::get('no-app.meta.dir', [], $locale, false),
                'locales' => $locales,
            ],
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewContext(AppLayer $layer, string $resolution): array
    {
        $routePath = $this->normalizeRoutePath(
            $layer->configuredPath() ?? (string) ($layer->context('request_path') ?? '/'),
        );
        $routePackage = $layer->configuredPackage();

        return [
            'resolution' => $resolution,
            'routePath' => $routePath,
            'routePackage' => $routePackage,
            'cliExample' => $this->cliExample($resolution, $routePath, $routePackage),
            'managerRoutesUrl' => $this->managerControlUrl('/control/routes'),
            'managerAppsUrl' => $this->managerControlUrl('/control/apps'),
        ];
    }

    private function normalizeRoutePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }

    private function cliExample(string $resolution, string $routePath, ?string $routePackage): string
    {
        $path = $this->cliPath($routePath);

        return match ($resolution) {
            AppResolution::APP_MISSING => $routePackage !== null
                ? 'php pinoox app:router set ' . $path . ' ' . $routePackage
                : 'php pinoox app:router',
            AppResolution::APP_DISABLED => 'php pinoox app:router remove ' . $path,
            default => 'php pinoox app:router set ' . $path . ' com_vendor_myapp',
        };
    }

    private function cliPath(string $routePath): string
    {
        return $routePath === '/' ? '/' : $routePath;
    }

    private function managerControlUrl(string $suffix): ?string
    {
        if (!AppEngine::exists(self::MANAGER_PACKAGE) || !AppEngine::stable(self::MANAGER_PACKAGE)) {
            return null;
        }

        $mount = $this->managerMountPath();

        if ($mount === null) {
            return null;
        }

        return rtrim($mount, '/') . $suffix;
    }

    private function managerMountPath(): ?string
    {
        $routes = AppRouter::routes();
        $candidates = [];

        foreach ($routes as $path => $package) {
            if ($package !== self::MANAGER_PACKAGE || !is_string($path)) {
                continue;
            }

            if (in_array($path, ['*', '/'], true)) {
                continue;
            }

            $candidates[] = $path;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->normalizeRoutePath($candidates[0]);
    }

    private function availableLocales(): array
    {
        $locales = [];
        $pattern = SystemConfig::path('platform_lang') . '/*/no-app.lang.php';

        foreach (glob($pattern) ?: [] as $file) {
            $code = basename(dirname($file));

            if (!preg_match('/^[a-z]{2}$/', $code)) {
                continue;
            }

            $data = require $file;
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $locales[$code] = (string) ($meta['name'] ?? strtoupper($code));
        }

        if ($locales === []) {
            return ['en' => 'English'];
        }

        ksort($locales);

        return $locales;
    }

    private function resolveLocale(Request $request, array $locales): string
    {
        $available = array_keys($locales);
        $requested = $request->query->get('lang');

        if (is_string($requested) && in_array($requested, $available, true)) {
            return $requested;
        }

        $current = Lang::getLocale();

        if (in_array($current, $available, true)) {
            return $current;
        }

        return $available[0] ?? 'en';
    }
}
