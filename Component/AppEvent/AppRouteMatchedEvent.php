<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Event\Event;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Support\Event\Dispatchable;

class AppRouteMatchedEvent extends Event
{
    use Dispatchable;

    public static $eventName = AppEventNames::ROUTE_MATCHED;

    public function __construct(
        public readonly string $package,
        public readonly Request $request,
        public readonly Route $route,
    ) {
    }

    public function routeName(): string
    {
        return $this->route->getName();
    }

    public function isApi(): bool
    {
        return str_starts_with($this->request->getPathInfo(), '/api/');
    }
}
