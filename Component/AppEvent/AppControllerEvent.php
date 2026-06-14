<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Event\Event;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Support\Event\Dispatchable;

class AppControllerEvent extends Event
{
    use Dispatchable;

    public static $eventName = AppEventNames::CONTROLLER;

    public function __construct(
        public readonly string $package,
        public readonly Request $request,
        public readonly mixed $controller,
        public readonly ?string $controllerClass,
        public readonly ?string $controllerMethod,
        public readonly ?Route $route = null,
    ) {
    }
}
