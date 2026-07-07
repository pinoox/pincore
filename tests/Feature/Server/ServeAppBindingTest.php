<?php

use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Component\Server\ServeAppBinding;

it('resolves package names to their router mount path', function () {
    $routes = [
        '/' => 'com_pinoox_welcome',
        '/manager' => 'com_pinoox_manager',
    ];

    expect(ServeAppBinding::resolveBinding('com_pinoox_manager', $routes))
        ->toBe(['package' => 'com_pinoox_manager', 'path' => '/manager'])
        ->and(ServeAppBinding::resolveBinding('io_yoosefap_ai', []))
        ->toBe(['package' => 'io_yoosefap_ai', 'path' => '/']);
});

it('resolves route paths from the router map', function () {
    $routes = [
        '/' => 'com_pinoox_welcome',
        '/manager' => 'com_pinoox_manager',
        '/c' => 'com_pinoox_comingsoon',
    ];

    expect(ServeAppBinding::resolveBinding('/manager', $routes))
        ->toBe(['package' => 'com_pinoox_manager', 'path' => '/manager'])
        ->and(ServeAppBinding::resolveBinding('manager', $routes))
        ->toBe(['package' => 'com_pinoox_manager', 'path' => '/manager']);
});

it('supports package@path override syntax', function () {
    expect(ServeAppBinding::resolveBinding('com_pinoox_manager@/manager', []))
        ->toBe(['package' => 'com_pinoox_manager', 'path' => '/manager']);
});

it('guesses com_pinoox_* package from short aliases', function () {
    expect(ServeAppBinding::resolveBinding('manager', []))
        ->toBe(['package' => 'com_pinoox_manager', 'path' => '/']);
});

it('prefers explicit mount paths when a package has multiple router entries', function () {
    $routes = [
        '/' => 'com_pinoox_demo',
        '/demo' => 'com_pinoox_demo',
    ];

    expect(ServeAppBinding::preferPackageMountPath('com_pinoox_demo', $routes))
        ->toBe('/demo');
});

it('builds app layer when serve env binding is active', function () {
    putenv(ServeAppBinding::ENV . '=com_pinoox_manager');

    try {
        $layer = ServeAppBinding::resolveLayer(
            AppRouteMatcher::normalizeRoutes(['/manager' => 'com_pinoox_manager']),
            static fn (string $package): bool => $package === 'com_pinoox_manager',
        );

        expect($layer)->not->toBeNull()
            ->and($layer->getPackageName())->toBe('com_pinoox_manager')
            ->and($layer->getPath())->toBe('/manager')
            ->and($layer->matchedBy())->toBe('serve_app');
    } finally {
        putenv(ServeAppBinding::ENV);
    }
});
