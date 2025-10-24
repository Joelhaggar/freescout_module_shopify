# Shopify Module Conversion Plan

This document outlines the complete plan for converting the WooCommerce module into a Shopify module for FreeScout.

## Overview

Convert the existing WooCommerce integration module to work with Shopify, maintaining the same functionality: displaying customer order history in the Customer Profile pane within conversations.

---

## Key Differences Between WooCommerce and Shopify APIs

### WooCommerce API
- **Authentication**: OAuth 1.0a (Consumer Key + Consumer Secret in URL params)
- **Endpoint**: `https://{domain}/wp-json/wc/v{version}/orders`
- **Email Filtering**: Supports filtering orders by customer email via `?search=` parameter
- **Version Format**: Numeric (e.g., `v2`, `v3`)
- **Single API Call**: Can fetch orders by email directly

### Shopify API
- **Authentication**: Access Token (`X-Shopify-Access-Token` header)
- **Customer Search Endpoint**: `https://{shop}.myshopify.com/admin/api/{version}/customers/search.json`
- **Customer Orders Endpoint**: `https://{shop}.myshopify.com/admin/api/{version}/customers/{customer_id}/orders.json`
- **Email Filtering**: Does NOT support filtering by email in REST API - requires customer ID lookup
- **Version Format**: Date-based (e.g., `2025-01`, released quarterly)
- **Current Supported Versions**: `2025-01` (latest), `2024-10`, `2024-07`, `2024-04` (each supported 12 months)
- **Two-Step Process**: Must lookup customer by email, then fetch orders using customer-specific endpoint
- **Note**: REST API is legacy (as of Oct 2024), but still fully functional for custom apps

---

## Customer ID Caching Strategy (OPTIMIZATION)

To minimize API calls and improve performance, we'll store Shopify customer IDs in the FreeScout database:

### Database Schema Addition
Add `shopify_customer_id` column to the `customers` table (or create a separate lookup table).

### Logic Flow
1. **Check FreeScout DB**: Look for existing `shopify_customer_id` for the customer
2. **If ID exists**: Make single API call to fetch orders using customer_id
3. **If ID doesn't exist**:
   - Make API call to search customer by email
   - Store returned customer_id in FreeScout DB
   - Make second API call to fetch orders
4. **Future requests**: Use cached customer_id (only 1 API call needed)

### Benefits
- **First request**: 2 API calls (lookup + fetch + store ID)
- **All subsequent requests**: 1 API call (direct fetch)
- Reduces API usage by 50% after initial lookup
- Improves response time
- More respectful of Shopify rate limits

### Migration Consideration
Create migration: `add_shopify_customer_id_to_customers_table`

---

## Phase 1: Rename & File Structure (18 files total)

### Root Files
1. **Directory**: Rename `WooCommerce/` → `Shopify/`
2. **module.json**:
   - `name`: "WooCommerce Integration" → "Shopify Integration"
   - `alias`: "woocommerce" → "shopify"
   - `description`: Update to reference Shopify
   - `version`: Reset to "1.0.0"
   - `providers`: Update namespace reference

3. **composer.json**:
   - `name`: "freescout/woocommerce" → "freescout/shopify"
   - PSR-4 namespace: `Modules\\WooCommerce\\` → `Modules\\Shopify\\`

### PHP Files (rename + namespace changes)

| Current File | New File |
|-------------|----------|
| `Providers/WooCommerceServiceProvider.php` | `Providers/ShopifyServiceProvider.php` |
| `Http/Controllers/WooCommerceController.php` | `Http/Controllers/ShopifyController.php` |
| `Database/Seeders/WooCommerceDatabaseSeeder.php` | `Database/Seeders/ShopifyDatabaseSeeder.php` |
| `Database/Migrations/2021_02_02_010101_add_wc_column_to_mailboxes_table.php` | `YYYY_MM_DD_HHMMSS_add_shopify_column_to_mailboxes_table.php` |

### View Files (7 Blade templates)
- Update all view file references
- Change view namespace from `woocommerce::` to `shopify::`
- Files remain in `Resources/views/` with updated content

### Frontend Assets
- `Public/js/module.js` - Update function names, route names
- `Public/js/laroute.js` - Update route references
- `Public/css/module.css` - Update CSS class names from `wc-` to `shopify-`

### Config
- `Config/config.php` - Update environment variable names and structure

---

## Phase 2: Configuration Changes

### Environment Variables

| WooCommerce | Shopify | Notes |
|------------|---------|-------|
| `WOOCOMMERCE_URL` | `SHOPIFY_SHOP_DOMAIN` | e.g., `mystore.myshopify.com` |
| `WOOCOMMERCE_KEY` | `SHOPIFY_ACCESS_TOKEN` | Single Admin API access token |
| `WOOCOMMERCE_SECRET` | *(removed)* | Not needed for Shopify |
| `WOOCOMMERCE_VERSION` | `SHOPIFY_API_VERSION` | e.g., `2025-01` |

### Database Schema

**Mailbox Settings Column:**
- Rename `mailboxes.wc` → `mailboxes.shopify`

