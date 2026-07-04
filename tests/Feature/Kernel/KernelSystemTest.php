<?php

use Pinoox\Component\Kernel\Boot\BootPipeline;
use Pinoox\Component\Kernel\Container\AppServiceContainer;
use Pinoox\Component\Kernel\Container\IlluminateBridge;
use Pinoox\Component\Kernel\Container\ServiceContainerBootstrap;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Kernel\Boot;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppTestKit::boot();
});

afterEach(function () {
    deleteFakeApp('com_test_kernel_ctrl');
    deleteTestApp('com_test_kernel_container');
    AppEngine::__rebuild();
});

it('exposes a deterministic boot pipeline', function () {
    $stages = AppProvider::___()->bootStages();

    expect($stages)->toBe([
        'composer',
        'loader',
        'boot.global',
        'app.boot',
        'container',
        'events',
        'database',
        'api',
        'session',
    ])->and(Boot::bootStages())->toBe($stages);
});

it('keeps app container opt-in disabled by default', function () {
    writeTestApp('com_test_kernel_container', []);

    expect(ServiceContainerBootstrap::containerEnabled('com_test_kernel_container'))->toBeFalse();
});

it('registers bindings when container is enabled', function () {
    $interface = 'Pinoox\Tests\\Support\\KernelSampleContract';
    $service = 'Pinoox\Tests\\Support\\KernelSampleService';

    IlluminateBridge::bind($interface, $service);

    $instance = IlluminateBridge::make($interface);

    expect($instance)->toBeInstanceOf($service)
        ->and($instance->label())->toBe('kernel-sample');
});

it('discovers controller classes for an app package', function () {
    fakeApp('com_test_kernel_ctrl', [
        'Controller/DemoController.php' => <<<'PHP'
<?php

namespace App\com_test_kernel_ctrl\Controller;

class DemoController
{
}
PHP,
    ]);
    AppEngine::__rebuild();

    $controllerFile = AppEngine::path('com_test_kernel_ctrl', 'Controller/DemoController.php');
    expect($controllerFile)->toBeFile();
    require_once $controllerFile;

    expect(AppServiceContainer::discoverControllers('com_test_kernel_ctrl'))
        ->toBe(['App\\com_test_kernel_ctrl\\Controller\\DemoController']);
});

it('builds boot pipeline for a context', function () {
    $provider = AppProvider::___();

    expect($provider->bootStages())->toContain('app.boot', 'container');
});

it('registers service_container alias in pincore container', function () {
    $builder = ServiceContainerBootstrap::boot('~');

    expect($builder->has('kernel.service_container'))->toBeTrue()
        ->and($builder->hasAlias('service_container'))->toBeTrue();
});

it('hydrates container bindings from boot cache payload shape', function () {
    $payload = [
        'bindings' => [
            Pinoox\Tests\Support\KernelSampleContract::class => Pinoox\Tests\Support\KernelSampleService::class,
        ],
        'controllers' => [],
        'singletons' => [],
    ];

    AppServiceContainer::hydrate('com_test_cache', $payload);

    expect(AppServiceContainer::export('com_test_cache'))->toBe($payload);
});

