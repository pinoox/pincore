<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Event\Event;
use Pinoox\Component\Http\Request;
use Pinoox\Support\Event\Dispatchable;
use Symfony\Component\HttpFoundation\Response;

class AppTerminateEvent extends Event
{
    use Dispatchable;

    public static $eventName = AppEventNames::TERMINATE;

    public function __construct(
        public readonly string $package,
        public readonly Request $request,
        public readonly Response $response,
    ) {
    }
}
