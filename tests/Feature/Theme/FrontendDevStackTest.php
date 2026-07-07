<?php

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendDevStack;

test('FrontendDevStack allocates unique vite ports', function () {
    $targets = [
        ['package' => 'com_a', 'theme' => 'a', 'config' => ['dev' => ['port' => 59101]]],
        ['package' => 'com_b', 'theme' => 'b', 'config' => ['dev' => ['port' => 59101]]],
        ['package' => 'com_c', 'theme' => 'c', 'config' => ['dev' => ['port' => 59103]]],
    ];

    expect(FrontendDevStack::allocateVitePorts($targets))->toBe([59101, 59102, 59103]);
});

test('FrontendDevStack uses configured ports when unique', function () {
    $targets = [
        ['package' => 'com_a', 'theme' => 'a', 'config' => ['dev' => ['port' => 59111]]],
        ['package' => 'com_b', 'theme' => 'b', 'config' => ['dev' => ['port' => 59112]]],
    ];

    expect(FrontendDevStack::allocateVitePorts($targets))->toBe([59111, 59112]);
});

test('FrontendDevStack bumps preferred port when it is already in use', function () {
    $socket = @stream_socket_server('tcp://127.0.0.1:59121', $errno, $errstr);

    if ($socket === false) {
        test()->markTestSkipped('Could not bind test port 59121.');
    }

    try {
        $targets = [
            ['package' => 'com_welcome', 'theme' => 'welcome', 'config' => ['dev' => ['port' => 59121]]],
        ];

        expect(FrontendDevStack::allocateVitePorts($targets))->toBe([59122]);
    } finally {
        fclose($socket);
    }
});

test('FrontendDevStack defaults missing port config to 5173', function () {
    $targets = [
        ['package' => 'com_a', 'theme' => 'a', 'config' => FrontendConfig::forThemePath(sys_get_temp_dir())],
    ];

    $ports = FrontendDevStack::allocateVitePorts($targets);

    expect($ports)->toHaveCount(1)
        ->and($ports[0])->toBeGreaterThanOrEqual(5173);
});
