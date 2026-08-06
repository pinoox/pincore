<?php

namespace Pinoox\Component\Kernel\Listener;

use Pinoox\Component\Http\Api\ApiResponse;
use Pinoox\Component\Http\Request;
use Pinoox\Component\Http\Response;
use Pinoox\Component\Kernel\Debug\PinooxHtmlErrorRenderer;
use Pinoox\Component\Kernel\Debug\Support\ExceptionContext;
use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Component\Transport\TransportContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders exceptions for guest-app failures inside {@see \Pinoox\Component\Package\App::meeting()}.
 *
 * Kernel sub-requests catch exceptions before PHP's global handler; this listener bridges that gap.
 * When {@see RuntimeMode::bootDebugEnabled()} is false, returns a friendly HTML/JSON page without
 * stack traces (instead of leaking PHP's default fatal error output).
 */
class PinooxExceptionRenderListener implements EventSubscriberInterface
{
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!TransportContext::inMeeting()) {
            return;
        }

        if ($event->hasResponse()) {
            return;
        }

        $debug = RuntimeMode::bootDebugEnabled();
        $request = $event->getRequest();

        if (!$debug && $this->wantsJson($request)) {
            $event->setResponse(
                ApiResponse::error(
                    'SERVER_ERROR',
                    'Something went wrong while processing your request.',
                    status: 500,
                    translate: false,
                ),
            );
            $event->allowCustomResponseCode();

            return;
        }

        $projectDir = ExceptionContext::collect()['project_root'];
        $renderer = new PinooxHtmlErrorRenderer($debug, null, null, $projectDir);
        $flattened = $renderer->render($event->getThrowable());

        $event->setResponse(new Response(
            $flattened->getAsString(),
            $flattened->getStatusCode(),
            $flattened->getHeaders(),
        ));
        $event->allowCustomResponseCode();
    }

    private function wantsJson(Request|\Symfony\Component\HttpFoundation\Request $request): bool
    {
        if ($request instanceof Request && ($request->isContentJson || $request->isXHR())) {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json')
            || str_contains((string) $request->headers->get('Content-Type', ''), 'application/json');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -128],
        ];
    }
}
