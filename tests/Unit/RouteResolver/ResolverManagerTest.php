<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\RouteResolver\Binding;
use Pinoox\Component\RouteResolver\ResolverManager;
use Pinoox\Flow\ResolveFlow;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->manager = new ResolverManager();
});

it('binds callables and resolves parameters', function () {
    $this->manager->bind('tenant', function (string $value) {
        return (object) ['domain' => $value];
    });

    $request = Request::create('/t/acme');
    $request->attributes->set('tenant', 'acme');

    expect($this->manager->resolve($request))->toBeNull()
        ->and($request->attributes->get('tenant')->domain)->toBe('acme');
});

it('supports Binding::missing for custom responses', function () {
    $this->manager->bind('user', fn () => null)
        ->missing(fn () => new Response('gone', 302));

    $request = Request::create('/users/1');
    $request->attributes->set('user', '1');

    $response = $this->manager->resolve($request);

    expect($response)->toBeInstanceOf(Response::class)
        ->and($response->getStatusCode())->toBe(302)
        ->and($response->getContent())->toBe('gone');
});

it('throws 404 when resolution fails without missing handler', function () {
    $this->manager->define('post', fn () => null);

    $request = Request::create('/posts/x');
    $request->attributes->set('post', 'x');

    $this->manager->resolve($request);
})->throws(NotFoundHttpException::class);

it('leaves unbound attributes untouched', function () {
    $request = Request::create('/x');
    $request->attributes->set('id', '99');

    expect($this->manager->resolve($request))->toBeNull()
        ->and($request->attributes->get('id'))->toBe('99');
});

it('skips already resolved objects', function () {
    $user = (object) ['id' => 5];
    $this->manager->bind('user', fn () => (object) ['id' => 1]);

    $request = Request::create('/users/5');
    $request->attributes->set('user', $user);

    $this->manager->resolve($request);

    expect($request->attributes->get('user'))->toBe($user);
});

it('ResolveFlow short-circuits with missing response', function () {
    $this->manager->bind('item', fn () => null)
        ->missing(fn () => new Response('missing', 404));

    $flow = new ResolveFlow(null, $this->manager);
    $request = Request::create('/items/1');
    $request->attributes->set('item', '1');

    $called = false;
    $response = $flow->response($request, function () use (&$called) {
        $called = true;

        return new Response('ok');
    });

    expect($called)->toBeFalse()
        ->and($response->getContent())->toBe('missing');
});

it('returns Binding from define()', function () {
    $binding = $this->manager->define('workspace', fn ($value) => $value);

    expect($binding)->toBeInstanceOf(Binding::class)
        ->and($this->manager->has('workspace'))->toBeTrue();
});
