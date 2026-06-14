<?php

namespace Pinoox\Component\Kernel\Listener;

use Pinoox\Component\AppEvent\AppControllerEvent;
use Pinoox\Component\AppEvent\AppCoreEventDispatcher;
use Pinoox\Component\AppEvent\AppEventNames;
use Pinoox\Component\AppEvent\AppExceptionEvent;
use Pinoox\Component\AppEvent\AppResponseEvent;
use Pinoox\Component\AppEvent\AppRouteMatchedEvent;
use Pinoox\Component\AppEvent\AppTerminateEvent;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Kernel;
use Pinoox\Component\Router\Route;
use Pinoox\Portal\App\App;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AppCoreEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            Kernel::HANDLE_BEFORE => ['onRouteMatched', 30],
            KernelEvents::CONTROLLER => ['onController', 16],
            KernelEvents::RESPONSE => ['onResponse', 0],
            KernelEvents::EXCEPTION => ['onException', 16],
            KernelEvents::TERMINATE => ['onTerminate', 0],
        ];
    }

    public function onRouteMatched(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        $route = $request->attributes->get('_router');
        if (!$route instanceof Route) {
            return;
        }

        $package = (string) (App::package() ?? '');
        if ($package === '') {
            return;
        }

        $routeName = $route->getName();
        $namedChannel = $routeName !== ''
            ? ($request->getPathInfo() !== '' && str_starts_with($request->getPathInfo(), '/api/')
                ? AppEventNames::apiRoute($routeName)
                : AppEventNames::route($routeName))
            : null;

        AppCoreEventDispatcher::dispatch(
            new AppRouteMatchedEvent($package, $request, $route),
            AppEventNames::ROUTE_MATCHED,
            $package,
            $namedChannel,
        );
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        $package = (string) (App::package() ?? '');
        if ($package === '') {
            return;
        }

        [$class, $method] = $this->normalizeController($event->getController());
        $route = $request->attributes->get('_router');
        $route = $route instanceof Route ? $route : null;

        $namedChannel = $class !== null
            ? AppEventNames::controller($class, $method)
            : null;

        AppCoreEventDispatcher::dispatch(
            new AppControllerEvent(
                $package,
                $request,
                $event->getController(),
                $class,
                $method,
                $route,
            ),
            AppEventNames::CONTROLLER,
            $package,
            $namedChannel,
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        $package = (string) (App::package() ?? '');
        if ($package === '') {
            return;
        }

        $route = $request->attributes->get('_router');
        $route = $route instanceof Route ? $route : null;

        AppCoreEventDispatcher::dispatch(
            new AppResponseEvent($package, $request, $event->getResponse(), $route),
            AppEventNames::RESPONSE,
            $package,
        );
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        $package = (string) (App::package() ?? '');
        if ($package === '') {
            return;
        }

        $route = $request->attributes->get('_router');
        $route = $route instanceof Route ? $route : null;

        AppCoreEventDispatcher::dispatch(
            new AppExceptionEvent($package, $request, $event->getThrowable(), $route),
            AppEventNames::EXCEPTION,
            $package,
        );
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request instanceof Request) {
            return;
        }

        $package = (string) (App::package() ?? '');
        if ($package === '') {
            return;
        }

        AppCoreEventDispatcher::dispatch(
            new AppTerminateEvent($package, $request, $event->getResponse()),
            AppEventNames::TERMINATE,
            $package,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizeController(mixed $controller): array
    {
        if (is_array($controller) && isset($controller[0])) {
            $class = is_object($controller[0]) ? $controller[0]::class : (string) $controller[0];

            return [$class, isset($controller[1]) ? (string) $controller[1] : null];
        }

        if (is_object($controller)) {
            return [$controller::class, null];
        }

        return [null, null];
    }
}
