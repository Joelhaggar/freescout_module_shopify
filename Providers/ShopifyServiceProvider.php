<?php

namespace Modules\Shopify\Providers;

use Carbon\Carbon;
use App\Mailbox;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

// Module alias.
define('SHOPIFY_MODULE', 'shopify');

class ShopifyServiceProvider extends ServiceProvider
{
    const MAX_ORDERS = 5;

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        \Log::info('[Shopify] ServiceProvider boot() method called');
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        \Log::info('[Shopify] Service Provider hooks() method called');

        // Add module's CSS file to the application layout.
        \Eventy::addFilter('stylesheets', function($styles) {
            $styles[] = \Module::getPublicPath(SHOPIFY_MODULE).'/css/module.css';
            return $styles;
        });

        // Add module's JS file to the application layout.
        \Eventy::addFilter('javascripts', function($javascripts) {
            $javascripts[] = \Module::getPublicPath(SHOPIFY_MODULE).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(SHOPIFY_MODULE).'/js/module.js';
            return $javascripts;
        });

        // Add item to the mailbox menu.
        \Eventy::addAction('mailboxes.settings.menu', function($mailbox) {
            if (auth()->user()->isAdmin()) {
                echo \View::make('shopify::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 34);

        // Section settings.
        \Eventy::addFilter('settings.sections', function($sections) {
            $sections[SHOPIFY_MODULE] = ['title' => 'Shopify', 'icon' => 'shopping-cart', 'order' => 550];

            return $sections;
        }, 35);

        // Section parameters.
        \Eventy::addFilter('settings.section_params', function($params, $section) {

            if ($section != SHOPIFY_MODULE) {
                return $params;
            }

            $params['settings'] = [
                'shopify.shop_domain' => [
                    'env' => 'SHOPIFY_SHOP_DOMAIN',
                ],
                'shopify.access_token' => [
                    'env' => 'SHOPIFY_ACCESS_TOKEN',
                ],
                'shopify.api_version' => [
                    'env' => 'SHOPIFY_API_VERSION',
                ],
            ];

            // Validation.
            // $params['validator_rules'] = [
            //     'settings.shopify\.shop_domain' => 'required',
            // ];

            return $params;
        }, 20, 2);

        // Settings view.
        \Eventy::addFilter('settings.view', function($view, $section) {
            if ($section != SHOPIFY_MODULE) {
                return $view;
            } else {
                return 'shopify::settings';
            }
        }, 20, 2);

        // Section settings.
        \Eventy::addFilter('settings.section_settings', function($settings, $section) {

            if ($section != SHOPIFY_MODULE) {
                return $settings;
            }

            $settings['shopify.shop_domain'] = config('shopify.shop_domain');
            $settings['shopify.access_token'] = config('shopify.access_token');
            $settings['shopify.api_version'] = config('shopify.api_version');

            $mailboxes_enabled = \Auth::user()->mailboxesCanView(true);
            foreach ($mailboxes_enabled as $i => $mailbox) {
                if (!self::isMailboxApiEnabled($mailbox)) {
                    $mailboxes_enabled->forget($i);
                }
            }

            $settings['mailboxes_enabled'] = $mailboxes_enabled;

            return $settings;
        }, 20, 2);

        // Before saving settings.
        \Eventy::addFilter('settings.before_save', function($request, $section, $settings) {

            if ($section != SHOPIFY_MODULE) {
                return $request;
            }

            if (!empty($request->settings['shopify.shop_domain'])) {
                $settings = $request->settings;

                $settings['shopify.shop_domain'] = preg_replace("/https?:\/\//i", '', $settings['shopify.shop_domain']);

                if (!\Helper::sanitizeRemoteUrl('https://'.$settings['shopify.shop_domain'])) {
                    $settings['shopify.shop_domain'] = '';
                }

                $request->merge([
                    'settings' => $settings,
                ]);
            }

            return $request;
        }, 20, 3);

        // After saving settings.
        \Eventy::addFilter('settings.after_save', function($response, $request, $section, $settings) {

            if ($section != SHOPIFY_MODULE) {
                return $response;
            }

            if (self::isApiEnabled()) {
                // Check API credentials - create dummy customer object for testing
                $test_customer = new \stdClass();
                $test_customer->shopify_customer_id = null;

                $result = self::apiGetOrders('test@example.org', $test_customer);

                if (!empty($result['error'])) {
                    $request->session()->flash('flash_error', __('Error occurred connecting to the API').': '.$result['error']);
                } else {
                    $request->session()->flash('flash_success', __('Successfully connected to the API.'));
                }
            }

            return $response;
        }, 20, 4);

        // Show recent orders.
        \Eventy::addAction('conversation.after_prev_convs', function($customer, $conversation, $mailbox) {

            \Log::info('[Shopify] Hook triggered - Customer: ' . ($customer ? $customer->id : 'null'));

            $load = false;
            $orders = [];

            if (!$customer) {
                \Log::info('[Shopify] Hook: No customer object');
                return;
            }

            // Check all customer emails.
            $customer_emails = $customer->emails_cached->pluck('email');

            if (!count($customer_emails)) {
                \Log::info('[Shopify] Hook: No customer emails found');
                return;
            }

            $global_enabled = \Shopify::isApiEnabled();
            $mailbox_enabled = \Shopify::isMailboxApiEnabled($mailbox);
            \Log::info('[Shopify] Hook: Global enabled: ' . ($global_enabled ? 'yes' : 'no') . ', Mailbox enabled: ' . ($mailbox_enabled ? 'yes' : 'no'));

            if (!$global_enabled && !$mailbox_enabled) {
                \Log::info('[Shopify] Hook: API not enabled, skipping widget');
                return;
            }

            // Initialize variables
            $orders = [];
            $load = true;
            $url = '';

            // Check cache for existing orders
            foreach ($customer_emails as $customer_email) {
                if (self::isMailboxApiEnabled($mailbox)) {
                    $settings = self::getMailboxShopifySettings($mailbox);
                    $url = $settings['shop_domain'] ?? '';
                    $cached_orders_json = \Cache::get('shopify_orders_'.$mailbox->id.'_'.$customer_email);
                } else {
                    $cached_orders_json = \Cache::get('shopify_orders_'.$customer_email);
                }

                if ($cached_orders_json && is_array($cached_orders_json)) {
                    $orders = $cached_orders_json;
                    $load = false;
                    break;
                }
            }

            // if (self::isApiEnabled()) {
            //     $result = self::apiGetOrders($customer_email);

            //     if (!empty($result['error'])) {
            //         \Log::error('[WooCommerce] '.$result['error']);
            //     } elseif (!empty($result['data'])) {
            //         $orders = $result['data'];

            //         // Cache orders for an hour.
            //         \Cache::put('wc_orders_'.$customer_email, $orders, now()->addMinutes(60));
            //     }
            // }

            echo \View::make('shopify::partials/orders', [
                'orders'         => $orders,
                'customer_emails' => $customer_emails,
                'load'           => $load,
                'url'            => \Shopify::getSanitizedShopDomain($url),
            ])->render();

        }, 12, 3);

        // Custom menu in conversation.
        \Eventy::addAction('conversation.customer.menu', function($customer, $conversation) {
            ?>
                <li role="presentation" class="col3-hidden"><a data-toggle="collapse" href=".shopify-collapse-orders" tabindex="-1" role="menuitem"><?php echo __("Recent Orders") ?></a></li>
            <?php
        }, 12, 2);

    }

    public static function isApiEnabled()
    {
        return (config('shopify.shop_domain') && config('shopify.access_token') && config('shopify.api_version'));
    }

    public static function isMailboxApiEnabled($mailbox)
    {
        if (empty($mailbox) || empty($mailbox->shopify)) {
            return false;
        }
        $settings = self::getMailboxShopifySettings($mailbox);

        return (!empty($settings['shop_domain']) && !empty($settings['access_token']) && !empty($settings['api_version']));
    }

    public static function getMailboxShopifySettings($mailbox)
    {
        return json_decode($mailbox->shopify ?: '', true);
    }

    public static function formatDate($date)
    {
        $date_carbon = Carbon::parse($date);

        if (!$date_carbon) {
            return '';
        }

        return $date_carbon->format('M j, Y');
    }

    public static function getSanitizedShopDomain($shop_domain = '')
    {
        if (empty($shop_domain)) {
            $shop_domain = config('shopify.shop_domain');
        }

        // Remove protocol if present
        $shop_domain = preg_replace("/https?:\/\//i", '', $shop_domain);

        // Remove trailing slash if present
        $shop_domain = rtrim($shop_domain, '/');

        return 'https://' . $shop_domain;
    }

    /**
     * Retrieve Shopify orders for customer.
     * Uses customer ID caching to optimize API calls.
     */
    public static function apiGetOrders($customer_email, $customer, $mailbox = null)
    {
        $response = [
            'error' => '',
            'data' => [],
        ];

        // Get credentials (global or per-mailbox)
        if ($mailbox && \Shopify::isMailboxApiEnabled($mailbox)) {
            $settings = self::getMailboxShopifySettings($mailbox);
            $shop_domain = $settings['shop_domain'];
            $access_token = $settings['access_token'];
            $api_version = $settings['api_version'];
            \Log::info('[Shopify] Using mailbox settings - Domain: ' . $shop_domain . ', Version: ' . $api_version . ', Token: ' . substr($access_token, 0, 10) . '...');
        } else {
            $shop_domain = config('shopify.shop_domain');
            $access_token = config('shopify.access_token');
            $api_version = config('shopify.api_version');
            \Log::info('[Shopify] Using global settings - Domain: ' . $shop_domain . ', Version: ' . $api_version . ', Token: ' . substr($access_token, 0, 10) . '...');
        }

        $shop_url = self::getSanitizedShopDomain($shop_domain);
        \Log::info('[Shopify] Shop URL: ' . $shop_url . ', Customer: ' . $customer_email);

        // OPTIMIZATION: Check if we already have Shopify customer ID cached
        $shopify_customer_id = $customer->shopify_customer_id ?? null;

        if (!$shopify_customer_id) {
            // Step 1: Lookup customer by email
            $customer_search_url = $shop_url . '/admin/api/' . $api_version . '/customers/search.json?query=email:' . urlencode($customer_email);

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

    /**
     * Make Shopify API call with authentication.
     */
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

            \Log::info('[Shopify] API Response - Status: ' . $status_code . ', URL: ' . $url);
            \Log::info('[Shopify] API Response Body: ' . substr($json, 0, 500));

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

    public static function errorCodeDescr($code)
    {
        switch ($code) {
            case 400:
                $descr = __('Bad request');
                break;
            case 401:
            case 403:
                $descr = __('Authentication error. Check your Admin API access token and ensure it has the correct permissions.');
                break;
            case 0:
            case 404:
                $descr = __('Shop not found. Verify your shop domain is correct (e.g., mystore.myshopify.com)');
                break;
            case 429:
                $descr = __('Shopify API rate limit exceeded. Please try again in a moment.');
                break;
            case 500:
                $descr = __('Internal shop error');
                break;
            default:
                $descr = __('Unknown error');
                break;
        }

        return $descr;
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('shopify.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'shopify'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/woocommerce');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ],'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/woocommerce';
        }, \Config::get('view.paths')), [$sourcePath]), 'shopify');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ .'/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories()
    {
        if (! app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
