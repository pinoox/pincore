<?php



return [

    /*

    |--------------------------------------------------------------------------

    | Pinoox platform runtime defaults (pincore template)

    |--------------------------------------------------------------------------

    |

    | Runtime defaults for standalone pincore (config/pinoox.config.php).

    | Platform projects override via {project}/platform/pinoox.config.php.

    | Kernel version: pincore/config/pincore.config.php

    | Per-environment overrides: .env (APP_NAME, APP_DEBUG, APP_LOCALE, LOG_*, …)

    |

    */

    'version_code' => 0,

    'version_name' => 'dev',

    'name' => env('APP_NAME', 'Pinoox'),

    'lang' => env('APP_LOCALE', 'en'),

    'lang_fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),



    /*

    |--------------------------------------------------------------------------

    | Runtime mode

    |--------------------------------------------------------------------------

    |

    | development | production | staging | test
    | Apps may override via app.php → runtime.mode / runtime.debug

    |

    */

    'mode' => runtime_env_mode(),



    /*

    |--------------------------------------------------------------------------

    | Debug (APP_DEBUG)

    |--------------------------------------------------------------------------

    |

    | Application debug: route action validation, Twig debug, etc.

    | Default: false in production and test, true in other modes (see EnvBootstrap).

    |

    */

    'debug' => env('APP_DEBUG', \Pinoox\Component\Runtime\RuntimeMode::defaultDebugForMode()),



    /*

    |--------------------------------------------------------------------------

    | Pinoox Exception (PINOOX_EXCEPTION)

    |--------------------------------------------------------------------------

    |

    | Rich exception page and boot-time error handler (PinooxDebug).

    | Independent of APP_DEBUG — defaults to true in all modes.

    | true  → detailed Pinoox Exception page (dev)

    | false → friendly "something went wrong" page / generic JSON (production)

    | The handler is always registered so PHP never dumps raw stack traces.

    |

    */

    'exception' => env('PINOOX_EXCEPTION', true),



    'log' => [

        'driver' => env('LOG_DRIVER', 'size_daily'),

        'path' => env('LOG_PATH', '~storage/logs/pinoox.log'),

        'channel' => env('LOG_CHANNEL', 'pinoox'),

        // Unset defaults dynamically by runtime mode: 'error' in production, 'debug' in dev
        'level' => env('LOG_LEVEL', null),

        'rotate' => (bool) env('LOG_ROTATE', true),

        'max_files' => (int) env('LOG_MAX_FILES', 14),

        'max_size' => env('LOG_MAX_SIZE', '10MB'),

        'compress' => (bool) env('LOG_COMPRESS', false),

        'buffer' => (bool) env('LOG_BUFFER', false),

        'deduplicate' => (bool) env('LOG_DEDUPLICATE', false),

        'deduplicate_time' => (int) env('LOG_DEDUPLICATE_TIME', 60),

    ],

];

