<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Router\Route;
use Pinoox\Flow\AccessFlow;
use Pinoox\Flow\AuthFlow;
use Pinoox\Portal\Auth;

class TestCustomAccessFlow extends AccessFlow
{
    public bool $allowAuthorize = true;

    protected function authorize(Request $request, ?Route $route): bool
    {
        return $this->allowAuthorize;
    }
}

class TestLegacyAuthFlow extends AuthFlow
{
    public bool $exitCalled = false;

    protected function exit(Request $request, Route $route)
    {
        $this->exitCalled = true;
        return response('legacy_exit_401', 401);
    }
}

it('returns 401 unauthenticated when guest accesses protected flow', function () {
    Auth::boot();
    Auth::logout();

    $flow = new TestCustomAccessFlow();
    $request = Request::create('http://localhost/protected');
    $next = fn (Request $req) => response('ok_passed', 200);

    $response = $flow->response($request, $next);

    expect($response->getStatusCode())->toBe(401);
});

it('returns 403 forbidden when user is logged in but authorize() returns false', function () {
    Auth::boot();
    $user = new \Pinoox\Model\UserModel();
    $user->user_id = 999;
    Auth::login($user);

    $flow = new TestCustomAccessFlow();
    $flow->allowAuthorize = false;

    $request = Request::create('http://localhost/admin');
    $next = fn (Request $req) => response('ok_passed', 200);

    $response = $flow->response($request, $next);

    expect($response->getStatusCode())->toBe(403);
});

it('passes through to next handler when user is logged in and authorize() returns true', function () {
    Auth::boot();
    $user = new \Pinoox\Model\UserModel();
    $user->user_id = 999;
    Auth::login($user);

    $flow = new TestCustomAccessFlow();
    $flow->allowAuthorize = true;

    $request = Request::create('http://localhost/admin');
    $next = fn (Request $req) => response('ok_passed', 200);

    $response = $flow->response($request, $next);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok_passed');
});

it('maintains backward compatibility with legacy AuthFlow implementing exit()', function () {
    Auth::boot();
    Auth::logout();

    $flow = new TestLegacyAuthFlow();
    $request = Request::create('http://localhost/legacy');
    $route = new Route(collection: new \Pinoox\Component\Router\Collection(), path: '/legacy');
    $request->attributes->set('_router', $route);
    $next = fn (Request $req) => response('ok_passed', 200);

    $response = $flow->response($request, $next);

    expect($flow->exitCalled)->toBeTrue()
        ->and($response->getStatusCode())->toBe(401)
        ->and($response->getContent())->toBe('legacy_exit_401');
});

it('returns json response on 401 and 403 when json is requested', function () {
    Auth::boot();
    Auth::logout();

    $flow = new TestCustomAccessFlow();
    $request = Request::create('http://localhost/api/resource', 'GET', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $next = fn (Request $req) => response()->json(['status' => 'ok']);

    $response = $flow->response($request, $next);
    expect($response->getStatusCode())->toBe(401);
    $data = json_decode($response->getContent(), true);
    expect($data)->toHaveKey('message');

    // Forbidden 403 in json
    $user = new \Pinoox\Model\UserModel();
    $user->user_id = 999;
    Auth::login($user);
    $flow->allowAuthorize = false;
    $response403 = $flow->response($request, $next);
    expect($response403->getStatusCode())->toBe(403);
    $data403 = json_decode($response403->getContent(), true);
    expect($data403['code'])->toBe(403);
});
