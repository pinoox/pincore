<?php

namespace Pinoox\Component\Kernel\Listener;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Kernel;
use Pinoox\Component\Package\Routing\AppResolution;
use Pinoox\Portal\App\App as AppPortal;
use Pinoox\Portal\Lang;
use Pinoox\Portal\Path;
use Pinoox\Portal\View;
use Pinoox\Support\StaticAssetRequest;
use Pinoox\Support\SystemApp;
use Pinoox\Support\SystemConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Pinoox\Component\Http\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class NoAppListener implements EventSubscriberInterface
{
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

    private function createResponse(Request $request, \Pinoox\Component\Package\AppLayer $layer): Response
    {
        $resolution = (string) ($layer->resolution() ?? AppResolution::NOT_CONFIGURED);
        $locales = $this->availableLocales();
        $locale = $this->resolveLocale($request, $locales);

        Lang::setLocale($locale);

        View::changeTheme('no-app', Path::get('~pincore/resource/views/no-app/'));

        return new Response(View::render('home', [
            'resolution' => $resolution,
            'configuredPackage' => $layer->configuredPackage(),
            'configuredPath' => $layer->configuredPath(),
            'requestPath' => (string) ($layer->context('request_path') ?? '/'),
            'host' => (string) ($layer->host() ?? $request->getHost()),
            'routerConfigFile' => 'config/app-router.config.php',
            'routerConfigPath' => SystemApp::existingPath('app-router.config.php'),
            'locale' => $locale,
            'dir' => (string) Lang::get('no-app.meta.dir', [], $locale, false),
            'locales' => $locales,
        ]));
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
