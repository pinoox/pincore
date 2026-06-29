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
  root.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:2147483647;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif';
  root.innerHTML = `
    <div data-pinx-panel style="display:none;position:relative;width:min(292px,calc(100vw - 24px));margin-bottom:10px;border:1px solid rgba(216,180,254,.30);border-radius:20px;background:linear-gradient(145deg,#111827,#2b1558 48%,#050914);color:#eef2ff;box-shadow:0 20px 70px rgba(15,23,42,.42),inset 0 1px 0 rgba(255,255,255,.16);overflow:hidden">
      <div style="position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 18% 0%,rgba(255,255,255,.13),transparent 32%),radial-gradient(circle at 88% 12%,rgba(168,85,247,.18),transparent 28%)"></div>
      <div style="position:relative;display:flex;align-items:center;justify-content:space-between;padding:12px 13px;border-bottom:1px solid rgba(255,255,255,.10)">
        <div style="display:flex;align-items:center;gap:9px;min-width:0">
          <div style="display:grid;place-items:center;width:34px;height:34px;border-radius:14px;background:linear-gradient(145deg,#3b2a64,#28144f);border:1px solid rgba(255,255,255,.18);box-shadow:inset 0 1px 0 rgba(255,255,255,.18),0 0 24px rgba(167,139,250,.24)">
            <svg viewBox="0 0 48 48" width="23" height="23" aria-hidden="true"><path fill="none" stroke="#d8b4fe" stroke-width="2.4" d="M24 5 40 14v20l-16 9-16-9V14L24 5Z"/><path fill="none" stroke="#c4b5fd" stroke-width="2.4" d="m8 14 16 9 16-9M24 23v20M16 18.5l16-9"/><path fill="#a78bfa" fill-opacity=".28" d="m24 5 16 9-16 9-16-9 16-9Z"/></svg>
          </div>
          <div style="min-width:0"><div style="font-size:13px;font-weight:900;letter-spacing:-.01em;white-space:nowrap">Pinx Inspector</div><div data-pinx-status style="max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#cbd5e1">Checking local app...</div></div>
        </div>
        <span data-pinx-dot style="height:8px;width:8px;border-radius:999px;background:#34d399;box-shadow:0 0 16px #34d399"></span>
      </div>
      <div style="position:relative;display:grid;grid-template-columns:1fr 1fr;gap:7px;padding:10px">
        <a href="{$route}" target="_blank" rel="noreferrer" style="grid-column:1/-1;color:#fff;background:linear-gradient(135deg,#7c3aed,#a855f7);text-decoration:none;border-radius:13px;padding:9px 11px;font-size:12px;font-weight:900;box-shadow:0 12px 26px rgba(124,58,237,.24),inset 0 1px 0 rgba(255,255,255,.16)">Open Inspector</a>
        <a href="{$route}#database" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:#1f2937;border:1px solid rgba(255,255,255,.08);text-decoration:none;border-radius:12px;padding:8px 9px;font-size:12px;font-weight:800">Database</a>
        <a href="{$route}#health" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:#1f2937;border:1px solid rgba(255,255,255,.08);text-decoration:none;border-radius:12px;padding:8px 9px;font-size:12px;font-weight:800">Health</a>
        <a href="{$route}#migrations" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:#1f2937;border:1px solid rgba(255,255,255,.08);text-decoration:none;border-radius:12px;padding:8px 9px;font-size:12px;font-weight:800">Migrate</a>
        <a href="{$route}#logs" target="_blank" rel="noreferrer" style="color:#e5e7eb;background:#1f2937;border:1px solid rgba(255,255,255,.08);text-decoration:none;border-radius:12px;padding:8px 9px;font-size:12px;font-weight:800">Logs</a>
      </div>
      <div style="position:relative;border-top:1px solid rgba(255,255,255,.08);display:grid;grid-template-columns:1fr 1fr 1fr;gap:1px;background:rgba(255,255,255,.06)">
        <div style="background:#111827;padding:8px 9px"><div style="font-size:10px;color:#cbd5e1">Tables</div><strong data-pinx-tables style="font-size:12px">--</strong></div>
        <div style="background:#111827;padding:8px 9px"><div style="font-size:10px;color:#cbd5e1">Rows</div><strong data-pinx-rows style="font-size:12px">--</strong></div>
        <div style="background:#111827;padding:8px 9px;min-width:0"><div style="font-size:10px;color:#cbd5e1">Engine</div><strong data-pinx-engine style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">--</strong></div>
      </div>
    </div>
    <button data-pinx-toggle type="button" title="Pinx Inspector" style="height:50px;width:50px;border:1px solid rgba(221,214,254,.38);border-radius:18px;background:linear-gradient(145deg,#49317a,#6d28d9 46%,#111827);color:#fff;display:grid;place-items:center;padding:0;box-shadow:0 18px 46px rgba(88,28,135,.38),inset 0 1px 0 rgba(255,255,255,.20);cursor:pointer">
      <svg viewBox="0 0 48 48" width="27" height="27" aria-hidden="true"><path fill="rgba(167,139,250,.18)" stroke="#ddd6fe" stroke-width="2.3" d="M24 5 40 14v20l-16 9-16-9V14L24 5Z"/><path fill="none" stroke="#c4b5fd" stroke-width="2.3" d="m8 14 16 9 16-9M24 23v20M16 18.5l16-9"/></svg>
    </button>
  `;
  root.querySelector('[data-pinx-toggle]').addEventListener('click', function () {
    var panel = root.querySelector('[data-pinx-panel]');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  });
  fetch('{$route}/api/summary', { cache: 'no-store' }).then(function (res) { return res.json(); }).then(function (summary) {
    var status = root.querySelector('[data-pinx-status]');
    var tables = root.querySelector('[data-pinx-tables]');
    var rows = root.querySelector('[data-pinx-rows]');
    var engine = root.querySelector('[data-pinx-engine]');
    if (status) status.textContent = (summary.app && summary.app.name ? summary.app.name : 'Local app') + ' is connected';
    if (tables) tables.textContent = summary.database && summary.database.table_count != null ? summary.database.table_count : '--';
    if (rows) rows.textContent = summary.stats && summary.stats.rows != null ? summary.stats.rows : '--';
    if (engine) engine.textContent = summary.database && summary.database.engine ? summary.database.engine : '--';
  }).catch(function () {
    var status = root.querySelector('[data-pinx-status]');
    var dot = root.querySelector('[data-pinx-dot]');
    if (status) status.textContent = 'Inspector is starting...';
    if (dot) dot.style.background = '#f59e0b';
  });
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(root); });
})();
</script>
HTML;

        $response->setContent(preg_replace('/<\/body>/i', $widget . '</body>', $content, 1) ?? $content);
        $response->headers->remove('Content-Length');
    }
}
