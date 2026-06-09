<?php

return [
    'actions' => [[
            'name' => 'installer.home',
            'handler' => 'App\\com_pinoox_installer\\Controller\\MainController::home',
            'handler_ref' => [
                'type' => 'controller',
                'class' => 'App\\com_pinoox_installer\\Controller\\MainController',
                'method' => 'home'
            ],
            'cacheable' => true,
            'description' => '',
            'flows' => [],
            'tags' => [],
            'file' => 'apps/com_pinoox_installer/routes/actions.php',
            'line' => 6,
            'group' => 'installer',
            'routes' => ['installer.home'],
            'used' => true
        ]]
];
