<?php

return [
    'name' => 'Shopify',
    'shop_domain' => env('SHOPIFY_SHOP_DOMAIN', ''),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
];
