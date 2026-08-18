<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Event;
use Symfony\Contracts\EventDispatcher\Event as SymfonyEvent;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
});

afterEach(function () {
    Event::dontFake();
});

it('declares the Event portal contract', function () {
    expectPortalContract(Event::class);
});

it('keeps callback portal methods chainable and dispatches events', function () {
    $handled = false;
    $listener = function () use (&$handled) {
        $handled = true;
    };

    $result = Event::listen('portal.event.test', $listener);
    Event::dispatch(new SymfonyEvent(), 'portal.event.test');
    Event::removeListener('portal.event.test', $listener);

    expect($result)->toBeInstanceOf(Event::class)
        ->and($handled)->toBeTrue();
});

it('dispatches with event() helpers and type-hinted event_listen closures', function () {
    $seen = [];
    event_listen(function (EventHelperProbe $event) use (&$seen) {
        $seen[] = $event->value;
    });
    event_listen(function (EventHelperProbe|EventHelperOther $event) use (&$seen) {
        $seen[] = $event::class;
    });

    event(EventHelperProbe::class, 4);
    event(new EventHelperOther(2));

    expect($seen)->toBe([4, EventHelperProbe::class, EventHelperOther::class])
        ->and(event_has(EventHelperProbe::class))->toBeTrue()
        ->and(event_name(EventHelperProbe::class))->toBe(EventHelperProbe::class)
        ->and(event_name(new EventHelperProbe(1)))->toBe(EventHelperProbe::class);

    EventHelperProbe::dispatchIf(false, 9);
    expect($seen)->toHaveCount(3);
    EventHelperProbe::dispatchUnless(false, 8);
    expect($seen)->toContain(8);
});

it('fakes events and asserts dispatches without running listeners', function () {
    $ran = false;
    event_listen(function (EventHelperProbe $event) use (&$ran) {
        $ran = true;
    });

    event_fake(EventHelperProbe::class);
    event(new EventHelperProbe(1));

    Event::assertDispatched(EventHelperProbe::class);
    Event::assertDispatchedOnce(EventHelperProbe::class);
    Event::assertNotDispatched(EventHelperOther::class);
    expect($ran)->toBeFalse();
});

class EventHelperProbe extends \Pinoox\Component\Event\Event
{
    public function __construct(public readonly int $value = 0)
    {
    }
}

class EventHelperOther extends \Pinoox\Component\Event\Event
{
    public function __construct(public readonly int $value = 0)
    {
    }
}

