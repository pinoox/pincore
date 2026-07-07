<?php

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\FrontendDevStack;

test('FrontendDevStack allocates unique vite ports', function () {
    $targets = [
        ['package' => 'com_a', 'theme' => 'a', 'config' => ['dev' => ['port' => 5173]]],
        ['package' => 'com_b', 'theme' => 'b', 'config' => ['dev' => ['port' => 5173]]],
        ['package' => 'com_c', 'theme' => 'c', 'config' => ['dev' => ['port' => 5175]]],
    ];

    expect(FrontendDevStack::allocateVitePorts($targets))->toBe([5173, 5174, 5175]);
});

test('FrontendDevStack uses configured ports when unique', function () {
    $targets = [
        ['package' => 'com_a', 'theme' => 'a', 'config' => ['dev' => ['port' => 5173]]],
        ['package' => 'com_b', 'theme' => 'b', 'config' => ['dev' => ['port' => 5174]]],
    ];

    expect(FrontendDevStack::allocateVitePorts($targets))->toBe([5173, 5174]);
});

test('FrontendDevStack defaults missing port config to 5173', function () {
    $targets = [
        ['package' => 'com_a', 'theme' => 'a', 'config' => FrontendConfig::forThemePath(sys_get_temp_dir())],
    ];

    expect(FrontendDevStack::allocateVitePorts($targets))->toBe([5173]);
});
