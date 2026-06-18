<?php

/**
 * Front theme stack config (Twig-only).
 *
 * | Field     | twig | vite/vue/react | webpack (legacy) |
 * |-----------|------|----------------|------------------|
 * | stack     | twig | vite/vue/react | webpack          |
 * | entry     | —    | src/main.js(x) | dist/pinoox.js   |
 * | manifest  | —    | dist/.vite/manifest.json | dist/mix-manifest.json |
 * | dev.url   | —    | VITE_DEV_SERVER | —               |
 *
 * Twig themes use assets() for CSS in theme/assets/. No npm build manifest.
 * Vite stacks load JS/CSS via vite_css_tags() / vite_js_tags() in Twig — not pinoox_script().
 *
 * Controllers must return Response, string, or array (Pinoox 3) — see README.md.
 */
return [
    'profile' => 'twig',
    'stack' => 'twig',
    'ssr' => [
        'enabled' => true,
        'mode' => 'full',
    ],
];
