<?php

return [
    'meta' => [
        'dir' => 'rtl',
        'lang' => 'fa',
        'name' => 'فارسی',
    ],
    'page' => [
        'title' => 'راهنمای Pinoox',
        'welcome_prefix' => 'به',
        'welcome_suffix' => 'خوش آمدید',
        'descriptions' => [
            'not_configured' => 'این مسیر URL در app router به هیچ اپی متصل نشده است. برای ادامه یک پکیج به این آدرس اختصاص دهید.',
            'app_missing' => 'app router این URL را به پکیجی اشاره می‌کند که App Engine آن را پیدا نمی‌کند. اپ را نصب کنید یا mapping را اصلاح کنید.',
            'app_disabled' => 'app router این URL را به اپی اشاره می‌کند که وجود دارد ولی غیرفعال است. اپ را فعال کنید یا mapping را تغییر دهید.',
        ],
        'labels' => [
            'route_mapping' => 'mapping فعلی app router',
            'cli_title' => 'خط فرمان',
            'manager_routes' => 'منیجر → مسیرها',
            'manager_apps' => 'منیجر → اپ‌ها',
        ],
        'quick_start_title' => 'راه‌حل',
        'steps' => [
            'not_configured' => [
                'step1' => 'از <strong>منیجر → مسیرها</strong> این مسیر URL را به یک پکیج نصب‌شده متصل کنید.',
                'step2' => 'یا دستور CLI زیر را از ریشه پروژه اجرا کنید و صفحه را رفرش کنید.',
            ],
            'app_missing' => [
                'step1' => 'مطمئن شوید <code dir="ltr">:package</code> در <code dir="ltr">apps/</code> نصب شده و App Engine آن را می‌شناسد.',
                'step2' => 'mapping را در <strong>منیجر → مسیرها</strong> یا با دستور CLI زیر اصلاح کنید و رفرش کنید.',
            ],
            'app_disabled' => [
                'step1' => 'از <strong>منیجر → اپ‌ها</strong> اپ <code dir="ltr">:package</code> را پیدا کنید و فعالش کنید.',
                'step2' => 'اگر باید غیرفعال بماند، این mapping را در <strong>منیجر → مسیرها</strong> حذف یا تغییر دهید (یا از CLI زیر).',
            ],
        ],
        'docs_link' => 'مستندات app router در pinoox.com',
    ],
    'help' => [
        'docs' => 'مستندات',
        'tutorials' => 'آموزش',
        'community' => 'جامعه',
        'guides' => 'راهنما',
        'api' => 'API',
        'first_app' => 'ساخت اولین اپ',
        'faq' => 'سوالات متداول',
        'contact' => 'تماس با ما',
        'telegram' => 'تلگرام',
    ],
    'labels' => [
        'language' => 'زبان',
    ],
];