**JSON Structure Change:**
```php
// OLD (WooCommerce)
{
    "url": "example.com",
    "key": "ck_...",
    "secret": "cs_...",
    "version": "3"
}

// NEW (Shopify)
{
    "shop_domain": "mystore.myshopify.com",
    "access_token": "shpat_...",
    "api_version": "2025-01"
}
```

**Customer ID Caching (NEW):**
Add column to `customers` table:
```php
$table->string('shopify_customer_id')->nullable();
```

### Settings UI Updates

**Global Settings Page** (`settings.blade.php`):
- Remove "Consumer Secret" field
- Change "Consumer Key" → "Admin API Access Token"
- Change "URL" → "Shop Domain"
- Update placeholder: `mystore.myshopify.com`
- Update help text to explain Shopify custom apps
- Default API version: `"2025-01"`

**Per-Mailbox Settings** (`mailbox_settings.blade.php`):
- Same field updates as global settings
- Explain per-mailbox override behavior

---

## Phase 3: API Integration Rewrite

### Authentication Method Change

**OLD (WooCommerce - OAuth 1.0a):**
```php
$url = $request_url.'?consumer_key='.$key.'&consumer_secret='.$secret.'&per_page=5&search='.$email;
curl_setopt($ch, CURLOPT_URL, $url);
```

**NEW (Shopify - Access Token):**
```php
$url = $request_url.'?limit=5&customer_id='.$customer_id;
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Shopify-Access-Token: ' . $access_token,
    'Content-Type: application/json'
]);
```

### API Call Strategy with Customer ID Caching

Update `apiGetOrders()` method in `ShopifyServiceProvider.php`:

```php
public static function apiGetOrders($customer_email, $customer, $mailbox = null)
{
    $response = ['error' => '', 'data' => []];

    // Get credentials (global or per-mailbox)
    if ($mailbox && \Shopify::isMailboxApiEnabled($mailbox)) {
        $settings = self::getMailboxShopifySettings($mailbox);
        $shop_domain = $settings['shop_domain'];
        $access_token = $settings['access_token'];
        $api_version = $settings['api_version'];
    } else {
        $shop_domain = config('shopify.shop_domain');
        $access_token = config('shopify.access_token');
        $api_version = config('shopify.api_version');
    }

    $shop_url = 'https://' . $shop_domain;

    // OPTIMIZATION: Check if we already have Shopify customer ID
    $shopify_customer_id = $customer->shopify_customer_id ?? null;

    if (!$shopify_customer_id) {
        // Step 1: Lookup customer by email
        $customer_search_url = $shop_url . '/admin/api/' . $api_version . '/customers/search.json?query=email:' . $customer_email;

        $customer_result = self::makeShopifyApiCall($customer_search_url, $access_token);

        if (!empty($customer_result['error'])) {
            return ['error' => $customer_result['error'], 'data' => []];
        }

        if (empty($customer_result['data']['customers'][0]['id'])) {
            // Customer not found in Shopify
            return ['error' => '', 'data' => []];
        }

        $shopify_customer_id = $customer_result['data']['customers'][0]['id'];

        // Store customer ID for future use
        $customer->shopify_customer_id = $shopify_customer_id;
        $customer->save();
    }

    // Step 2: Fetch orders by customer ID (using customer-specific orders endpoint)
    $orders_url = $shop_url . '/admin/api/' . $api_version . '/customers/' . $shopify_customer_id . '/orders.json?status=any&limit=' . self::MAX_ORDERS;

    $orders_result = self::makeShopifyApiCall($orders_url, $access_token);

    if (!empty($orders_result['error'])) {
        return ['error' => $orders_result['error'], 'data' => []];
    }

    return ['error' => '', 'data' => $orders_result['data']['orders'] ?? []];
}

private static function makeShopifyApiCall($url, $access_token)
{
    $response = ['error' => '', 'data' => []];

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        \Helper::setCurlDefaultOptions($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Shopify-Access-Token: ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, config('app.curl_user_agent') ?: 'FreeScout-Shopify-Integration');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $json = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json_decoded = json_decode($json, true);

        if ($status_code == 200) {
            $response['data'] = $json_decoded;
        } else {
            $response['error'] = 'HTTP Status Code: ' . $status_code . ' (' . self::errorCodeDescr($status_code) . ')';

            if (!empty($json_decoded['errors'])) {
                $response['error'] .= ' | API Error: ' . json_encode($json_decoded['errors']);
            }
        }

    } catch (\Exception $e) {
        $response['error'] = $e->getMessage();
    }

    if ($response['error']) {
        $response['error'] .= ' | Requested resource: ' . $url;
    }

    return $response;
}
```

### URL Construction

```php
// OLD (WooCommerce)
$request_url = $url.'wp-json/wc/v'.$version.'/orders';

// NEW (Shopify - Customer Search)
$shop_url = 'https://' . $shop_domain; // e.g., mystore.myshopify.com
$customer_search_url = $shop_url . '/admin/api/' . $version . '/customers/search.json?query=email:' . $email;

// NEW (Shopify - Customer Orders)
$orders_url = $shop_url . '/admin/api/' . $version . '/customers/' . $customer_id . '/orders.json?status=any&limit=5';
```

---

## Phase 4: Response Data Mapping

### Order Data Structure Differences

