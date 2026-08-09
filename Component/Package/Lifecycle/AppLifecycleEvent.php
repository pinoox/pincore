<?php

namespace Pinoox\Component\Package\Lifecycle;

use Pinoox\Component\AppEvent\AppEventNames;
use Pinoox\Component\Event\Event;
use Pinoox\Support\Event\Dispatchable;

class AppLifecycleEvent extends Event
{
    use Dispatchable;

    public static $eventName = AppEventNames::INSTALLING;

    public function __construct(
        public readonly string $package,
        public readonly string $action,
        public readonly AppLifecycleContext $context,
        public readonly bool $after = false,
    ) {
    }
}
