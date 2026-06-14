<?php

use Pinoox\Component\AppEvent\AppControllerEvent;
use Pinoox\Component\AppEvent\AppCoreEventDispatcher;
use Pinoox\Component\AppEvent\AppEventNames;
use Pinoox\Component\AppEvent\AppRouteMatchedEvent;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Collection;
use Pinoox\Component\Router\Route;
use Pinoox\Portal\Event;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    pinooxBoot();
});

it('defines core request event names and helpers', function () {
    expect(AppEventNames::ROUTE_MATCHED)->toBe('app.route.matched')
        ->and(AppEventNames::CONTROLLER)->toBe('app.controller')
        ->and(AppEventNames::RESPONSE)->toBe('app.response')
        ->and(AppEventNames::EXCEPTION)->toBe('app.exception')
        ->and(AppEventNames::TERMINATE)->toBe('app.terminate')
        ->and(AppEventNames::route('app.run'))->toBe('app.route.app.run')
        ->and(AppEventNames::apiRoute('auth.login'))->toBe('app.api.auth.login')
        ->and(AppEventNames::controller(FakeCoreEventController::class, 'index'))
            ->toBe('app.controller.' . str_replace('\\', '.', FakeCoreEventController::class) . '.index')
        ->and(AppEventNames::coreRequestEvents())->toContain(AppEventNames::ROUTE_MATCHED);
});

it('dispatches route matched to global package and route channels', function () {
    $package = 'com_core_event_' . bin2hex(random_bytes(3));
    $collection = new Collection();
    $route = new Route($collection, '/ping', fn () => 'pong', name: 'ping');
    $request = Request::create('http://localhost/ping', 'GET');
    $event = new AppRouteMatchedEvent($package, $request, $route);

    $channels = [];
    $listener = static function () use (&$channels, $event): void {
        $channels[] = $event->routeName();
    };

    Event::listen(AppEventNames::ROUTE_MATCHED, $listener);
    Event::listen(AppEventNames::package(AppEventNames::ROUTE_MATCHED, $package), $listener);
    Event::listen(AppEventNames::route('ping'), $listener);
    Event::listen(AppEventNames::package(AppEventNames::route('ping'), $package), $listener);

    AppCoreEventDispatcher::dispatch(
        $event,
        AppEventNames::ROUTE_MATCHED,
        $package,
        AppEventNames::route('ping'),
    );

    expect($channels)->toBe(['ping', 'ping', 'ping', 'ping']);
});

it('dispatches controller event to controller channel', function () {
    $package = 'com_core_ctrl_' . bin2hex(random_bytes(3));
    $request = Request::create('http://localhost/panel', 'GET');
    $controllerClass = FakeCoreEventController::class;
    $event = new AppControllerEvent(
        $package,
        $request,
        [FakeCoreEventController::class, 'index'],
        $controllerClass,
        'index',
    );

    $hit = false;
    Event::listen(AppEventNames::controller($controllerClass, 'index'), static function () use (&$hit): void {
        $hit = true;
    });

    AppCoreEventDispatcher::dispatch(
        $event,
        AppEventNames::CONTROLLER,
        $package,
        AppEventNames::controller($controllerClass, 'index'),
    );

    expect($hit)->toBeTrue();
});

class FakeCoreEventController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}
