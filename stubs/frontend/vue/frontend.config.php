<?php

/**
 * Front theme stack config (Vue SPA / hybrid).
 *
 * stack=vue — same Vite manifest as vite stack; Vue app mounts on #app.
 * manifest — dist/.vite/manifest.json (FrontController never reads webpack mix-manifest).
 * entry — passed to vite_js_tags('src/main.js') in partials/scripts.twig.
 * dev.url — Vite HMR server when VITE_DEV=true.
 * pinoox — optional inline twig template for pinoox_script(); prefer pinoox_bootstrap() + vite tags.
 *
 * Controllers must return Response, string, or array (Pinoox 3) — see README.md.
 */
return [
    'profile' => 'spa',
    'stack' => 'vue',
    'entry' => 'src/main.js',
    'manifest' => 'dist/.vite/manifest.json',
    'pinoox' => 'pinoox',
    'mount' => '#app',
    'dev' => [
        'enabled' => (bool) _env('VITE_DEV', false),
        'url' => rtrim((string) _env('VITE_DEV_SERVER', 'http://127.0.0.1:5173'), '/'),
    ],
    'ssr' => [
        'enabled' => false,
        'mode' => 'shell',
    ],
];
