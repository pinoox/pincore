<?php

/**
 * Front theme stack config (React SPA / hybrid).
 *
 * stack=react — Vite + React; entry is usually src/main.jsx.
 * manifest — dist/.vite/manifest.json (not legacy dist/mix-manifest.json).
 * entry — must match vite_js_tags('src/main.jsx') in Twig partials.
 * dev.url — Vite dev server URL from VITE_DEV_SERVER.
 *
 * Controllers must return Response, string, or array (Pinoox 3) — see README.md.
 */
return [
    'profile' => 'spa',
    'stack' => 'react',
    'entry' => 'src/main.jsx',
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
