<?php

return [
    'adapter_mode' => env('WHISPER_ADAPTER_MODE', 'fake'),
    'webhook_verify_token' => env('DENTOLIZE_WEBHOOK_VERIFY_TOKEN', 'local-secret'),
    'qoyod_generic_product_id' => env('QOYOD_GENERIC_PRODUCT_ID', '1'),
    'default_inventory_id' => env('QOYOD_DEFAULT_INVENTORY_ID', '1'),
    'default_account_id' => env('QOYOD_DEFAULT_ACCOUNT_ID', '1'),
    'vat_rate' => env('VAT_RATE', '15'),
];
