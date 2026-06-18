<?php

use Pinoox\Pinion\Session;
use Pinoox\Terminal\Pinion\Concerns\ManagesCliPinion;
use Pinoox\Terminal\Pinion\PinionCleanCommand;
use Pinoox\Terminal\Pinion\PinionInfoCommand;
use Pinoox\Terminal\Pinion\PinionListCommand;

it('registers pinion CLI commands', function () {
    $application = cliApplication([
        new PinionListCommand(),
        new PinionInfoCommand(),
        new PinionCleanCommand(),
    ]);

    expect($application->has('pinion:list'))->toBeTrue()
        ->and($application->has('pinion:info'))->toBeTrue()
        ->and($application->has('pinion:clean'))->toBeTrue();
});

it('formats pinion progress for CLI output', function () {
    $probe = cliTraitProbe([ManagesCliPinion::class]);
    $session = Session::fromArray([
        'id' => '11111111-1111-4111-8111-111111111111',
        'filename' => 'demo.pinx',
        'size' => 100,
        'chunk_size' => 50,
        'total_chunks' => 2,
        'destination' => 'uploads/manual',
        'extensions' => ['pinx'],
        'status' => 'pending',
        'bytes_received' => 50,
        'received_indexes' => [0],
        'created_at' => time(),
        'expires_at' => time() + 3600,
    ]);

    expect(cliTraitInvoke($probe, 'formatPinionProgress', $session))
        ->toBe('50 B / 100 B (50.0%)');
});
