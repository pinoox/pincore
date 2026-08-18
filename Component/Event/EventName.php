<?php

namespace Pinoox\Component\Event;

/**
 * Resolve an event class or dispatcher name to the string Event::listen() expects.
 */
final class EventName
{
    public static function resolve(string $event): string
    {
        if (!class_exists($event)) {
            return $event;
        }

        if (is_callable([$event, 'eventName'])) {
            $name = $event::eventName();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        if (isset($event::$eventName) && is_string($event::$eventName) && $event::$eventName !== '') {
            return $event::$eventName;
        }

        return $event;
    }
}
