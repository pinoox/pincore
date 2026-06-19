<?php

return [
    'meta' => [
        'dir' => 'ltr',
        'lang' => 'en',
        'name' => 'English',
    ],
    'page' => [
        'title' => 'Pinoox Help',
        'welcome_prefix' => 'Welcome to',
        'welcome_suffix' => '',
        'descriptions' => [
            'not_configured' => 'No app is assigned to this address. Map this URL in your app router config to an installed app package.',
            'app_missing' => 'An app is configured for this address, but Pinoox could not find that package in App Engine.',
            'app_disabled' => 'An app is configured for this address, but it is disabled in its app manifest.',
        ],
        'labels' => [
            'request_path' => 'Requested path',
            'host' => 'Host',
            'configured_path' => 'Router path',
            'configured_package' => 'Configured package',
        ],
        'quick_start_title' => 'How to fix',
        'steps' => [
            'not_configured' => [
                'step1' => 'Open <code dir="ltr">:router_file</code> in your project config folder.',
                'step2' => 'Add a route for this address, for example <code dir="ltr">\'/\' =&gt; \'com_vendor_myapp\'</code>, then refresh.',
            ],
            'app_missing' => [
                'step1' => 'Check that package <code dir="ltr">:package</code> exists under your apps directory and is registered in App Engine.',
                'step2' => 'Fix the route in <code dir="ltr">:router_file</code> or install the missing app, then refresh.',
            ],
            'app_disabled' => [
                'step1' => 'Open <code dir="ltr">apps/:package/app.php</code> and set <code dir="ltr">\'enable\' =&gt; true</code>.',
                'step2' => 'If the app should stay disabled, remove or change its route in <code dir="ltr">:router_file</code>.',
            ],
        ],
        'docs_link' => 'App router docs on pinoox.com',
    ],
    'help' => [
        'docs' => 'Documentation',
        'tutorials' => 'Tutorials',
        'community' => 'Community',
        'guides' => 'Guides',
        'api' => 'APIs',
        'first_app' => 'Create First App',
        'faq' => 'FAQ',
        'contact' => 'Contact Us',
        'telegram' => 'Telegram',
    ],
    'labels' => [
        'language' => 'Language',
    ],
];
