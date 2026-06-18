<?php

/**
 * Front theme stack config.
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
