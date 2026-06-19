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
            'not_configured' => 'This URL path is not mapped to any app in the app router. Assign a package to this address to continue.',
            'app_missing' => 'The app router points this URL to a package that App Engine cannot find. Install the app or fix the mapping.',
            'app_disabled' => 'The app router points this URL to an app that exists but is disabled. Enable the app or change the mapping.',
        ],
        'labels' => [
            'route_mapping' => 'Current app router mapping',
            'cli_title' => 'CLI',
            'manager_routes' => 'Manager → Routes',
            'manager_apps' => 'Manager → Apps',
        ],
        'quick_start_title' => 'How to fix',
        'steps' => [
            'not_configured' => [
                'step1' => 'Open <strong>Manager → Routes</strong> and map this URL path to an installed app package.',
                'step2' => 'Or run the CLI command below from your project root, then refresh this page.',
            ],
            'app_missing' => [
                'step1' => 'Make sure <code dir="ltr">:package</code> is installed under <code dir="ltr">apps/</code> and visible to App Engine.',
                'step2' => 'Update the mapping in <strong>Manager → Routes</strong> or with the CLI command below, then refresh.',
            ],
            'app_disabled' => [
                'step1' => 'Open <strong>Manager → Apps</strong>, find <code dir="ltr">:package</code>, and turn it on.',
                'step2' => 'If it should stay off, remove or change this mapping in <strong>Manager → Routes</strong> (or use the CLI below).',
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
