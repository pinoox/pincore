<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Event\Event;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Support\Event\Dispatchable;
use Throwable;

class AppExceptionEvent extends Event
{
    use Dispatchable;

    public static $eventName = AppEventNames::EXCEPTION;

    public function __construct(
        public readonly string $package,
        public readonly Request $request,
        public readonly Throwable $throwable,
        public readonly ?Route $route = null,
    ) {
    }
}
