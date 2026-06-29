<?php

namespace Pinoox\Component\Kernel\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class InspectorWidgetListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -240],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || (string) getenv('PINX_INSPECTOR_WIDGET') !== '1') {
            return;
        }

        if (!class_exists(\Pinoox\PinxInspector\WidgetRenderer::class)) {
            return;
        }

        $response = $event->getResponse();
        $contentType = (string) $response->headers->get('Content-Type', '');
        $content = (string) $response->getContent();

        if ($content === '' || stripos($content, '<html') === false || stripos($content, '</body>') === false) {
            return;
        }

        if ($contentType !== '' && stripos($contentType, 'html') === false) {
            return;
        }

        $route = (string) (getenv('PINX_INSPECTOR_ROUTE') ?: '/~inspector');
        $widget = \Pinoox\PinxInspector\WidgetRenderer::render($route);

        $response->setContent(preg_replace('/<\/body>/i', $widget . '</body>', $content, 1) ?? $content);
        $response->headers->remove('Content-Length');
    }
}
