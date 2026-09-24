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

use Pinoox\Component\Kernel\SessionStarter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SessionReleaseListener implements EventSubscriberInterface
{
    /**
     * Release session file lock early on safe (read-only) API requests.
     * Prevents parallel requests from being serialized by PHP's session lock.
     */
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->isMethodSafe()) {
            $path = $request->getPathInfo();
            if (str_starts_with($path, '/api/') || $request->headers->has('Authorization')) {
                SessionStarter::release($request);
            }
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        SessionStarter::release($event->getRequest());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', -16],
            KernelEvents::RESPONSE => ['onResponse', -128],
        ];
    }
}

