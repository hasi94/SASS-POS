<?php

/*
|--------------------------------------------------------------------------
| Database connection owned by the demo service
|--------------------------------------------------------------------------
|
| Merged into config('database.connections') by DemoServiceProvider, so
| the host application never has to know this service exists. One schema per
| service: no other service may read or write through this connection.
|
| Provisioning the schema itself (and its dedicated MySQL user) is a separate
| backlog row; the connection only has to be configured here.
|
*/

return [

    'svc_demo' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_SCHEMA_DEMO', 'pos_demo'),
        'username' => env('DB_USERNAME_DEMO', env('DB_USERNAME', 'root')),
        'password' => env('DB_PASSWORD_DEMO', env('DB_PASSWORD', '')),
        'unix_socket' => env('DB_SOCKET', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
    ],

];