| Field | WooCommerce | Shopify | Action Required |
|-------|-------------|---------|-----------------|
| Order ID | `$order['id']` | `$order['id']` | No change |
| Order Number | `$order['number']` | `$order['order_number']` | Update field name |
| Total | `$order['total']` | `$order['total_price']` | Update field name |
| Currency | `$order['currency']` | `$order['currency']` | No change |
| Date | `$order['date_created']` | `$order['created_at']` | Update field name |
| Status | `$order['status']` | `$order['financial_status']` | Update field + logic |
| Admin Link | WP Admin post | Shopify admin order | Update URL structure |

### Update View Template

**File**: `Resources/views/partials/orders_list.blade.php`

**Line 22 - Order Link:**
```php
// OLD
<a href="{{ $url }}wp-admin/post.php?post={{ $order['id'] }}&amp;action=edit" target="_blank">
    #{{ $order['number'] }}
</a>

// NEW
<a href="{{ $url }}/admin/orders/{{ $order['id'] }}" target="_blank">
    #{{ $order['order_number'] }}
</a>
```

**Line 23 - Total:**
```php
// OLD
<span class="pull-right">{{ $order['currency'] }} {{ $order['total'] }}</span>

// NEW
<span class="pull-right">{{ $order['currency'] }} {{ $order['total_price'] }}</span>
```

**Line 26 - Date:**
```php
// OLD
<small class="text-help">{{ \WooCommerce::formatDate($order['date_created']) }}</small>

// NEW
<small class="text-help">{{ \Shopify::formatDate($order['created_at']) }}</small>
```

**Line 27-29 - Status:**
```php
// OLD
<small class="pull-right @if ($order['status'] == 'completed') text-success @else text-warning @endif ">
    {{ __(ucfirst($order['status'])) }}
</small>

// NEW
<small class="pull-right @if (in_array($order['financial_status'], ['paid', 'refunded', 'partially_refunded'])) text-success @else text-warning @endif ">
    {{ __(ucfirst($order['financial_status'])) }}
</small>
```

### Status Field Mapping

**WooCommerce Statuses:**
- `pending`, `processing`, `completed`, `cancelled`, `refunded`, `failed`

**Shopify Financial Statuses:**
- `pending`, `authorized`, `partially_paid`, `paid`, `partially_refunded`, `refunded`, `voided`

**Status Color Logic:**
- Green (success): `paid`, `refunded`, `partially_refunded`
- Yellow (warning): `pending`, `authorized`, `partially_paid`
- Red (danger): `voided`

---

## Phase 5: Code Refactoring

### Global Search & Replace Operations

Run these find/replace operations across all files:

| Find | Replace | Scope |
|------|---------|-------|
| `Modules\WooCommerce\` | `Modules\Shopify\` | All PHP files |
| `Modules\\WooCommerce\\` | `Modules\\Shopify\\` | JSON files (escape) |
| `WC_MODULE` | `SHOPIFY_MODULE` | All PHP files |
| `\WooCommerce::` | `\Shopify::` | All PHP files |
| `woocommerce.ajax` | `shopify.ajax` | PHP & JS files |
| `mailboxes.woocommerce` | `mailboxes.shopify` | PHP files |
| `woocommerce::` | `shopify::` | PHP files (views) |
| `wc-` | `shopify-` | CSS & Blade files |
| `wc_` | `shopify_` | JS files |
| `wcLoadOrders` | `shopifyLoadOrders` | JS files |
| `wc_orders_` | `shopify_orders_` | PHP files (cache keys) |
| `'woocommerce'` | `'shopify'` | Config references |

### Method Renames

| Old Method | New Method |
|------------|------------|
| `getMailboxWcSettings()` | `getMailboxShopifySettings()` |
| `isApiEnabled()` | `isApiEnabled()` (no change) |
| `isMailboxApiEnabled()` | `isMailboxApiEnabled()` (no change) |
| `apiGetOrders()` | `apiGetOrders()` (no change) |
| `getSanitizedUrl()` | `getSanitizedShopDomain()` |

### Variable Renames

**JavaScript** (`Public/js/module.js`):
- `wc_customer_emails` → `shopify_customer_emails`
- `initWooCommerce()` → `initShopify()`
- `wcLoadOrders()` → `shopifyLoadOrders()`

**CSS** (`Public/css/module.css`):
- `.wc-loading` → `.shopify-loading`
- `.wc-orders-list` → `.shopify-orders-list`
- `.wc-collapse-orders` → `.shopify-collapse-orders`
- `.wc-no-orders` → `.shopify-no-orders`
- `.wc-refresh` → `.shopify-refresh`
- `#wc-orders` → `#shopify-orders`
- `#wc-loader` → `#shopify-loader`

---

## Phase 6: Specific Code Updates

### Files Requiring Significant Logic Changes

#### 1. `Providers/ShopifyServiceProvider.php`

**Lines 15-16: Constants**
```php
// OLD
const MAX_ORDERS = 5;

// NEW
const MAX_ORDERS = 5; // No change
```

**Lines 249-252: isApiEnabled()**
```php
// OLD
public static function isApiEnabled()
{
    return (config('woocommerce.url') && config('woocommerce.key') && config('woocommerce.secret') && config('woocommerce.version'));
}

// NEW
public static function isApiEnabled()
{
    return (config('shopify.shop_domain') && config('shopify.access_token') && config('shopify.api_version'));
}
```

