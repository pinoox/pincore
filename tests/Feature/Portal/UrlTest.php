<?php

use Pinoox\Component\Path\Url;
use Pinoox\Portal\Url as UrlPortal;

it('declares the Url portal contract', function () {
    expectPortalContract(UrlPortal::class);
});

it('exposes the standard url scopes', function () {
    expect(Url::SCOPE_APP)->toBe('app')
        ->and(Url::SCOPE_SITE)->toBe('site')
        ->and(Url::SCOPE_RELATIVE)->toBe('relative')
        ->and(Url::SCOPE_APP_PATH)->toBe('app-path');
});

it('declares file download url methods on the Url component', function () {
    expect(method_exists(Url::class, 'file'))->toBeTrue()
        ->and(method_exists(Url::class, 'fileThumb'))->toBeTrue()
        ->and(method_exists(Url::class, 'temporaryFile'))->toBeTrue();
});

