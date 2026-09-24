<?php

use Pinoox\Component\Kernel\Listener\SessionReleaseListener;
use Pinoox\Component\Kernel\SessionStarter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

function createDummyKernel(): HttpKernelInterface
{
    return new class implements HttpKernelInterface {
        public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
        {
            return new Response();
        }
    };
}

function attachStartedSession(Request $request): Session
{
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request->setSession($session);

    return $session;
}

it('subscribes to controller and response kernel events with correct priorities', function () {
    $events = SessionReleaseListener::getSubscribedEvents();

    expect($events)->toHaveKey(KernelEvents::CONTROLLER)
        ->and($events)->toHaveKey(KernelEvents::RESPONSE)
        ->and($events[KernelEvents::CONTROLLER])->toBe(['onController', -16])
        ->and($events[KernelEvents::RESPONSE])->toBe(['onResponse', -128]);
});

it('releases session lock early on safe api routes', function () {
    $kernel = createDummyKernel();
    $listener = new SessionReleaseListener();

    $request = Request::create('/api/v1/panel/products', 'GET');
    $session = attachStartedSession($request);

    expect($session->isStarted())->toBeTrue();

    $event = new ControllerEvent($kernel, fn () => new Response(), $request, HttpKernelInterface::MAIN_REQUEST);
    $listener->onController($event);

    expect($session->isStarted())->toBeFalse();
});

it('releases session lock early on safe requests with Authorization bearer header', function () {
    $kernel = createDummyKernel();
    $listener = new SessionReleaseListener();

    $request = Request::create('/panel/data', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer test_token_123',
    ]);
    $session = attachStartedSession($request);

    expect($session->isStarted())->toBeTrue();

    $event = new ControllerEvent($kernel, fn () => new Response(), $request, HttpKernelInterface::MAIN_REQUEST);
    $listener->onController($event);

    expect($session->isStarted())->toBeFalse();
});

it('does not release session early on mutation requests (POST /api/...) until response', function () {
    $kernel = createDummyKernel();
    $listener = new SessionReleaseListener();

    $request = Request::create('/api/v1/panel/products', 'POST');
    $session = attachStartedSession($request);

    expect($session->isStarted())->toBeTrue();

    // Controller stage must keep session open for mutation writes
    $controllerEvent = new ControllerEvent($kernel, fn () => new Response(), $request, HttpKernelInterface::MAIN_REQUEST);
    $listener->onController($controllerEvent);

    expect($session->isStarted())->toBeTrue();

    // Response stage releases the session
    $responseEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response());
    $listener->onResponse($responseEvent);

    expect($session->isStarted())->toBeFalse();
});

it('does not release session early on non-api web routes without authorization header', function () {
    $kernel = createDummyKernel();
    $listener = new SessionReleaseListener();

    $request = Request::create('/panel/dashboard', 'GET');
    $session = attachStartedSession($request);

    expect($session->isStarted())->toBeTrue();

    $controllerEvent = new ControllerEvent($kernel, fn () => new Response(), $request, HttpKernelInterface::MAIN_REQUEST);
    $listener->onController($controllerEvent);

    // Session remains open for web page controllers
    expect($session->isStarted())->toBeTrue();

    $responseEvent = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response());
    $listener->onResponse($responseEvent);

    expect($session->isStarted())->toBeFalse();
});

it('ignores sub-requests during session early release', function () {
    $kernel = createDummyKernel();
    $listener = new SessionReleaseListener();

    $request = Request::create('/api/v1/panel/orders', 'GET');
    $session = attachStartedSession($request);

    expect($session->isStarted())->toBeTrue();

    $event = new ControllerEvent($kernel, fn () => new Response(), $request, HttpKernelInterface::SUB_REQUEST);
    $listener->onController($event);

    expect($session->isStarted())->toBeTrue();
});
