<?php

/*
|--------------------------------------------------------------------------
| Provisioning Console — application config
|--------------------------------------------------------------------------
|
| Only the keys this app actually overrides. Laravel 12 merges the framework
| defaults from vendor/laravel/framework/config/app.php underneath this file
| (Illuminate\Foundation\Bootstrap\LoadConfiguration), so `providers`,
| `aliases`, `cipher`, `locale`, `maintenance` and friends are inherited.
|
*/

return [

    'name' => env('APP_NAME', 'Evitan Provisioning Console'),

    'env' => env('APP_ENV', 'local'),

    'debug' => (bool) env('APP_DEBUG', true),

    /*
     * The console is a developer tool on a developer machine. Business
     * timezones are per-business settings stored in Postgres, never here.
     */
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'key' => env('APP_KEY'),

];
