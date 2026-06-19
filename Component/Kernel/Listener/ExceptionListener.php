<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Kernel\Listener;

use Illuminate\Validation\ValidationException as IlluminateValidationException;
use Pinoox\Component\Http\Api\ApiResponse;
use Pinoox\Component\Http\Response;
use Pinoox\Component\Http\ResponseException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionListener implements EventSubscriberInterface
{
    public function onException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();

        if ($exception instanceof ResponseException) {
            $event->setResponse(
                $exception->getResponse()
            );

            return;
        }

        if ($exception instanceof IlluminateValidationException) {
            $message = $exception->validator->errors()->first() ?: $exception->getMessage();

            $event->setResponse(
                ApiResponse::error('VALIDATION_FAILED', $message, $exception->errors(), 422, translate: false),
            );
            $event->allowCustomResponseCode();

            return;
        }

        if ($event->getRequest()->attributes->has('_controller')) {
            $controller = $event->getRequest()->attributes->get('_controller');
            if (is_array($controller) && isset($controller[0]) && class_exists($controller[0]) && method_exists($controller[0], '_exception')) {
                $result = call_user_func_array(array($controller[0], '_exception'), [
                    $event,
                ]);
                $event->setResponse($result);
                $event->stopPropagation();
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', -1],
        ];
    }
}