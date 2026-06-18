<?php

use Pinoox\Portal\Pinion;

it('declares the Pinion portal contract', function () {
    expectPortalContract(Pinion::class);
});

it('exposes pinion package types', function () {
    expect(class_exists(\Pinoox\Pinion\Manager::class))->toBeTrue()
        ->and(class_exists(\Pinoox\Pinion\Builder::class))->toBeTrue()
        ->and(class_exists(\Pinoox\Pinion\HttpHandler::class))->toBeTrue()
        ->and(class_exists(\Pinoox\Pinion\Result::class))->toBeTrue()
        ->and(\Pinoox\Pinion\Config::PROTOCOL)->toBe('pinion');
});

it('creates an http handler from the portal', function () {
    $handler = Pinion::http(['destination' => 'uploads/tmp']);

    expect($handler)->toBeInstanceOf(\Pinoox\Component\Pinion\HttpHandler::class);
});
