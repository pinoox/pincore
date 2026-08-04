<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limiter cache key prefix
    |--------------------------------------------------------------------------
    |
    | Counters are stored via Portal\Cache (file, Redis, …). Prefix keeps
    | rate-limit keys isolated from other cached values.
    |
    */

    'prefix' => env('RATE_LIMITER_PREFIX', 'pinoox_rate:'),

];
