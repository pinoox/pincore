<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Project paths
    |--------------------------------------------------------------------------
    |
    | Aliases: ~, ~config (framework), ~project (deploy), ~pincore, ~pinker, ~storage.
    | Framework config: pincore/config — deploy config: {project}/config
    |
    */
    'config' => '~pincore/config',
    'pinker_config' => '~pinker/config',
    'system' => '~pincore/config',
    'apps' => env('PINOOX_APPS_PATH', 'apps'),
    'pinker' => env('PINOOX_PINKER_PATH', 'pinker'),
    'storage' => env('PINOOX_STORAGE_PATH', 'storage'),

    'project_config' => env('PINOOX_PROJECT_CONFIG_PATH', '~/config'),
    'project_registry' => env('PINOOX_PROJECT_REGISTRY_PATH', '~/config/apps.config.php'),
    'project_router' => '~/config/app-router.config.php',
    'project_domain' => '~/config/domain.config.php',
    'project_pinoox' => '~/config/pinoox.config.php',
    'project_pincore' => '~pincore/config/pincore.config.php',

    'platform_lang' => '~pincore/lang',
    'platform_migrations' => '~pincore/database/migrations',
    'platform_seed' => '~pincore/database/seed',
    'platform_patches' => '~pincore/patches',
    'platform_models' => '~pincore/Model',

    'stubs' => '~pincore/stubs',
    'app_file' => 'app.php',
    'app_migrations' => 'database/migrations',
    'app_seed' => 'database/seed',
    'app_patches' => 'patches',
    'app_lang' => 'lang',
    'app_config' => 'config',
    'wizard_tmp' => '~pinker/wizard_tmp',
];
