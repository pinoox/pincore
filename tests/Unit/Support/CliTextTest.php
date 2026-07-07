<?php

use Pinoox\Support\CliText;

it('leaves LTR labels unchanged', function () {
    expect(CliText::isolateRtl('Core platform'))->toBe('Core platform')
        ->and(CliText::isolateRtl(''))->toBe('');
});

it('wraps Arabic script labels with directional marks for terminal tables', function () {
    $wrapped = CliText::isolateRtl('مدیریت');

    expect($wrapped)->toStartWith("\u{200F}")
        ->and($wrapped)->toEndWith("\u{200E}")
        ->and($wrapped)->toContain('مدیریت');
});

it('builds package rows with wrapped Persian display names', function () {
    $probe = cliTraitProbe([\Pinoox\Terminal\Concerns\SelectsPackage::class]);
    $rows = cliTraitInvoke($probe, 'packageRows', [
        'com_pinoox_manager' => 'مدیریت',
    ]);

    expect($rows[0][2])->toBe(CliText::isolateRtl('مدیریت'));
});
