<?php

namespace Pinoox\Component\Kernel\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class StudioWidgetListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -240],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || (string) getenv('PINX_STUDIO_WIDGET') !== '1') {
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

        $route = rtrim((string) (getenv('PINX_STUDIO_ROUTE') ?: '/~studio'), '/');
        $route = htmlspecialchars($route !== '' ? $route : '/~studio', ENT_QUOTES, 'UTF-8');
        $widget = <<<HTML
<script>
(function () {
  if (window.__PINX_STUDIO_WIDGET__) return;
  window.__PINX_STUDIO_WIDGET__ = true;
  var a = document.createElement('a');
  a.href = '{$route}';
  a.target = '_blank';
  a.rel = 'noreferrer';
  a.title = 'Open Pinx Studio';
  a.textContent = 'P';
  a.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:2147483647;width:42px;height:42px;border-radius:999px;background:#0b7a75;color:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;font:700 18px system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 10px 28px rgba(15,23,42,.24);border:1px solid rgba(255,255,255,.35)';
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(a); });
})();
</script>
HTML;

        $response->setContent(preg_replace('/<\/body>/i', $widget . '</body>', $content, 1) ?? $content);
        $response->headers->remove('Content-Length');
    }
}
