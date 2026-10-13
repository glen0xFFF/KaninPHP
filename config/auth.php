<?php

declare(strict_types=1);

return [
    // Valid API tokens for the "auth.token" middleware, from API_TOKENS (comma-separated).
    // An empty list denies every request. Generate a token with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_TOKENS', ''))))),
];
