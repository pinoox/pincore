<?php



return [

    'protocol' => 'pinion',

    'protocol_version' => 2,

    'chunk_size' => (int) env('PINION_CHUNK_SIZE', 5 * 1024 * 1024),

    'min_chunk_size' => 1024 * 1024,

    'max_chunk_size' => 10 * 1024 * 1024,

    'ttl' => (int) env('PINION_TTL', 86400),

    'max_file_size' => (int) env('PINION_MAX_FILE', 2 * 1024 * 1024 * 1024),

    'storage_path' => env('PINION_PATH', '~storage/pinion'),

    'storage_strategy' => env('PINION_STRATEGY', 'parts'),

    'verify_chunks' => (bool) env('PINION_VERIFY_CHUNKS', true),

    'verify_file_hash' => (bool) env('PINION_VERIFY_FILE', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP handler defaults (merged into init meta)
    |--------------------------------------------------------------------------
    |
    | mode: auto | local | storage
    |   auto    — use Flysystem when app disk is not "local"
    |   local   — assemble on project disk via path()
    |   storage — always publish through Portal\File / Flysystem (S3, etc.)
    |
    */
    'defaults' => [
        'mode' => env('PINION_MODE', 'auto'),
        'storage' => null,
        'disk' => null,
        'access' => null,
        'record' => true,
        'destination' => 'uploads',
    ],

];

