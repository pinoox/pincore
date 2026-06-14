<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Portal\App\App;

/**
 * Context passed to boot watch handlers (route, API, controller, …).
 */
class AppWatchContext
{
    public function __construct(
        public readonly Request $request,
        public readonly ?Route $route = null,
        public readonly mixed $controller = null,
        public readonly ?string $controllerMethod = null,
        public readonly ?string $modelClass = null,
        public readonly ?string $modelEvent = null,
        public readonly mixed $model = null,
    ) {
    }

    public function package(): string
    {
        return (string) (App::package() ?? '');
    }

    public function routeName(): ?string
    {
        return $this->route?->getName() ?: null;
    }

    public function path(): string
    {
        return $this->request->getPathInfo();
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path(), '/api/');
    }

    public function isRoute(string $name): bool
    {
        return $this->routeName() === $name;
    }
}
