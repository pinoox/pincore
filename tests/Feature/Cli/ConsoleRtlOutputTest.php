<?php

use Pinoox\Component\Console\Output\RtlText;

it('can convert Persian text to visual order for Windows console output', function () {
    expect(RtlText::toConsoleVisual('مدیریت'))->toBe('تیریدم')
        ->and(RtlText::toConsoleVisual('Name: مدیریت'))->toBe('Name: تیریدم')
        ->and(RtlText::toConsoleVisual("\033[32mمدیریت\033[39m"))->toBe("\033[32mتیریدم\033[39m");
});

it('allows the RTL console mode to be forced or disabled', function () {
    $previousServer = $_SERVER['PINOOX_CLI_RTL'] ?? null;
    $previousEnv = getenv('PINOOX_CLI_RTL');

    try {
        $_SERVER['PINOOX_CLI_RTL'] = 'visual';
        putenv('PINOOX_CLI_RTL=visual');
        expect(RtlText::shouldUseVisualOrder(null))->toBeTrue();

        $_SERVER['PINOOX_CLI_RTL'] = 'off';
        putenv('PINOOX_CLI_RTL=off');
        expect(RtlText::shouldUseVisualOrder(null))->toBeFalse();
    } finally {
        if ($previousServer === null) {
            unset($_SERVER['PINOOX_CLI_RTL']);
        } else {
            $_SERVER['PINOOX_CLI_RTL'] = $previousServer;
        }

        if ($previousEnv === false) {
            putenv('PINOOX_CLI_RTL');
        } else {
            putenv('PINOOX_CLI_RTL=' . $previousEnv);
        }
    }
});
