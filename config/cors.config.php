<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default CORS policy name
    |--------------------------------------------------------------------------
    |
    | Used by CorsFlow when no policy is given (flow('cors') or Cors::apply()).
    | Apps should Cors::define() this name (or rely on the built-in permissive
    | "default" policy registered by the Cors portal).
    |
    */

    'default' => env('CORS_DEFAULT_POLICY', 'default'),

];
