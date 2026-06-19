<?php

return [
    'meta' => [
        'dir' => 'ltr',
        'lang' => 'en',
        'name' => 'English',
    ],
    'page' => [
        'title' => 'Pinoox Help',
        'brand_name' => 'Pinoox',
        'welcome_prefix' => 'Welcome to',
        'welcome_suffix' => '',
        'descriptions' => [
            'not_configured' => 'No app is assigned to this address yet. Choose which app should handle this URL.',
            'app_missing' => 'An app is configured for this address, but Pinoox cannot find it. It may be missing or misnamed.',
            'app_disabled' => 'An app is configured for this address, but it is disabled. Enable it or change the assignment.',
        ],
        'labels' => [
            'route_mapping' => 'Current assignment',
            'cli_title' => 'Command line',
            'manager_routes' => 'Routes in Manager',
            'manager_apps' => 'Apps in Manager',
        ],
        'cli_hints' => [
            'not_configured' => 'Use these commands to list, add, or remove URL-to-app assignments:',
            'app_missing' => 'Review current assignments, then point this URL to an installed app:',
            'app_disabled' => 'Use this command to review current URL assignments:',
        ],
        'quick_start_title' => 'How to fix',
        'steps' => [
            'not_configured' => [
                'step1' => 'Run <code dir="ltr">php pinoox app:router</code> to see which URLs are mapped to which apps.',
                'step2' => 'Use the second command below to assign this address, or open <strong>Manager → Routes</strong>.',
            ],
            'app_missing' => [
                'step1' => 'Make sure <code dir="ltr">:package</code> exists under <code dir="ltr">apps/</code>.',
                'step2' => 'Fix the assignment with the second command below, or pick another installed app.',
            ],
            'app_disabled' => [
                'step1' => 'Open <strong>Manager → Apps</strong> and enable <code dir="ltr">:package</code>.',
                'step2' => 'If it should stay off, change this URL assignment in <strong>Manager → Routes</strong>.',
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
