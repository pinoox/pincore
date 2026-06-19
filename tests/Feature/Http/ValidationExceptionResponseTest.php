<?php

use Illuminate\Validation\ValidationException as IlluminateValidationException;
use Pinoox\Component\Kernel\Listener\ExceptionListener;
use Pinoox\Portal\Validation;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

it('renders validation failures as API 422 envelope', function () {
    $request = appRequest('POST', '/auth/login', json: ['username' => '', 'password' => '']);
    $request->setValidation(Validation::___());

    try {
        Validation::___()->make(
            ['username' => '', 'password' => ''],
            ['username' => 'required', 'password' => 'required'],
        )->validate();
    } catch (IlluminateValidationException $exception) {
        $event = new ExceptionEvent(
            test()->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        (new ExceptionListener())->onException($event);

        $response = $event->getResponse();
        $payload = json_decode($response->getContent(), true);

        expect($response->getStatusCode())->toBe(422)
            ->and($payload['success'])->toBeFalse()
            ->and($payload['error']['code'])->toBe('VALIDATION_FAILED')
            ->and($payload['error']['message'])->not->toBeEmpty()
            ->and($payload['error']['details'])->toBeArray();
    }
});
