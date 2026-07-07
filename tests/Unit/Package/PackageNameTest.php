<?php

use Pinoox\Component\Package\PackageName;
use Pinoox\Component\Package\Scaffold\AppCreateScaffolder;

it('accepts app packages with any scope prefix up to 10 chars', function () {
    expect(PackageName::isValid('com_pinoox_manager'))->toBeTrue()
        ->and(PackageName::isValid('co_pinoox_app'))->toBeTrue()
        ->and(PackageName::isValid('ir_mysite_financial'))->toBeTrue()
        ->and(PackageName::isValid('io_yoosefap_ai'))->toBeTrue()
        ->and(PackageName::isValid('opensource_acme_blog'))->toBeTrue();
});

it('accepts optional module segment', function () {
    expect(PackageName::isValid('com_acme_shop_panel'))->toBeTrue()
        ->and(PackageName::isValid('ir_mysite_financial_api'))->toBeTrue();
});

it('treats uppercase and lowercase as the same package', function () {
    expect(PackageName::equals('COM_PINOOX_MANAGER', 'com_pinoox_manager'))->toBeTrue()
        ->and(PackageName::canonical('IO_YOOSEFAP_AI'))->toBe('io_yoosefap_ai')
        ->and(PackageName::isValid('COM_ACME_SHOP'))->toBeTrue();
});

it('rejects short aliases and invalid package names', function () {
    expect(PackageName::isValid('manager'))->toBeFalse()
        ->and(PackageName::isValid('welcome'))->toBeFalse()
        ->and(PackageName::isValid('com_shop'))->toBeFalse()
        ->and(PackageName::isValid(''))->toBeFalse()
        ->and(PackageName::isValid('bad-name'))->toBeFalse()
        ->and(PackageName::isValid('a_b_c'))->toBeFalse();
});

it('detects package-like strings for CLI disambiguation', function () {
    expect(PackageName::looksLike('io_yoosefap_ai'))->toBeTrue()
        ->and(PackageName::looksLike('COM_TEST_CLI_ROLE'))->toBeTrue()
        ->and(PackageName::looksLike('editor'))->toBeFalse()
        ->and(PackageName::looksLike('manager'))->toBeFalse();
});

it('extracts app slug from multi-segment packages', function () {
    expect(PackageName::appSlug('com_pinoox_manager'))->toBe('manager')
        ->and(PackageName::appSlug('IR_MYSITE_FINANCIAL'))->toBe('financial')
        ->and(PackageName::appSlug('io_yoosefap_ai'))->toBe('ai')
        ->and(PackageName::appSlug('com_acme_shop_panel'))->toBe('shop_panel');
});

it('normalizes wizard package names without breaking valid scopes', function () {
    expect(AppCreateScaffolder::normalizePackageName('my_shop'))->toBe('com_my_shop')
        ->and(AppCreateScaffolder::normalizePackageName('com_acme_blog'))->toBe('com_acme_blog')
        ->and(AppCreateScaffolder::normalizePackageName('IO_YOOSEFAP_AI'))->toBe('io_yoosefap_ai')
        ->and(AppCreateScaffolder::normalizePackageName('ir_mysite_financial'))->toBe('ir_mysite_financial');
});
