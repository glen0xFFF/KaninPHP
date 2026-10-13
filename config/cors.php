<?php

declare(strict_types=1);

return [
    // Origins allowed to call the API from a browser. "*" means any origin.
    // Set CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com to restrict.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],

    // Response headers the browser's JavaScript is allowed to read.
    'exposed_headers' => [],

    // How long (seconds) a browser may cache a preflight response.
    'max_age' => 600,

    // Allow cookies/credentials. Keep false for token-based APIs.
    // WARNING: '*' origins combined with true reflects any origin with credentials,
    // which is unsafe for production. List explicit origins if you enable this.
    'supports_credentials' => false,
];