**Lines 254-262: isMailboxApiEnabled()**
```php
// OLD
public static function isMailboxApiEnabled($mailbox)
{
    if (empty($mailbox) || empty($mailbox->wc)) {
        return false;
    }
    $settings = self::getMailboxWcSettings($mailbox);
    return (!empty($settings['url']) && !empty($settings['key']) && !empty($settings['secret']) && !empty($settings['version']));
}

// NEW
public static function isMailboxApiEnabled($mailbox)
{
    if (empty($mailbox) || empty($mailbox->shopify)) {
        return false;
    }
    $settings = self::getMailboxShopifySettings($mailbox);
    return (!empty($settings['shop_domain']) && !empty($settings['access_token']) && !empty($settings['api_version']));
}
```

**Lines 264-267: getMailboxShopifySettings()**
```php
// OLD
public static function getMailboxWcSettings($mailbox)
{
    return json_decode($mailbox->wc ?: '', true);
}

// NEW
public static function getMailboxShopifySettings($mailbox)
{
    return json_decode($mailbox->shopify ?: '', true);
}
```

**Lines 280-293: getSanitizedShopDomain()**
```php
// OLD
public static function getSanitizedUrl($url = '')
{
    if (empty($url)) {
        $url = config('woocommerce.url');
    }
    $url = preg_replace("/https?:\/\//i", '', $url);
    if (substr($url, -1) != '/') {
        $url .= '/';
    }
    return 'https://'.$url;
}

// NEW
public static function getSanitizedShopDomain($shop_domain = '')
{
    if (empty($shop_domain)) {
        $shop_domain = config('shopify.shop_domain');
    }

    // Remove protocol if present
    $shop_domain = preg_replace("/https?:\/\//i", '', $shop_domain);

    // Remove trailing slash if present
    $shop_domain = rtrim($shop_domain, '/');

    // Ensure it ends with .myshopify.com if it doesn't already
    if (!str_ends_with($shop_domain, '.myshopify.com')) {
        // Allow custom domains but validate format
    }

    return 'https://' . $shop_domain;
}
```

**Lines 295-359: apiGetOrders()** - See Phase 3 for complete rewrite

**Lines 361-384: errorCodeDescr()**
```php
// Update error messages to be Shopify-specific
case 401:
case 403:
    $descr = __('Authentication error. Check your Admin API access token and ensure it has the correct permissions.');
    break;
case 404:
    $descr = __('Shop not found. Verify your shop domain is correct (e.g., mystore.myshopify.com)');
    break;
case 429:
    $descr = __('Shopify API rate limit exceeded. Please try again in a moment.');
    break;
```

**Lines 179-238: Hook - conversation.after_prev_convs**
```php
// Update to pass $customer object to apiGetOrders
\Eventy::addAction('conversation.after_prev_convs', function($customer, $conversation, $mailbox) {
    // ... existing code ...

    // OLD
    $result = self::apiGetOrders($customer_email, $mailbox);

    // NEW
    $result = self::apiGetOrders($customer_email, $customer, $mailbox);

    // ... rest of code ...
```

#### 2. `Http/Controllers/ShopifyController.php`

**Lines 16-31: mailboxSettings()**
```php
// OLD
public function mailboxSettings($id)
{
    $mailbox = Mailbox::findOrFail($id);
    $settings = \WooCommerce::getMailboxWcSettings($mailbox);

    return view('woocommerce::mailbox_settings', [
        'settings' => [
            'woocommerce.url' => $settings['url'] ?? '',
            'woocommerce.key' => $settings['key'] ?? '',
            'woocommerce.secret' => $settings['secret'] ?? '',
            'woocommerce.version' => $settings['version'] ?? '',
        ],
        'mailbox' => $mailbox
    ]);
}

// NEW
public function mailboxSettings($id)
{
    $mailbox = Mailbox::findOrFail($id);
    $settings = \Shopify::getMailboxShopifySettings($mailbox);

    return view('shopify::mailbox_settings', [
        'settings' => [
            'shopify.shop_domain' => $settings['shop_domain'] ?? '',
            'shopify.access_token' => $settings['access_token'] ?? '',
            'shopify.api_version' => $settings['api_version'] ?? '',
        ],
        'mailbox' => $mailbox
    ]);
}
```

**Lines 33-70: mailboxSettingsSave()**
```php
// Update field name transformations
if (!empty($settings)) {
    foreach ($settings as $key => $value) {
        $settings[str_replace('shopify.', '', $key)] = $value;
        unset($settings[$key]);
    }
}

// Update URL validation to shop domain
if (!empty($settings['shop_domain'])) {
    $settings['shop_domain'] = preg_replace("/https?:\/\//i", '', $settings['shop_domain']);
    if (!\Helper::sanitizeRemoteUrl('https://'.$settings['shop_domain'])) {
        $settings['shop_domain'] = '';
    }
}

// Save to shopify column instead of wc
$mailbox->shopify = json_encode($settings);
$mailbox->save();

// Update credential check - need to pass customer object (use dummy)
if (!empty($settings['shop_domain']) && !empty($settings['access_token']) && !empty($settings['api_version'])) {
    // Create dummy customer object for testing
    $test_customer = new \stdClass();
    $test_customer->shopify_customer_id = null;

    $result = \Shopify::apiGetOrders('test@example.org', $test_customer, $mailbox);

    if (!empty($result['error'])) {
        \Session::flash('flash_error', __('Error occurred connecting to the API').': '.$result['error']);
    } else {
        \Session::flash('flash_success', __('Successfully connected to the API.'));
    }
}
```

