<?php

declare(strict_types=1);

use Kanin\Middleware\AuthenticateToken;
use Kanin\Middleware\HandleCors;

return [
    // The application name, returned by GET /api.
    'name' => env('APP_NAME', 'KaninPHP'),

    // "local", "testing" or "production".
    'env' => env('APP_ENV', 'production'),

    // Show exception details in error responses. NEVER true in production.
    'debug' => (bool) env('APP_DEBUG', false),

    // Public base URL, used by the url() helper.
    'url' => env('APP_URL', 'http://localhost:8000'),

    // Default timezone for date() and friends.
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Global middleware: runs on every request, first listed runs outermost.
    'middleware' => [
        HandleCors::class,
    ],

    // Short names for route middleware: ->middleware('auth.token').
    'middleware_aliases' => [
        'auth.token' => AuthenticateToken::class,
    ],
];
