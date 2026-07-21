<?php

namespace Pinoox\Component\Server\Share;

/**
 * Free Pinggy tunnels show a browser screening page (see pinggy.io/docs/http_tunnels/screening/).
 * A service worker adds X-Pinggy-No-Screen to same-origin requests so assets load as JS/CSS, not HTML.
 */
final class SharePinggyScreeningBypass
{
    public const SERVICE_WORKER_URI = '/.pinoox/share/pinggy-sw.js';

    public static function flagPath(string $projectRoot): string
    {
        return ShareToolkit::binDir($projectRoot) . DIRECTORY_SEPARATOR . 'share-pinggy-bypass';
    }

    public static function activate(string $projectRoot): void
    {
        ShareToolkit::binDir($projectRoot);
        file_put_contents(self::flagPath($projectRoot), (string) time());
    }

    public static function deactivate(string $projectRoot): void
    {
        $path = self::flagPath($projectRoot);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public static function isActive(string $projectRoot): bool
    {
        return is_file(self::flagPath($projectRoot));
    }

    public static function tryServe(string $uri): bool
    {
        if ($uri !== self::SERVICE_WORKER_URI) {
            return false;
        }

        return self::respondJavaScript(self::serviceWorkerScript(), true);
    }

    public static function injectHtml(string $html): string
    {
        if ($html === '' || stripos($html, '<html') === false) {
            return $html;
        }

        if (str_contains($html, 'pinx-pinggy-bypass')) {
            return $html;
        }

        $html = self::deferModuleScripts($html);
        $snippet = '<script>' . self::bootstrapScript() . '</script>';

        if (preg_match('/<head[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $pos = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $pos) . $snippet . substr($html, $pos);
        }

        if (preg_match('/<html[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $pos = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $pos) . '<head>' . $snippet . '</head>' . substr($html, $pos);
        }

        return $snippet . $html;
    }

    private static function deferModuleScripts(string $html): string
    {
        return (string) preg_replace(
            '/<script\b([^>]*?)\stype=(["\'])module\2/i',
            '<script$1 type="text/pinx-pinggy-defer" data-pinx-was-module="1"',
            $html,
        );
    }

    private static function respondJavaScript(string $body, bool $serviceWorker): bool
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store');

        if ($serviceWorker) {
            header('Service-Worker-Allowed: /');
        }

        echo $body;

        return true;
    }

    private static function bootstrapScript(): string
    {
        return <<<'JS'
(function () {
  'use strict';

  function activateModules() {
    document.querySelectorAll('script[type="text/pinx-pinggy-defer"]').forEach(function (node) {
      var script = document.createElement('script');
      script.type = 'module';

      if (node.src) {
        script.src = node.src;
        if (node.crossOrigin) {
          script.crossOrigin = node.crossOrigin;
        }
      } else {
        script.textContent = node.textContent;
      }

      node.replaceWith(script);
    });
  }

  if (!('serviceWorker' in navigator)) {
    activateModules();
    return;
  }

  var storageKey = 'pinx-pinggy-sw-ready';

  if (navigator.serviceWorker.controller) {
    sessionStorage.setItem(storageKey, '1');
    activateModules();
    return;
  }

  navigator.serviceWorker.register('/.pinoox/share/pinggy-sw.js', { scope: '/' })
    .then(function (registration) {
      return registration.ready;
    })
    .then(function () {
      if (navigator.serviceWorker.controller) {
        sessionStorage.setItem(storageKey, '1');
        activateModules();
        return;
      }

      if (sessionStorage.getItem(storageKey) === '1') {
        activateModules();
        return;
      }

      sessionStorage.setItem(storageKey, '1');
      location.reload();
    })
    .catch(function () {
      activateModules();
    });
})();
JS;
    }

    private static function serviceWorkerScript(): string
    {
        return <<<'JS'
self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') {
    return;
  }

  event.respondWith((async function () {
    var headers = new Headers(event.request.headers);
    headers.set('X-Pinggy-No-Screen', '1');

    var init = {
      method: event.request.method,
      headers: headers,
      mode: event.request.mode,
      credentials: event.request.credentials,
      cache: event.request.cache,
      redirect: event.request.redirect,
      referrer: event.request.referrer,
      integrity: event.request.integrity
    };

    return fetch(new Request(event.request.url, init));
  })());
});
JS;
    }
}
