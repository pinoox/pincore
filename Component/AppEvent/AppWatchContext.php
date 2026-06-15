<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Portal\App\App;

/**
 * Context passed to boot watch handlers (route, API, controller, theme, …).
 */

class AppWatchContext
{
    /**
     * @param list<string>|null $themeStack
     */
    public function __construct(
        public readonly Request $request,
        public readonly ?Route $route = null,
        public readonly mixed $controller = null,
        public readonly ?string $controllerMethod = null,
        public readonly ?string $modelClass = null,
        public readonly ?string $modelEvent = null,
        public readonly mixed $model = null,
        public readonly ?string $themeContext = null,
        public readonly ?string $themeName = null,
        public readonly ?array $themeStack = null,
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
    public function themeContext(): ?string
    {
        return $this->themeContext;
    }
    public function themeName(): ?string
    {
        return $this->themeName;
    }
    /**
     * @return list<string>
     */
    public function themeStack(): array
    {
        return $this->themeStack ?? [];
    }
}
