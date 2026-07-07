<?php

use Pinoox\Terminal\Theme\ThemeFrontendCommand;
use Symfony\Component\Console\Input\ArrayInput;

test('fe dev:apps parseArguments accepts dev-stack as deprecated alias', function () {
    $command = new ThemeFrontendCommand();
    $method = new ReflectionMethod($command, 'parseArguments');
    $method->setAccessible(true);

    $input = new ArrayInput([
        'target' => 'dev-stack',
        'action' => '',
    ]);
    $input->bind($command->getDefinition());

    expect($method->invoke($command, $input))->toBe(['', 'dev:apps']);
});

test('fe dev:apps rejects short aliases and requires full package names', function () {
    $command = new ThemeFrontendCommand();
    $method = new ReflectionMethod($command, 'assertDevAppsPackage');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($command, 'manager'))
        ->toThrow(RuntimeException::class, 'Invalid package');

    expect(fn () => $method->invoke($command, 'welcome'))
        ->toThrow(RuntimeException::class, 'Invalid package');
});

test('parseDevAppsSelection accepts comma-separated numbers and package names', function () {
    $command = new ThemeFrontendCommand();
    $method = new ReflectionMethod($command, 'parseDevAppsSelection');
    $method->setAccessible(true);

    $packages = [
        'com_pinoox_welcome',
        'com_pinoox_manager',
        'com_pinoox_market',
    ];

    expect($method->invoke($command, '0,1', $packages))->toBe([
        'com_pinoox_welcome',
        'com_pinoox_manager',
    ])->and($method->invoke($command, 'com_pinoox_manager, com_pinoox_welcome', $packages))->toBe([
        'com_pinoox_manager',
        'com_pinoox_welcome',
    ])->and($method->invoke($command, 'all', $packages))->toBe($packages);
});

test('parseDevAppsSelection rejects empty input', function () {
    $command = new ThemeFrontendCommand();
    $method = new ReflectionMethod($command, 'parseDevAppsSelection');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($command, '', ['com_pinoox_welcome']))
        ->toThrow(RuntimeException::class, 'Select at least one package');
});
