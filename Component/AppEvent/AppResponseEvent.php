<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Event\Event;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Support\Event\Dispatchable;
use Symfony\Component\HttpFoundation\Response;

class AppResponseEvent extends Event
{
    use Dispatchable;

    public static $eventName = AppEventNames::RESPONSE;

    public function __construct(
        public readonly string $package,
        public readonly Request $request,
        public readonly Response $response,
        public readonly ?Route $route = null,
    ) {
    }
}
