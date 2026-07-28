<?php

use Pinoox\Portal\RouteResolver;

it('declares the RouteResolver portal contract', function () {
    expectPortalContract(RouteResolver::class);
});
