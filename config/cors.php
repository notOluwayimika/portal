<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', '*'],

    'allowed_methods' => ['*'],

    // Can't use '*' when supports_credentials is true
    'allowed_origins' => [
        'http://portal.test',
        'https://portal.test',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://[::1]:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Must be true for Sanctum session cookies to work
    'supports_credentials' => true,
];
