<?php

use Pinoox\Component\Kernel\Debug\PinooxCliErrorRenderer;

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
