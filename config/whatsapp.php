<?php

return [
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'on_demand_worker' => env('WHATSAPP_ON_DEMAND_WORKER', false),
    'worker_socket' => env('WHATSAPP_WORKER_SOCKET', storage_path('framework/whatsapp-worker.sock')),
    'enabled' => env('WHATSAPP_ENABLED', false),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'chat_number' => env('WHATSAPP_CHAT_NUMBER'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'api_version' => env('WHATSAPP_API_VERSION'),
    'backends' => [
        'retail' => ['url' => env('RETAIL_API_URL')],
        'production' => ['url' => env('PRODUCTION_API_URL')],
    ],
];
