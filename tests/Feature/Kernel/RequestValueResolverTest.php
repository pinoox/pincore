<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Resolver\RequestAttributeValueResolver;
use Pinoox\Component\Kernel\Resolver\RequestValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

test('RequestValueResolver injects the HTTP request when a colliding route attribute exists', function () {
    $httpRequest = Request::create('/api/example');
    $httpRequest->attributes->set('request', null);

    $argument = new ArgumentMetadata('request', Request::class, false, false, null);
    $attributeResolver = new RequestAttributeValueResolver();
    $requestResolver = new RequestValueResolver();

    expect($attributeResolver->supports($httpRequest, $argument))->toBeFalse()
        ->and($requestResolver->supports($httpRequest, $argument))->toBeTrue();

    $resolved = iterator_to_array($requestResolver->resolve($httpRequest, $argument));

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0])->toBe($httpRequest);
});

test('RequestAttributeValueResolver resolves typed route parameters from attributes', function () {
    $httpRequest = Request::create('/api/items/42');
    $httpRequest->attributes->set('id', '42');

    $argument = new ArgumentMetadata('id', 'string', false, false, null);
    $resolver = new RequestAttributeValueResolver();

    expect($resolver->supports($httpRequest, $argument))->toBeTrue();

    $resolved = iterator_to_array($resolver->resolve($httpRequest, $argument));

    expect($resolved)->toBe(['42']);
});

test('RequestAttributeValueResolver ignores Request-typed controller arguments', function () {
    $httpRequest = Request::create('/api/example');
    $httpRequest->attributes->set('httpRequest', null);

    $argument = new ArgumentMetadata('httpRequest', Request::class, false, false, null);
    $resolver = new RequestAttributeValueResolver();

    expect($resolver->supports($httpRequest, $argument))->toBeFalse()
        ->and(iterator_to_array($resolver->resolve($httpRequest, $argument)))->toBe([]);
});
