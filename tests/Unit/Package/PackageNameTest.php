<?php

use Pinoox\Component\Package\PackageName;

it('accepts app packages with any tld prefix', function () {
    expect(PackageName::isValid('com_pinoox_manager'))->toBeTrue()
        ->and(PackageName::isValid('co_pinoox_app'))->toBeTrue()
        ->and(PackageName::isValid('ir_mysite_financial'))->toBeTrue()
        ->and(PackageName::isValid('io_yoosefap_ai'))->toBeTrue();
});

it('rejects short aliases and invalid package names', function () {
    expect(PackageName::isValid('manager'))->toBeFalse()
        ->and(PackageName::isValid('welcome'))->toBeFalse()
        ->and(PackageName::isValid(''))->toBeFalse()
        ->and(PackageName::isValid('bad-name'))->toBeFalse();
});

it('detects package-like strings for CLI disambiguation', function () {
    expect(PackageName::looksLike('io_yoosefap_ai'))->toBeTrue()
        ->and(PackageName::looksLike('com_test_cli_role'))->toBeTrue()
        ->and(PackageName::looksLike('editor'))->toBeFalse()
        ->and(PackageName::looksLike('manager'))->toBeFalse();
});

it('extracts app slug from multi-segment packages', function () {
    expect(PackageName::appSlug('com_pinoox_manager'))->toBe('manager')
        ->and(PackageName::appSlug('ir_mysite_financial'))->toBe('financial')
        ->and(PackageName::appSlug('io_yoosefap_ai'))->toBe('ai')
        ->and(PackageName::appSlug('com_manager'))->toBe('manager');
});
