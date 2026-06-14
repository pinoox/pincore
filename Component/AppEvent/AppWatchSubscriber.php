<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Action\ActionReference;
use Pinoox\Component\Router\Route;
use Pinoox\Component\Template\Theme\ThemeContext;
use Pinoox\Component\Template\Theme\ThemeStack;
use Pinoox\Portal\App\App;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AppWatchSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AppEventNames::ROUTE_MATCHED => ['onRouteMatched', 0],
            AppEventNames::CONTROLLER => ['onController', -8],
        ];
    }

    public function onRouteMatched(AppRouteMatchedEvent $event): void
    {
        $request = $event->request;
        $route = $event->route;
        $activePackage = $event->package;

        foreach (AppWatchRegistry::rules() as $rule) {
            $kind = $rule['kind'] ?? '';
            if (!in_array($kind, ['route', 'api', 'path', 'action'], true)) {
                continue;
            }

            if (!$this->packageMatches($rule, $activePackage)) {
                continue;
            }

            if ($kind === 'route' && !$this->routeNameMatches($rule, $route, $request->getPathInfo(), api: false)) {
                continue;
            }

            if ($kind === 'api' && !$this->routeNameMatches($rule, $route, $request->getPathInfo(), api: true)) {
                continue;
            }

            if ($kind === 'path' && !$this->pathMatches($rule, $request->getPathInfo())) {
                continue;
            }

            if ($kind === 'action' && !$this->actionMatches($rule, $request, $route)) {
                continue;
            }

            $this->invoke($rule['handler'] ?? null, new AppWatchContext(
                request: $request,
                route: $route,
            ));
        }
    }

    public function onController(AppControllerEvent $event): void
    {
        $request = $event->request;
        $route = $event->route;
        $activePackage = $event->package;

        foreach (AppWatchRegistry::rules() as $rule) {
            if (($rule['kind'] ?? '') !== 'controller') {
                continue;
            }

            if (!$this->packageMatches($rule, $activePackage)) {
                continue;
            }

            if (!$this->controllerMatches($rule, $event->controllerClass, $event->controllerMethod)) {
                continue;
            }

            $this->invoke($rule['handler'] ?? null, new AppWatchContext(
                request: $request,
                route: $route,
                controller: $event->controller,
                controllerMethod: $event->controllerMethod,
            ));
        }

        AppWatchRegistry::dispatchTheme($activePackage);
    }

    /**
     * @param array<string, mixed> $stack
     */
    public function dispatchTheme(string $package, ?string $themeContext, array $stack): void
    {
        if ($stack === []) {
            return;
        }

        $request = $this->resolveRequest($package);
        $route = $request->attributes->get('_router');
        $route = $route instanceof Route ? $route : null;

        foreach (AppWatchRegistry::rules() as $rule) {
            if (($rule['kind'] ?? '') !== 'theme') {
                continue;
            }

            if (!$this->packageMatches($rule, $package)) {
                continue;
            }

            if (!$this->themeMatches($rule, $themeContext, $stack)) {
                continue;
            }

            $this->invoke($rule['handler'] ?? null, new AppWatchContext(
                request: $request,
                route: $route,
                themeContext: $themeContext,
                themeName: (string) ($stack['name'] ?? ''),
                themeStack: is_array($stack['stack'] ?? null) ? $stack['stack'] : [],
            ));
        }
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $stack
     */
    private function themeMatches(array $rule, ?string $themeContext, array $stack): bool
    {
        $names = $this->normalizeMatchList($rule['match'] ?? null);
        if ($names === []) {
            return false;
        }

        $candidates = array_filter([
            $themeContext,
            $stack['name'] ?? null,
            ...(is_array($stack['stack'] ?? null) ? $stack['stack'] : []),
        ], static fn ($value) => is_string($value) && $value !== '');

        foreach ($names as $name) {
            if (in_array($name, $candidates, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRequest(string $package): Request
    {
        try {
            $request = App::getRequest();
            if ($request instanceof Request) {
                return $request;
            }
        } catch (\Throwable) {
        }

        return Request::create('/');
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function packageMatches(array $rule, string $activePackage): bool
    {
        $filter = $rule['package'] ?? null;
        if ($filter === null || $filter === '') {
            return true;
        }

        return $filter === $activePackage;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function routeNameMatches(array $rule, ?Route $route, string $path, bool $api): bool
    {
        if ($route === null) {
            return false;
        }

        $names = $this->normalizeMatchList($rule['match'] ?? null);
        if ($names === []) {
            return false;
        }

        if (!in_array($route->getName(), $names, true)) {
            return false;
        }

        return $api ? str_starts_with($path, '/api/') : !str_starts_with($path, '/api/');
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function pathMatches(array $rule, string $path): bool
    {
        $patterns = $this->normalizeMatchList($rule['match'] ?? null);
        foreach ($patterns as $pattern) {
            if ($pattern === $path) {
                return true;
            }

            if (str_ends_with($pattern, '*') && str_starts_with($path, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function actionMatches(array $rule, Request $request, ?Route $route): bool
    {
        $names = $this->normalizeMatchList($rule['match'] ?? null);
        if ($names === []) {
            return false;
        }

        if ($route !== null && in_array($route->getName(), $names, true)) {
            return true;
        }

        $controller = $request->attributes->get('_controller');
        if (!is_string($controller)) {
            return false;
        }

        foreach ($names as $name) {
            $normalized = ltrim($name, '@');
            if ($controller === $name || $controller === '@' . $normalized || $controller === '&' . $normalized) {
                return true;
            }

            if (ActionReference::isReference($controller)) {
                $parsed = ActionReference::parse($controller);
                if ($parsed !== null && $parsed['short'] === $normalized) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function controllerMatches(array $rule, ?string $class, ?string $method): bool
    {
        $target = $rule['match'] ?? null;

        if (is_string($target)) {
            return $class === $target || is_a($class, $target, true);
        }

        if (!is_array($target) || !isset($target[0])) {
            return false;
        }

        $expectedClass = $target[0];
        $expectedMethod = $target[1] ?? null;

        if ($class !== $expectedClass && !is_a($class, $expectedClass, true)) {
            return false;
        }

        if ($expectedMethod === null) {
            return true;
        }

        return $method === $expectedMethod;
    }

    /**
     * @return list<string>
     */
    private function normalizeMatchList(mixed $match): array
    {
        if (is_string($match) && $match !== '') {
            return [$match];
        }

        if (!is_array($match)) {
            return [];
        }

        return array_values(array_filter($match, static fn ($value) => is_string($value) && $value !== ''));
    }

    private function invoke(mixed $handler, AppWatchContext $context): void
    {
        if (!is_callable($handler)) {
            return;
        }

        $handler($context);
    }
}
