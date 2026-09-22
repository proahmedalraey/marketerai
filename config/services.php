<?php

return [

    'salla' => [
        'client_id' => env('SALLA_CLIENT_ID'),
        'client_secret' => env('SALLA_CLIENT_SECRET'),
        'redirect' => env('SALLA_REDIRECT_URI'),
        'base_url' => env('SALLA_BASE_URL', 'https://api.salla.dev/admin/v2'),
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect' => env('META_REDIRECT_URI'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    ],

];
