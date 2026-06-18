<?php

/**
 * Front theme stack config (Vite hybrid).
 *
 * stack=vite — Twig shell + Vite entry (use vite_css_tags / vite_js_tags in partials).
 * manifest — Vite build output at dist/.vite/manifest.json (not webpack mix-manifest).
 * entry — Vite input key, must match vite_*_tags('src/main.js') in Twig.
 * dev.url — Vite dev server (VITE_DEV=true); optional theme/dist/hot overrides at runtime.
 *
 * Controllers must return Response, string, or array (Pinoox 3) — see README.md.
 */
return [
    'profile' => 'hybrid',
    'stack' => 'vite',
    'entry' => 'src/main.js',
    'manifest' => 'dist/.vite/manifest.json',
    'mount' => '#app',
    'dev' => [
        'enabled' => (bool) _env('VITE_DEV', false),
        'url' => rtrim((string) _env('VITE_DEV_SERVER', 'http://127.0.0.1:5173'), '/'),
    ],
    'ssr' => [
        'enabled' => false,
        'mode' => 'hybrid',
    ],
];
