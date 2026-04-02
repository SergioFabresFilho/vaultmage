<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    'api_path' => 'api',

    'export_path' => 'docs/api.json',

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'VaultMage backend API documentation.',
    ],

    'ui' => [
        'title' => 'VaultMage API Docs',
        'theme' => 'light',
        'layout' => 'responsive',
        'try_it_credentials_policy' => 'include',
    ],

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],
];