**Lines 84-131: ajax() - orders action**
```php
// Update to pass customer object
case 'orders':
    $response['html'] = '';

    $mailbox = null;
    if ($request->mailbox_id) {
        $mailbox = Mailbox::find($request->mailbox_id);
    }

    $mailbox_api_enabled = \Shopify::isMailboxApiEnabled($mailbox);
    $orders = [];

    if (\Shopify::isApiEnabled() || $mailbox_api_enabled) {

        // Get customer object from first email
        $customer = \App\Customer::where('email', $request->customer_emails[0])->first();

        foreach ($request->customer_emails as $customer_email) {
            $result = \Shopify::apiGetOrders($customer_email, $customer, $mailbox);

            if (!empty($result['error'])) {
                \Log::error('[Shopify] '.$result['error']);
            } elseif (is_array($result['data']) && count($result['data'])) {
                $orders = $result['data'];

                // Cache orders for an hour
                $cache_key = 'shopify_orders_'.$customer_email;
                if ($mailbox_api_enabled) {
                    $cache_key = 'shopify_orders_'.$request->mailbox_id.'_'.$customer_email;
                }

                \Cache::put($cache_key, $orders, now()->addMinutes(60));
                break;
            }
        }
    }

    $url = '';
    if ($mailbox && \Shopify::isMailboxApiEnabled($mailbox)) {
        $settings  = \Shopify::getMailboxShopifySettings($mailbox);
        $url = \Shopify::getSanitizedShopDomain($settings['shop_domain'] ?? '');
    }

    $response['html'] = \View::make('shopify::partials/orders_list', [
        'orders'         => $orders,
        'load'           => false,
        'url'            => \Shopify::getSanitizedShopDomain($url),
    ])->render();

    $response['status'] = 'success';
    break;
```

#### 3. `Http/routes.php`

```php
// OLD
Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\WooCommerce\Http\Controllers'], function()
{
    Route::post('/woocommerce/ajax', ['uses' => 'WooCommerceController@ajax', 'laroute' => true])->name('woocommerce.ajax');
    Route::get('/mailbox/woocommerce/{id}', ['uses' => 'WooCommerceController@mailboxSettings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.woocommerce');
    Route::post('/mailbox/woocommerce/{id}', ['uses' => 'WooCommerceController@mailboxSettingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.woocommerce.save');
});

// NEW
Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Shopify\Http\Controllers'], function()
{
    Route::post('/shopify/ajax', ['uses' => 'ShopifyController@ajax', 'laroute' => true])->name('shopify.ajax');
    Route::get('/mailbox/shopify/{id}', ['uses' => 'ShopifyController@mailboxSettings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.shopify');
    Route::post('/mailbox/shopify/{id}', ['uses' => 'ShopifyController@mailboxSettingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.shopify.save');
});
```

#### 4. `Config/config.php`

```php
// OLD
return [
    'name' => 'WooCommerce',
    'url' => env('WOOCOMMERCE_URL', ''),
    'key' => env('WOOCOMMERCE_KEY', ''),
    'secret' => env('WOOCOMMERCE_SECRET', ''),
    'version' => env('WOOCOMMERCE_VERSION', '2'),
];

// NEW
return [
    'name' => 'Shopify',
    'shop_domain' => env('SHOPIFY_SHOP_DOMAIN', ''),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),
    'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
];
```

#### 5. `Public/js/module.js`

```javascript
// OLD
var wc_customer_emails = [];

function initWooCommerce(customer_emails, load)
{
    wc_customer_emails = customer_emails;

    if (!Array.isArray(wc_customer_emails)) {
        wc_customer_emails = [];
    }

    $(document).ready(function(){
        if (load) {
            wcLoadOrders();
        }
        $('.wc-refresh').click(function(e) {
            wcLoadOrders();
            e.preventDefault();
        });
    });
}

function wcLoadOrders()
{
    $('#wc-orders').addClass('wc-loading');

    fsAjax({
            action: 'orders',
            customer_emails: wc_customer_emails,
            mailbox_id: getGlobalAttr('mailbox_id')
        },
        laroute.route('woocommerce.ajax'),
        function(response) {
            if (typeof(response.status) != "undefined" && response.status == 'success'
                && typeof(response.html) != "undefined" && response.html
            ) {
                $('#wc-orders').html(response.html);
                $('#wc-orders').removeClass('wc-loading');

                $('.wc-refresh').click(function(e) {
                    wcLoadOrders();
                    e.preventDefault();
                });
            }
        }, true);
}

// NEW
var shopify_customer_emails = [];

function initShopify(customer_emails, load)
{
    shopify_customer_emails = customer_emails;

    if (!Array.isArray(shopify_customer_emails)) {
        shopify_customer_emails = [];
    }

    $(document).ready(function(){
        if (load) {
            shopifyLoadOrders();
        }
        $('.shopify-refresh').click(function(e) {
            shopifyLoadOrders();
            e.preventDefault();
        });
    });
}

function shopifyLoadOrders()
{
    $('#shopify-orders').addClass('shopify-loading');

    fsAjax({
            action: 'orders',
            customer_emails: shopify_customer_emails,
            mailbox_id: getGlobalAttr('mailbox_id')
        },
        laroute.route('shopify.ajax'),
        function(response) {
            if (typeof(response.status) != "undefined" && response.status == 'success'
                && typeof(response.html) != "undefined" && response.html
            ) {
                $('#shopify-orders').html(response.html);
                $('#shopify-orders').removeClass('shopify-loading');

                $('.shopify-refresh').click(function(e) {
                    shopifyLoadOrders();
                    e.preventDefault();
                });
            }
        }, true);
}
```

