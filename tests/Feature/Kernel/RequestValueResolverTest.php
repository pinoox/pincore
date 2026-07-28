<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Kernel\Resolver\AppValueResolver;
use Pinoox\Component\Kernel\Resolver\DefaultValueResolver;
use Pinoox\Component\Kernel\Resolver\RequestAttributeValueResolver;
use Pinoox\Component\Kernel\Resolver\RequestValueResolver;
use Pinoox\Component\Kernel\Resolver\RouteValueResolver;
use Pinoox\Component\Package\App;
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

test('AppValueResolver skips non-App controller arguments so defaults can resolve', function () {
    $httpRequest = Request::create('/app/com_pinoox_pay');
    $argument = new ArgumentMetadata('subPath', 'string', false, true, '');

    $appResolver = new AppValueResolver();
    $defaultResolver = new DefaultValueResolver();

    expect(iterator_to_array($appResolver->resolve($httpRequest, $argument)))->toBe([])
        ->and(iterator_to_array($defaultResolver->resolve($httpRequest, $argument)))->toBe(['']);
});

test('AppValueResolver only yields for App-typed controller arguments', function () {
    $httpRequest = Request::create('/api/example');
    $argument = new ArgumentMetadata('app', App::class, false, false, null);
    $resolver = new AppValueResolver();

    expect($resolver->supports($httpRequest, $argument))->toBeTrue();
});

test('RouteValueResolver skips non-Route controller arguments', function () {
    $httpRequest = Request::create('/api/example');
    $argument = new ArgumentMetadata('subPath', 'string', false, true, '');

    expect(iterator_to_array((new RouteValueResolver())->resolve($httpRequest, $argument)))->toBe([]);
});
