<?php

return [
    'shop_domain'   => env('SHOPIFY_SHOP_DOMAIN', ''),
    // MODIFIÉ : client_id + client_secret remplacent access_token
    'client_id'     => env('SHOPIFY_CLIENT_ID', ''),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),
    'api_version'   => env('SHOPIFY_API_VERSION', '2025-01'),
];