#### 6. All Blade Template Files

Update in all `.blade.php` files:
- Function calls: `\WooCommerce::` → `\Shopify::`
- CSS classes: `wc-` → `shopify-`
- Element IDs: `#wc-` → `#shopify-`
- View names: `woocommerce::` → `shopify::`
- Translation strings mentioning "WooCommerce"

---

## Phase 7: Migration Strategy

### New Migrations to Create

#### 1. Add Shopify Column to Mailboxes Table

**File**: `Database/Migrations/YYYY_MM_DD_HHMMSS_add_shopify_column_to_mailboxes_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShopifyColumnToMailboxesTable extends Migration
{
    public function up()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            // Meta data in JSON format for per-mailbox Shopify settings
            $table->text('shopify')->nullable();
        });
    }

    public function down()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('shopify');
        });
    }
}
```

#### 2. Add Shopify Customer ID to Customers Table

**File**: `Database/Migrations/YYYY_MM_DD_HHMMSS_add_shopify_customer_id_to_customers_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShopifyCustomerIdToCustomersTable extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            // Store Shopify customer ID to optimize API calls
            $table->string('shopify_customer_id')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('shopify_customer_id');
        });
    }
}
```

### Handling Existing WooCommerce Module

**Option A: Clean Install**
- Completely remove WooCommerce module
- Install Shopify module as new
- No data migration needed

**Option B: Keep Both Modules**
- Install Shopify as separate module
- Both can coexist independently
- Leave `mailboxes.wc` column intact

**Recommended**: Option A (clean install) for new deployments, Option B for existing systems

---

## Phase 8: Documentation Updates

### 1. module.json

```json
{
    "name": "Shopify Integration",
    "alias": "shopify",
    "description": "View customer Shopify order history in the Customer Profile pane!",
    "version": "1.0.0",
    "detailsUrl": "https://freescout.net/module/shopify/",
    "author": "FreeScout",
    "authorUrl": "https://freescout.net",
    "requiredAppVersion": "1.8.190",
    "license": "AGPL-3.0",
    "keywords": ["shopify", "ecommerce", "orders"],
    "active": 0,
    "order": 0,
    "providers": [
        "Modules\\Shopify\\Providers\\ShopifyServiceProvider"
    ],
    "aliases": {
        "Shopify": "Modules\\Shopify\\Providers\\ShopifyServiceProvider"
    },
    "files": [
        "start.php"
    ],
    "requires": []
}
```

### 2. Create CLAUDE.md

**File**: `CLAUDE.md`

Document the Shopify module architecture including:
- Module overview and purpose
- Two-tier configuration system (global + per-mailbox)
- **Customer ID caching optimization strategy**
- API integration flow (two-step with caching)
- Shopify authentication method (Admin API access token)
- Database schema (mailboxes.shopify + customers.shopify_customer_id)
- View integration points via Eventy hooks
- AJAX architecture
- Key files and their purposes
- Testing approach
- API rate limit considerations

### 3. Inline Code Comments

Add comprehensive comments to:
- `apiGetOrders()` method explaining two-step lookup and caching
- `makeShopifyApiCall()` helper method
- Customer ID caching logic
- Error handling for Shopify-specific errors
- Rate limiting considerations

---

## Phase 9: Testing Checklist

### Global Settings Tests

- [ ] Install Shopify module
- [ ] Configure global settings with valid Shopify credentials
  - [ ] Shop domain format validation (mystore.myshopify.com)
  - [ ] Access token format validation
  - [ ] API version format validation (YYYY-MM)
- [ ] Test API connection (should successfully connect)
- [ ] Test with invalid credentials (should show appropriate error)
- [ ] Test with different API versions
- [ ] Verify settings are saved to config

### Per-Mailbox Settings Tests

- [ ] Navigate to mailbox settings
- [ ] Configure mailbox-specific Shopify credentials
- [ ] Verify API connection test works at mailbox level
- [ ] Confirm per-mailbox settings override global settings
- [ ] Test with multiple mailboxes using different Shopify stores

### Customer ID Caching Tests

- [ ] First request for new customer (should make 2 API calls)
  - [ ] Verify customer lookup API call
  - [ ] Verify orders fetch API call
  - [ ] Confirm `shopify_customer_id` is saved to database
