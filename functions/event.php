<?php

use Pinoox\Component\Event\EventName;
use Pinoox\Component\Event\NamedEvent;
use Pinoox\Portal\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

if (!function_exists('event')) {
    /**
     * Dispatch an event class, instance, or string name with an optional payload.
     *
     *     event(new OrderPlaced($id));
     *     event(OrderPlaced::class, $id);
     *     event('order.register', ['id' => 12, 'user_id' => 4]);
     */
    function event(object|string $event, mixed ...$payload): object
    {
        if (is_object($event)) {
            return Event::dispatch($event, EventName::of($event));
        }

        if (class_exists($event)) {
            $instance = new $event(...$payload);

            return Event::dispatch($instance, EventName::resolve($event));
        }

        $named = NamedEvent::from($event, ...$payload);

        return Event::dispatch($named, $event);
    }
}

if (!function_exists('event_listen')) {
    /**
     * Register a listener: event class / name, or a type-hinted closure.
     *
     *     event_listen(OrderPlaced::class, SendOrderEmail::class);
     *     event_listen(function (OrderPlaced $event) {});
     *     event_listen('order.register', function (NamedEvent $event) {});
     */
    function event_listen(string|callable $event, callable|array|string|null $listener = null, int $priority = 0): void
    {
        Event::listen($event, $listener, $priority);
    }
}

if (!function_exists('event_subscribe')) {
    /**
     * @param class-string<EventSubscriberInterface>|EventSubscriberInterface $subscriber
     */
    function event_subscribe(string|object $subscriber): void
    {
        if (is_string($subscriber)) {
            if (!class_exists($subscriber)) {
                throw new InvalidArgumentException(sprintf('Event subscriber class [%s] does not exist.', $subscriber));
            }

            $subscriber = new $subscriber();
        }

        Event::addSubscriber($subscriber);
    }
}

if (!function_exists('event_has')) {
    /**
     * Whether the dispatcher has listeners for an event class or name.
     */
    function event_has(string $event): bool
    {
        return Event::hasListeners(EventName::resolve($event));
    }
}

if (!function_exists('event_name')) {
    /**
     * Resolve an event instance or class to its dispatcher name.
     */
    function event_name(object|string $event): string
    {
        if (is_object($event)) {
            return EventName::of($event) ?? $event::class;
        }

        return EventName::resolve($event);
    }
}

if (!function_exists('event_fake')) {
    /**
     * Record events instead of running listeners (tests).
     *
     *     event_fake();
     *     event_fake(OrderPlaced::class);
     *     event_fake([OrderPlaced::class, PaymentConfirmed::class]);
     */
    function event_fake(array|string|null $events = null): void
    {
        Event::fake($events);
    }
}
