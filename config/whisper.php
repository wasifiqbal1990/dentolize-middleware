<?php

return [
    'adapter_mode' => env('WHISPER_ADAPTER_MODE', 'fake'),
    'webhook_verify_token' => env('DENTOLIZE_WEBHOOK_VERIFY_TOKEN', 'local-secret'),
    'dentolize_graphql_url' => env('DENTOLIZE_GRAPHQL_URL', 'https://api.dentolize.com/'),
    'dentolize_session_cookie' => env('DENTOLIZE_SESSION_COOKIE', ''),
    'qoyod_base_url' => env('QOYOD_BASE_URL', 'https://api.qoyod.com/2.0/'),
    'qoyod_api_key' => env('QOYOD_API_KEY', ''),
    'qoyod_generic_product_id' => env('QOYOD_GENERIC_PRODUCT_ID', '1'),
    'default_inventory_id' => env('QOYOD_DEFAULT_INVENTORY_ID', '1'),
    'default_account_id' => env('QOYOD_DEFAULT_ACCOUNT_ID', '1'),
    'vat_rate' => env('VAT_RATE', '15'),
];
