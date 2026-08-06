<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'cloud' => env('FILESYSTEM_CLOUD', 's3'),
    'app_disk' => env('FILESYSTEM_APP_DISK', env('FILESYSTEM_DISK', 'local')),
    // Private package files live under the local disk root: storage/local/{package}
    'app_root' => env('FILESYSTEM_APPS_ROOT', env('FILESYSTEM_LOCAL_ROOT', '~storage/local')),
    'app_prefix' => env('FILESYSTEM_APPS_PREFIX', ''),
    'public_root' => env('FILESYSTEM_PUBLIC_ROOT', '~storage/public'),
    'hash_length' => (int) env('FILE_HASH_LENGTH', 8),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => env('FILESYSTEM_LOCAL_ROOT', '~storage/local'),
            'protect' => 'lock',
            'visibility' => 'private',
            'throw' => env('FILESYSTEM_THROW', false),
        ],

        'public' => [
            'driver' => 'local',
            'root' => env('FILESYSTEM_PUBLIC_ROOT', '~storage/public'),
            'url' => env('FILESYSTEM_PUBLIC_URL', rtrim((string) env('APP_URL', ''), '/') . '/storage'),
            'protect' => 'unlock',
            'visibility' => 'public',
            'throw' => env('FILESYSTEM_THROW', false),
        ],

        'temp' => [
            'driver' => 'local',
            'root' => env('FILESYSTEM_TEMP_ROOT', '~storage/tmp'),
            'protect' => 'lock',
            'visibility' => 'private',
            'throw' => env('FILESYSTEM_THROW', false),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => env('FILESYSTEM_THROW', false),
        ],
    ],

    'links' => [
        env('FILESYSTEM_PUBLIC_LINK', 'public/storage') => env('FILESYSTEM_PUBLIC_ROOT', '~storage/public'),
    ],
];
