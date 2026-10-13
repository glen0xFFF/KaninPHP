<?php

declare(strict_types=1);

return [
    // Which connection below to use: "sqlite", "mysql" or "pgsql".
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            // A file path, or ":memory:" for a throw-away database.
            'database' => env('DB_DATABASE') ?: dirname(__DIR__) . '/database/database.sqlite',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'kanin'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'kanin'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
        ],
    ],
];
