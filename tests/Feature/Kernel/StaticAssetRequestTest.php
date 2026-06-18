<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Listener\RouteEmptyListener;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Support\AppPublicPath;
use Pinoox\Support\StaticAssetRequest;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    putenv('PINOOX_APPS_PATH=apps');
    $_ENV['PINOOX_APPS_PATH'] = 'apps';
    $_SERVER['PINOOX_APPS_PATH'] = 'apps';
});

function staticAssetAppsRequestPath(string $suffix = 'com_demo_shop/theme/default/dist/main.css'): string
{
    $appsPrefix = AppPublicPath::appsDirectoryPrefix(testProjectRoot());

    return '/' . $appsPrefix . '/' . ltrim($suffix, '/');
}

it('detects missing static assets under the apps web prefix', function () {
    $request = Request::create(staticAssetAppsRequestPath(), 'GET');

    expect(StaticAssetRequest::shouldReturnPlainNotFound($request))->toBeTrue();
});

it('ignores non-static paths under apps', function () {
    $request = Request::create(staticAssetAppsRequestPath('com_demo_shop/dashboard'), 'GET');

    expect(StaticAssetRequest::shouldReturnPlainNotFound($request))->toBeFalse();
});

it('ignores static extensions outside apps', function () {
    $request = Request::create('/theme/default/dist/main.css', 'GET');

    expect(StaticAssetRequest::shouldReturnPlainNotFound($request))->toBeFalse();
});

it('returns a plain 404 response for static asset requests', function () {
    $response = StaticAssetRequest::notFoundResponse();

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('')
        ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8');
});

it('detects standard apps prefix paths in a typical project layout', function () {
    $projectRoot = '/var/www/pinoox';
    $request = Request::create('/apps/com_demo_shop/theme/default/dist/main.css', 'GET');

    expect(StaticAssetRequest::shouldReturnPlainNotFound($request, $projectRoot))->toBeTrue();
});

it('skips no-route rendering for missing apps static assets', function () {
    $request = Request::create(staticAssetAppsRequestPath(), 'GET');
    $kernel = test()->createMock(HttpKernelInterface::class);
    $exception = new NotFoundHttpException('', new ResourceNotFoundException());
    $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

    (new RouteEmptyListener())->onKernelException($event);

    $response = $event->getResponse();

    expect($response)->not->toBeNull()
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('');
});
