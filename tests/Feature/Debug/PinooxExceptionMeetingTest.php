<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Listener\PinooxExceptionRenderListener;
use Pinoox\Component\Package\AppLayer;
use Pinoox\Component\Transport\TransportContext;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Config;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

function meetingExceptionTestKernel(): \Pinoox\Component\Kernel\Kernel
{
    static $kernel;

    if ($kernel === null) {
        pinooxBoot();
        $kernel = AppProvider::___()->getKernel();
    }

    return $kernel;
}

afterEach(function () {
    while (TransportContext::inMeeting()) {
        TransportContext::leave();
    }

    try {
        App::___()->setLayer(new AppLayer('/', 'com_pinoox_manager'));
    } catch (\Throwable) {
    }
});

it('renders pinoox exception for kernel failures inside meeting mode', function () {
    Config::name('~pinoox')->set('exception', true);

    App::___()->setLayer(new AppLayer('/', 'com_pinoox_manager'));
    TransportContext::enter('com_pinoox_manager');

    try {
        $request = Request::create('/app/com_demo', 'GET');
        $event = new ExceptionEvent(
            meetingExceptionTestKernel(),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            new RuntimeException('meeting boom'),
        );

        (new PinooxExceptionRenderListener())->onKernelException($event);

        $response = $event->getResponse();

        expect($response)->not->toBeNull()
            ->and($response->getStatusCode())->toBe(500);

        $body = (string) $response->getContent();

        expect($body)
            ->toContain('Pinoox Exception')
            ->and($body)->toContain('meeting boom')
            ->and($body)->toContain('Meeting mode')
            ->and($body)->toContain('com_pinoox_manager');
    } finally {
        TransportContext::leave();
    }
});

it('tracks meeting depth via transport context', function () {
    expect(TransportContext::inMeeting())->toBeFalse();

    TransportContext::enter('com_pinoox_manager');

    expect(TransportContext::inMeeting())->toBeTrue()
        ->and(TransportContext::host())->toBe('com_pinoox_manager');

    TransportContext::leave();

    expect(TransportContext::inMeeting())->toBeFalse();
});

it('skips rendering when not in meeting mode', function () {
    $event = new ExceptionEvent(
        meetingExceptionTestKernel(),
        Request::create('/', 'GET'),
        HttpKernelInterface::MAIN_REQUEST,
        new RuntimeException('main boom'),
    );

    (new PinooxExceptionRenderListener())->onKernelException($event);

    expect(TransportContext::inMeeting())->toBeFalse()
        ->and($event->getResponse())->toBeNull();
});

it('renders a friendly page when PINOOX_EXCEPTION is disabled in meeting mode', function () {
    Config::name('~pinoox')->set('exception', false);

    App::___()->setLayer(new AppLayer('/', 'com_pinoox_manager'));
    TransportContext::enter('com_pinoox_manager');

    try {
        $request = Request::create('/app/com_demo', 'GET');
        $event = new ExceptionEvent(
            meetingExceptionTestKernel(),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            new RuntimeException('secret meeting boom'),
        );

        (new PinooxExceptionRenderListener())->onKernelException($event);

        $response = $event->getResponse();

        expect($response)->not->toBeNull()
            ->and($response->getStatusCode())->toBe(500);

        $body = (string) $response->getContent();

        expect($body)
            ->toContain('Something went wrong')
            ->and($body)->not->toContain('secret meeting boom')
            ->and($body)->not->toContain('Pinoox Exception')
            ->and($body)->not->toContain('Stack trace');
    } finally {
        TransportContext::leave();
        Config::name('~pinoox')->set('exception', true);
    }
});

it('renders generic JSON when PINOOX_EXCEPTION is disabled for API meeting requests', function () {
    Config::name('~pinoox')->set('exception', false);

    App::___()->setLayer(new AppLayer('/', 'com_pinoox_manager'));
    TransportContext::enter('com_pinoox_manager');

    try {
        $request = Request::create('/api/register', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"mobile":"09000000000"}');
        $event = new ExceptionEvent(
            meetingExceptionTestKernel(),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            new RuntimeException('OTP_SMS_DRIVER is not configured.'),
        );

        (new PinooxExceptionRenderListener())->onKernelException($event);

        $response = $event->getResponse();

        expect($response)->not->toBeNull()
            ->and($response->getStatusCode())->toBe(500)
            ->and($response->headers->get('Content-Type'))->toContain('application/json');

        $payload = json_decode((string) $response->getContent(), true);

        expect($payload['success'])->toBeFalse()
            ->and($payload['error']['code'])->toBe('SERVER_ERROR')
            ->and($payload['error']['message'])->toContain('Something went wrong')
            ->and(json_encode($payload))->not->toContain('OTP_SMS_DRIVER');
    } finally {
        TransportContext::leave();
        Config::name('~pinoox')->set('exception', true);
    }
});