- [ ] Second request for same customer (should make 1 API call)
  - [ ] Verify only orders fetch API call is made
  - [ ] Confirm existing customer ID is used
- [ ] Test with customer not found in Shopify
  - [ ] Verify graceful handling (no customer ID saved)
  - [ ] Check error is logged appropriately

### Orders Display Tests

- [ ] View orders for customer with single email
  - [ ] Verify correct orders are displayed
  - [ ] Check order number, total, currency display correctly
  - [ ] Verify date formatting
  - [ ] Check status color coding
  - [ ] Test order link (should open correct Shopify admin page)
- [ ] View orders for customer with multiple emails
  - [ ] Verify all emails are checked
  - [ ] Confirm first email with orders is displayed
- [ ] Verify order caching works (60-minute cache)
  - [ ] First load should query API
  - [ ] Subsequent loads within 60min should use cache
- [ ] Test refresh functionality
  - [ ] Click refresh button
  - [ ] Verify new API call is made
  - [ ] Check orders are updated
- [ ] Verify "No orders found" displays correctly
- [ ] Test loading states display properly

### Edge Cases Tests

- [ ] Customer with no orders in Shopify
  - [ ] Should display "No orders found"
  - [ ] Should handle gracefully
- [ ] Customer email not found in Shopify
  - [ ] Should not crash
  - [ ] Should show "No orders found"
  - [ ] Should log appropriately
- [ ] Invalid shop domain format
  - [ ] Should show validation error
  - [ ] Should not save invalid domain
- [ ] Invalid access token
  - [ ] Should show authentication error
  - [ ] Should display clear error message
- [ ] Expired or revoked access token
  - [ ] Should handle 401 error gracefully
  - [ ] Should prompt to update credentials
- [ ] Network timeout
  - [ ] Should handle timeout gracefully
  - [ ] Should display user-friendly error
- [ ] API rate limiting (429 error)
  - [ ] Should handle rate limit error
  - [ ] Should display appropriate message
  - [ ] Should suggest retry later

### UI/UX Tests

- [ ] Collapsible panel works correctly
- [ ] Loading spinner displays during API calls
- [ ] Refresh button is clickable and works
- [ ] CSS styling matches existing FreeScout style
- [ ] Responsive design works on different screen sizes
- [ ] Links open in new tab correctly

### Security Tests

- [ ] Access token is not exposed in frontend
- [ ] Admin-only routes are protected
- [ ] Settings can only be changed by admins
- [ ] API credentials are stored securely
- [ ] SQL injection protection (via Eloquent ORM)

### Performance Tests

- [ ] Measure response time for first request (2 API calls)
- [ ] Measure response time for subsequent requests (1 API call)
- [ ] Verify caching reduces load time
- [ ] Test with high-volume shops (many orders)
- [ ] Check memory usage with large order datasets

### Migration Tests

- [ ] Run migrations successfully
  - [ ] `add_shopify_column_to_mailboxes_table`
  - [ ] `add_shopify_customer_id_to_customers_table`
- [ ] Verify database columns are created
- [ ] Confirm indexes are applied
- [ ] Test rollback functionality

### Multi-tenancy Tests (if applicable)

- [ ] Test with multiple FreeScout instances
- [ ] Verify data isolation between instances
- [ ] Confirm per-mailbox settings work across instances

---

## Implementation Timeline

### Day 1: Foundation (6-8 hours)
- Phase 1: Rename all files and directories
- Phase 2: Update configuration structure
- Phase 5: Global search/replace refactoring
- Create new migration files
- Initial testing of file structure

### Day 2: Core Logic (6-8 hours)
- Phase 3: Rewrite API integration with customer ID caching
- Phase 4: Update response data mapping
- Phase 6: Update ShopifyServiceProvider methods
- Phase 6: Update ShopifyController methods
- Test API calls in isolation

### Day 3: Frontend & Views (4-6 hours)
- Phase 6: Update all Blade templates
- Phase 6: Update JavaScript module
- Phase 6: Update CSS styling
- Update routes
- Test UI components

### Day 4: Documentation & Testing (6-8 hours)
- Phase 8: Create comprehensive CLAUDE.md
- Phase 8: Add inline code comments
- Phase 9: Run complete testing checklist
- Fix any bugs discovered
- Performance optimization

### Total Estimated Time: 22-30 hours

---

## Potential Challenges & Solutions

### Challenge 1: Two API Calls = Performance Impact
**Solution**: Implemented customer ID caching in FreeScout database
- First request: 2 API calls (lookup + fetch + store)
- All subsequent requests: 1 API call (direct fetch)
- 50% reduction in API calls after initial lookup

### Challenge 2: Shopify API Rate Limits
**Issue**: Shopify REST API has strict rate limits (2 calls/second for standard plans)

**Solutions**:
- Implement order caching (60 minutes) to minimize API calls
- Use customer ID caching to reduce API calls by 50%
- Add exponential backoff for 429 errors
- Consider implementing request queue for high-traffic scenarios

