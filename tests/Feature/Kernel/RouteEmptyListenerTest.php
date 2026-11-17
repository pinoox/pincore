<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Listener\RouteEmptyListener;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Router\RouteCollection;
use Pinoox\Portal\App\App as AppPortal;
use Pinoox\Support\SystemConfig;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\NoConfigurationException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    SystemConfig::clearCache();
});

it('does not render welcome response when routes exist and a 404 route is requested', function () {
    $kernel = test()->createMock(HttpKernelInterface::class);
    $request = Request::create('/non-existent-page', 'GET');
    $exception = new NotFoundHttpException('No route found', new ResourceNotFoundException('No routes found for "/non-existent-page".'));
    $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    // App has registered routes (installer has 100+ routes in test environment)
    expect(AppPortal::routeCollection()->count())->toBeGreaterThan(0);

    (new RouteEmptyListener())->onKernelException($event);
    $response = $event->getResponse();

    // Must NOT set a welcome response because routes exist
    expect($response)->toBeNull();
});

it('renders welcome response when app route collection is empty and NoConfigurationException is thrown', function () {
    $kernel = test()->createMock(HttpKernelInterface::class);
    $request = Request::create('/', 'GET');
    $exception = new NotFoundHttpException('', new NoConfigurationException());
    $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    // Mock an empty route collection using Pinoox's RouteCollection
    $mockApp = test()->createMock(\Pinoox\Component\Package\App::class);
    $emptyRoutes = new RouteCollection();
    $mockApp->method('routeCollection')->willReturn($emptyRoutes);
    $mockApp->method('package')->willReturn('com_pinoox_installer');
    $mockApp->method('path')->willReturn(testProjectRoot() . '/apps/com_pinoox_installer');
    $mockApp->method('get')->willReturnCallback(function ($key, $default = null) {
        return match ($key) {
            'router.routes' => ['routes/web.php'],
            'name' => 'Installer',
            default => $default,
        };
    });

    $originalApp = AppPortal::___();
    AppPortal::__bind($mockApp);

    try {
        (new RouteEmptyListener())->onKernelException($event);
        $response = $event->getResponse();

        expect($response)->not->toBeNull()
            ->and($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toContain('Pinoox');
    } finally {
        AppPortal::__bind($originalApp);
    }
});
