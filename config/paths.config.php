<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Project paths
    |--------------------------------------------------------------------------
    |
    | Aliases: ~, ~config (framework), ~project (deploy), ~pincore, ~pinker, ~storage.
    | Framework config: pincore/config — project override: {project}/platform/*.config.php
    |
    */
    'config' => env('PINOOX_CONFIG_PATH', '~pincore/config'),
    'pinker_config' => env('PINOOX_PINKER_CONFIG_PATH', '~pinker/platform'),
    'system' => env('PINOOX_CONFIG_PATH', '~pincore/config'),
    'apps' => env('PINOOX_APPS_PATH', 'apps'),
    'pinker' => env('PINOOX_PINKER_PATH', 'pinker'),
    'storage' => env('PINOOX_STORAGE_PATH', 'storage'),

    'project_config' => env('PINOOX_PROJECT_CONFIG_PATH', '~/platform'),
    'project_registry' => env('PINOOX_PROJECT_REGISTRY_PATH', '~/platform/apps.config.php'),
    'project_router' => env('PINOOX_PROJECT_ROUTER_PATH', '~/platform/app-router.config.php'),
    'project_domain' => env('PINOOX_PROJECT_DOMAIN_PATH', '~/platform/domain.config.php'),
    'project_pinoox' => env('PINOOX_PROJECT_PINOOX_PATH', '~/platform/pinoox.config.php'),
    'project_pincore' => env('PINOOX_PROJECT_PINCORE_PATH', '~pincore/config/pincore.config.php'),

    'platform_lang' => env('PINOOX_PLATFORM_LANG_PATH', '~pincore/lang'),
    'platform_migrations' => env('PINOOX_PLATFORM_MIGRATIONS_PATH', '~pincore/database/migrations'),
    'platform_seed' => env('PINOOX_PLATFORM_SEED_PATH', '~pincore/database/seed'),
    'platform_patches' => env('PINOOX_PLATFORM_PATCHES_PATH', '~pincore/patches'),
    'platform_models' => env('PINOOX_PLATFORM_MODELS_PATH', '~pincore/Model'),

    'stubs' => env('PINOOX_STUBS_PATH', '~pincore/stubs'),
    'app_file' => env('PINOOX_APP_FILE', 'app.php'),
    'app_migrations' => env('PINOOX_APP_MIGRATIONS_PATH', 'database/migrations'),
    'app_seed' => env('PINOOX_APP_SEED_PATH', 'database/seed'),
    'app_patches' => env('PINOOX_APP_PATCHES_PATH', 'patches'),
    'app_lang' => env('PINOOX_APP_LANG_PATH', 'lang'),
    'app_config' => env('PINOOX_APP_CONFIG_PATH', 'config'),
    'wizard_tmp' => env('PINOOX_WIZARD_TMP_PATH', '~pinker/wizard_tmp'),
    'pinion_uploads' => env('PINOOX_PINION_UPLOADS_PATH', '~storage/pinion'),
    'package_manual' => env('PINOOX_PACKAGE_MANUAL_PATH', '~storage/downloads/packages/manual'),
];
