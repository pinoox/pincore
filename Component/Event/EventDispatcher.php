<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Event;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher as EventDispatcherSymfony;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EventDispatcher extends EventDispatcherSymfony
{
    private bool $faking = false;

    /** @var list<string>|null */
    private ?array $fakeEvents = null;

    /** @var list<array{0: string, 1: object}> */
    private array $dispatched = [];

    public function listen(string|callable $eventName, callable|array|string|null $listener = null, int $priority = 0): void
    {
        if (is_callable($eventName) && $listener === null) {
            $types = EventListener::eventTypesFromCallable($eventName);
            if ($types === []) {
                throw new InvalidArgumentException('Closure listener must type-hint an event class.');
            }

            foreach ($types as $type) {
                $this->listen($type, $eventName, $priority);
            }

            return;
        }

        if (!is_string($eventName) || $listener === null) {
            throw new InvalidArgumentException('Event listen() expects an event name and a listener.');
        }

        parent::addListener(EventName::resolve($eventName), EventListener::make($listener), $priority);
    }

    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        parent::addSubscriber($subscriber);
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $eventName = $eventName ?? $event::class;

        if ($this->shouldFake($event, $eventName)) {
            $this->dispatched[] = [$eventName, $event];

            return $event;
        }

        return parent::dispatch($event, $eventName);
    }

    /**
     * @param list<class-string|string>|class-string|string|null $events
     */
    public function fake(array|string|null $events = null): void
    {
        $this->faking = true;
        $this->dispatched = [];

        if ($events === null) {
            $this->fakeEvents = null;

            return;
        }

        $names = is_array($events) ? $events : [$events];
        $this->fakeEvents = array_values(array_map(
            static fn (string $event) => EventName::resolve($event),
            $names,
        ));
    }

    public function dontFake(): void
    {
        $this->faking = false;
        $this->fakeEvents = null;
        $this->dispatched = [];
    }

    /**
     * @param callable(object): bool|null $callback
     * @return list<object>
     */
    public function dispatched(string $event, ?callable $callback = null): array
    {
        return $this->matching($event, $callback);
    }

    public function assertDispatched(string $event, int|callable|null $callback = null): void
    {
        $times = is_int($callback) ? $callback : null;
        $filter = is_callable($callback) ? $callback : null;
        $matches = $this->matching($event, $filter);
        $count = count($matches);

        if ($times !== null) {
            if ($count !== $times) {
                throw new RuntimeException(sprintf(
                    'Event [%s] was dispatched %d time(s) instead of %d.',
                    $event,
                    $count,
                    $times,
                ));
            }

            return;
        }

        if ($count < 1) {
            throw new RuntimeException(sprintf('Event [%s] was not dispatched.', $event));
        }
    }

    public function assertDispatchedOnce(string $event): void
    {
        $this->assertDispatched($event, 1);
    }

    public function assertNotDispatched(string $event, ?callable $callback = null): void
    {
        $matches = $this->matching($event, $callback);
        if ($matches !== []) {
            throw new RuntimeException(sprintf('Event [%s] was dispatched unexpectedly.', $event));
        }
    }

    public function assertNothingDispatched(): void
    {
        $count = count($this->dispatched);
        if ($count > 0) {
            throw new RuntimeException(sprintf('%d event(s) were dispatched unexpectedly.', $count));
        }
    }

    /**
     * @param callable(object): bool|null $callback
     * @return list<object>
     */
    private function matching(string $event, ?callable $callback): array
    {
        $name = EventName::resolve($event);
        $matches = [];

        foreach ($this->dispatched as [$dispatchedName, $instance]) {
            if ($dispatchedName !== $name && $instance::class !== $event && EventName::of($instance) !== $name) {
                continue;
            }

            if ($callback !== null && !$callback($instance)) {
                continue;
            }

            $matches[] = $instance;
        }

        return $matches;
    }

    private function shouldFake(object $event, string $eventName): bool
    {
        if (!$this->faking) {
            return false;
        }

        if ($this->fakeEvents === null) {
            return true;
        }

        $resolved = EventName::resolve($eventName);
        $className = EventName::of($event) ?? $event::class;

        return in_array($resolved, $this->fakeEvents, true)
            || in_array($className, $this->fakeEvents, true)
            || in_array($event::class, $this->fakeEvents, true);
    }
}
