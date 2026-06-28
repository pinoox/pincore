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
  var root = document.createElement('div');
  root.style.cssText = 'position:fixed;left:18px;bottom:18px;z-index:2147483647;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif';
  root.innerHTML = `
    <div data-pinx-panel style="display:none;width:310px;margin-bottom:12px;border:1px solid rgba(255,255,255,.14);border-radius:22px;background:rgba(7,11,20,.92);color:#e5e7eb;box-shadow:0 24px 80px rgba(0,0,0,.42);backdrop-filter:blur(18px);overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.10)">
        <div><div style="font-weight:800">Pinx Studio</div><div style="font-size:12px;color:#94a3b8">Local development tools</div></div>
        <span style="height:9px;width:9px;border-radius:999px;background:#2dd4bf;box-shadow:0 0 18px #2dd4bf"></span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px">
        <a href="{$route}" target="_blank" rel="noreferrer" style="color:#0f172a;background:#5eead4;text-decoration:none;border-radius:14px;padding:10px;font-size:13px;font-weight:800">Open Studio</a>
        <a href="{$route}#database" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:rgba(255,255,255,.08);text-decoration:none;border-radius:14px;padding:10px;font-size:13px;font-weight:700">Database</a>
        <a href="{$route}#cli" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:rgba(255,255,255,.08);text-decoration:none;border-radius:14px;padding:10px;font-size:13px;font-weight:700">CLI</a>
        <a href="{$route}/api/summary" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:rgba(255,255,255,.08);text-decoration:none;border-radius:14px;padding:10px;font-size:13px;font-weight:700">Status</a>
      </div>
    </div>
    <button data-pinx-toggle type="button" title="Pinx Studio" style="height:54px;min-width:54px;border:0;border-radius:999px;background:linear-gradient(135deg,#5eead4,#60a5fa);color:#06111f;display:flex;align-items:center;gap:10px;padding:0 16px;box-shadow:0 18px 48px rgba(15,23,42,.34);font-weight:900;cursor:pointer">
      <span style="display:grid;place-items:center;width:28px;height:28px;border-radius:999px;background:rgba(255,255,255,.58)">P</span>
      <span style="font-size:13px">Studio</span>
    </button>
  `;
  root.querySelector('[data-pinx-toggle]').addEventListener('click', function () {
    var panel = root.querySelector('[data-pinx-panel]');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  });
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(root); });
})();
</script>
HTML;

        $response->setContent(preg_replace('/<\/body>/i', $widget . '</body>', $content, 1) ?? $content);
        $response->headers->remove('Content-Length');
    }
}
