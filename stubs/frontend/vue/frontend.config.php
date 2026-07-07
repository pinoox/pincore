<?php

/** stack + profile — entry, entries, manifest, dev.* are filled automatically. Use vite_tags() in Twig. */
return [
    'profile' => 'spa',
    'stack' => 'vue',
    // 'entries' => ['src/main.js', 'src/admin.js'],
    // 'refresh' => false,
    //
    // Optional dev overrides (auto-detected values from AppRouter are used when omitted):
    // 'dev' => [
    //     'port' => 5174,
    //     'server_url' => 'http://127.0.0.1:8000/manager',
    //     'proxy' => ['/manager', '/api'],
    //     'proxy_extra' => ['/uploads'],
    // ],
];