**Code Addition to `makeShopifyApiCall()`**:
```php
// Handle rate limiting
if ($status_code == 429) {
    $retry_after = curl_getinfo($ch, CURLINFO_RETRY_AFTER) ?: 2;
    sleep($retry_after);
    // Optionally: retry the request once
}
```

### Challenge 3: Multiple Customer Emails
**Issue**: Current code checks multiple emails per customer. With Shopify, this could mean many API calls.

**Solutions**:
- Cache negative results (customer email not found) to avoid repeated lookups
- Prioritize primary email address first
- Implement smart email selection logic
- Consider storing email→customer_id mapping in cache

**Optimization**:
```php
// Check cache for "email not found" before making API call
$cache_key = 'shopify_customer_notfound_' . $customer_email;
if (\Cache::has($cache_key)) {
    continue; // Skip this email, we know it's not in Shopify
}

// If customer not found, cache that fact for 24 hours
if (empty($shopify_customer_id)) {
    \Cache::put($cache_key, true, now()->addHours(24));
    continue;
}
```

### Challenge 4: REST API Deprecation
**Issue**: Shopify REST Admin API is legacy as of October 1, 2024. Starting April 1, 2025, all new public apps must use GraphQL.

**Current Solution**:
- REST API still works for custom apps (our use case)
- Document this limitation in CLAUDE.md

**Future Consideration**:
- Plan for eventual GraphQL migration
- GraphQL would allow fetching customer and orders in single query
- Document migration path in CLAUDE.md for future developers

### Challenge 5: Custom Shopify Domains
**Issue**: Some merchants use custom domains (not .myshopify.com)

**Solution**:
- Update `getSanitizedShopDomain()` to handle custom domains
- Validate URL format regardless of domain
- Test with both .myshopify.com and custom domains

### Challenge 6: Order Status Mapping
**Issue**: WooCommerce and Shopify have different status systems

**Solution**:
- Map Shopify `financial_status` to colors appropriately
- Consider also displaying `fulfillment_status` for completeness
- Document status mapping in code comments

**Optional Enhancement**:
```php
// Display both financial and fulfillment status
<small>{{ ucfirst($order['financial_status']) }}</small>
<small class="text-muted">/ {{ ucfirst($order['fulfillment_status'] ?? 'unfulfilled') }}</small>
```

### Challenge 7: Shopify Plus vs Standard
**Issue**: Different Shopify plans may have different API capabilities

**Solution**:
- Test with standard Shopify account
- Document any Plus-specific considerations
- Gracefully handle differences in API responses

---

## Post-Implementation Checklist

- [ ] All 18 files renamed and updated
- [ ] All namespaces changed correctly
- [ ] All route names updated
- [ ] All view references updated
- [ ] All CSS classes renamed
- [ ] All JavaScript functions renamed
- [ ] Database migrations created and tested
- [ ] Customer ID caching implemented
- [ ] API authentication updated to use access token
- [ ] Order data mapping completed
- [ ] Error handling updated for Shopify errors
- [ ] CLAUDE.md documentation created
- [ ] Inline comments added to complex logic
- [ ] All tests passing (see Phase 9)
- [ ] Performance benchmarks acceptable
- [ ] Security review completed
- [ ] Code review completed
- [ ] Ready for deployment

---

## Rollback Plan

If issues are encountered during implementation:

1. **Keep WooCommerce module intact** during initial development
2. **Develop Shopify module in parallel** as separate module
3. **Test thoroughly** before removing WooCommerce
4. **Backup database** before running migrations
5. **Document rollback steps**:
   - Drop `mailboxes.shopify` column
   - Drop `customers.shopify_customer_id` column
   - Remove Shopify module directory
   - Clear cache
   - Revert to WooCommerce module

---

## Future Enhancements

### Phase 10 (Post-Launch)
- [ ] GraphQL API migration for better performance
- [ ] Display fulfillment status in addition to financial status
- [ ] Add product details to order display
- [ ] Implement webhook support for real-time order updates
- [ ] Add customer lifetime value display
- [ ] Support for draft orders
- [ ] Order search functionality
- [ ] Export order data feature
- [ ] Multi-currency support improvements

---

## Questions to Resolve Before Implementation

1. Should we keep the WooCommerce module intact or remove it?
2. Do we need to support Shopify Plus specific features?
3. Should we implement GraphQL from the start or stick with REST?
4. Do we want to display fulfillment_status in addition to financial_status?
5. Should we cache negative results (customer not found)?
6. What should the cache duration be for customer IDs? (Currently: indefinite until customer record changes)
7. Should we support custom Shopify domains or only .myshopify.com?

---

## References

- [Shopify Admin REST API Documentation](https://shopify.dev/docs/api/admin-rest)
- [Shopify Authentication Guide](https://shopify.dev/docs/api/usage/authentication)
- [Shopify Orders API](https://shopify.dev/docs/api/admin-rest/2025-01/resources/order)
- [Shopify Customers API](https://shopify.dev/docs/api/admin-rest/2025-01/resources/customer)
- [Shopify API Rate Limits](https://shopify.dev/docs/api/usage/rate-limits)
- [Generate Shopify Access Tokens](https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/generate-app-access-tokens-admin)

---

**Document Version**: 1.0
**Last Updated**: 2025-10-23
**Status**: Ready for Implementation
