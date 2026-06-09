<?php

use Pinoox\Component\Package\Pinx\PinxVersion;

it('prints platform and kernel versions via CLI', function () {
    $root = testProjectRoot();

    $process = proc_open(
        [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'pinoox', 'version'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
    );

    expect($process)->not->toBeFalse();

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect(trim($stderr))->toBe('')
        ->and($stdout)->toContain('Pinoox platform')
        ->and($stdout)->toContain('Kernel (pincore)')
        ->and($stdout)->toContain(PinxVersion::platform()['name'])
        ->and($stdout)->toContain(PinxVersion::kernel()['name']);
});

it('prints only kernel version with --kernel', function () {
    $root = testProjectRoot();

    $process = proc_open(
        [PHP_BINARY, $root . DIRECTORY_SEPARATOR . 'pinoox', 'version', '--kernel'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
    );

    expect($process)->not->toBeFalse();

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect(trim($stderr))->toBe('')
        ->and($stdout)->toContain('Kernel (pincore)')
        ->and($stdout)->not->toContain('Pinoox platform');
});
