<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Portal\Event;

final class AppCoreEventDispatcher
{
    public static function dispatch(object $event, string $baseName, string $package, ?string $namedChannel = null): void
    {
        Event::dispatch($event, $baseName);

        if ($package !== '') {
            Event::dispatch($event, AppEventNames::package($baseName, $package));
        }

        if ($namedChannel === null || $namedChannel === '') {
            return;
        }

        Event::dispatch($event, $namedChannel);

        if ($package !== '') {
            Event::dispatch($event, AppEventNames::package($namedChannel, $package));
        }
    }
}
