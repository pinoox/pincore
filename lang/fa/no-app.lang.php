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
            'not_configured' => 'برای این آدرس هیچ اپی در app router مشخص نشده است. این URL را در فایل app-router به یک پکیج نصب‌شده متصل کنید.',
            'app_missing' => 'برای این آدرس اپی تنظیم شده، اما Pinoox آن پکیج را در App Engine پیدا نکرد.',
            'app_disabled' => 'برای این آدرس اپی تنظیم شده، اما در manifest اپ غیرفعال است.',
        ],
        'labels' => [
            'request_path' => 'مسیر درخواست',
            'host' => 'هاست',
            'configured_path' => 'مسیر روتر',
            'configured_package' => 'پکیج تنظیم‌شده',
        ],
        'quick_start_title' => 'راه‌حل',
        'steps' => [
            'not_configured' => [
                'step1' => 'فایل <code dir="ltr">:router_file</code> را در پوشه config پروژه باز کنید.',
                'step2' => 'برای این آدرس یک مسیر اضافه کنید، مثلاً <code dir="ltr">\'/\' =&gt; \'com_vendor_myapp\'</code>، سپس صفحه را رفرش کنید.',
            ],
            'app_missing' => [
                'step1' => 'بررسی کنید پکیج <code dir="ltr">:package</code> در apps وجود دارد و در App Engine ثبت شده است.',
                'step2' => 'مسیر را در <code dir="ltr">:router_file</code> اصلاح کنید یا اپ گم‌شده را نصب کنید، سپس رفرش کنید.',
            ],
            'app_disabled' => [
                'step1' => 'فایل <code dir="ltr">apps/:package/app.php</code> را باز کنید و <code dir="ltr">\'enable\' =&gt; true</code> بگذارید.',
                'step2' => 'اگر اپ باید غیرفعال بماند، مسیر آن را در <code dir="ltr">:router_file</code> حذف یا تغییر دهید.',
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
