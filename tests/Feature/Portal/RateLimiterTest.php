<?php

use Pinoox\Portal\RateLimiter;

it('declares the RateLimiter portal contract', function () {
    expectPortalContract(RateLimiter::class);
});
