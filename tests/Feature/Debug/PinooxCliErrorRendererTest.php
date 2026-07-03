<?php

use Pinoox\Component\Kernel\Debug\PinooxCliErrorRenderer;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

it('renders pinoox exception output for cli', function () {
    $renderer = new PinooxCliErrorRenderer(PINOOX_BASE_PATH, false);
    $output = $renderer->render(new RuntimeException('cli boom'));

    expect($output)
        ->toContain('Pinoox Exception')
        ->and($output)->toContain(RuntimeException::class)
        ->and($output)->toContain('cli boom')
        ->and($output)->toContain('Location:')
        ->and($output)->toContain('Hints')
        ->and($output)->toContain('Trace');
});

it('renders console usage mistakes without the full exception report', function () {
    $previousArgv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['pinoox', 'serve', 'app:router'];

    try {
        $renderer = new PinooxCliErrorRenderer(PINOOX_BASE_PATH, false);
        $output = $renderer->render(new ConsoleRuntimeException('No arguments expected for "serve" command, got "app:router".'));
    } finally {
        if ($previousArgv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $previousArgv;
        }
    }

    expect($output)
        ->toContain('Command usage error')
        ->toContain('No arguments expected for "serve" command')
        ->toContain('php pinoox app:router')
        ->toContain('php pinoox serve')
        ->not->toContain('Pinoox Exception')
        ->not->toContain('Trace');
});
