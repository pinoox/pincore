<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\ResponseException;
use Pinoox\Component\Kernel\Controller\ApiController;
use Pinoox\Portal\Validation;

final class HttpApiControllerStub extends ApiController
{
    public function flashSuccess(): mixed
    {
        return $this->message('manager.saved_successfully');
    }

    public function flashWithData(): mixed
    {
        return $this->message('user.avatar_changed_successfully', ['id' => 1]);
    }

    public function dataOnly(): mixed
    {
        return $this->ok(['items' => []]);
    }

    public function softDeny(): mixed
    {
        return $this->deny('manager.invalid_request');
    }

    public function httpError(): mixed
    {
        return $this->error('manager.error_happened');
    }

    public function validateInput(Request $request): mixed
    {
        return $this->validated($request, ['email' => 'required|email']);
    }
}

it('returns flash success envelope from message()', function () {
    $response = (new HttpApiControllerStub())->flashSuccess();
    $payload = json_decode($response->getContent(), true);

    expect($payload)
        ->toMatchArray([
            'success' => true,
            'data' => null,
            'meta' => [],
        ])
        ->and($payload['message'])->not->toBe('OK');
});

it('returns flash success with data from message()', function () {
    $response = (new HttpApiControllerStub())->flashWithData();
    $payload = json_decode($response->getContent(), true);

    expect($payload['success'])->toBeTrue()
        ->and($payload['data'])->toBe(['id' => 1]);
});

it('returns data-only envelope from ok()', function () {
    $response = (new HttpApiControllerStub())->dataOnly();
    $payload = json_decode($response->getContent(), true);

    expect($payload)
        ->toMatchArray([
            'success' => true,
            'data' => ['items' => []],
            'message' => 'OK',
            'meta' => [],
        ]);
});

it('returns soft failure envelope from deny()', function () {
    $response = (new HttpApiControllerStub())->softDeny();
    $payload = json_decode($response->getContent(), true);

    expect($payload)
        ->toMatchArray([
            'success' => true,
            'data' => false,
            'meta' => [],
        ])
        ->and($payload['message'])->not->toBe('OK');
});

it('returns http error envelope from error()', function () {
    $response = (new HttpApiControllerStub())->httpError();
    $payload = json_decode($response->getContent(), true);

    expect($payload['success'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('API_ERROR')
        ->and($response->getStatusCode())->toBe(422);
});

it('aborts with validation error from validated()', function () {
    $request = appRequest('POST', '/profile', json: ['email' => '']);
    $request->setValidation(Validation::___());

    try {
        (new HttpApiControllerStub())->validateInput($request);
        expect(false)->toBeTrue('Expected ResponseException');
    } catch (ResponseException $e) {
        $payload = json_decode($e->getResponse()->getContent(), true);

        expect($payload['success'])->toBeFalse()
            ->and($payload['error']['code'])->toBe('API_ERROR')
            ->and($e->getResponse()->getStatusCode())->toBe(422);
    }
});
