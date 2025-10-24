<?php

namespace Modules\Shopify\Http\Controllers;

use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ShopifyController extends Controller
{
    /**
     * Mailbox Shopify settings page.
     * @return Response
     */
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

    public function mailboxSettingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);

        $settings = $request->settings ?: [];

        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                $settings[str_replace('shopify.', '', $key)] = $value;
                unset($settings[$key]);
            }
        }

        if (!empty($settings['shop_domain'])) {
            $settings['shop_domain'] = preg_replace("/https?:\/\//i", '', $settings['shop_domain']);
            if (!\Helper::sanitizeRemoteUrl('https://'.$settings['shop_domain'])) {
                $settings['shop_domain'] = '';
            }
        }

        $mailbox->shopify = json_encode($settings);
        $mailbox->save();

        if (!empty($settings['shop_domain']) && !empty($settings['access_token']) && !empty($settings['api_version'])) {
            // Check API credentials - create dummy customer object for testing
            $test_customer = new \stdClass();
            $test_customer->shopify_customer_id = null;

            $result = \Shopify::apiGetOrders('test@example.org', $test_customer, $mailbox);

            if (!empty($result['error'])) {
                \Session::flash('flash_error', __('Error occurred connecting to the API').': '.$result['error']);
            } else {
                \Session::flash('flash_success', __('Successfully connected to the API.'));
            }
        } else {
            \Session::flash('flash_success_floating', __('Settings updated'));
        }

        return redirect()->route('mailboxes.shopify', ['id' => $id]);
    }

    /**
     * Ajax controller.
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        switch ($request->action) {

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
                    $customer = null;
                    if (!empty($request->customer_emails[0])) {
                        $email_obj = \App\Email::where('email', $request->customer_emails[0])->first();
                        if ($email_obj) {
                            $customer = $email_obj->customer;
                        }
                    }

                    foreach ($request->customer_emails as $customer_email) {
                        // If we don't have customer object, try to find by current email
                        if (!$customer) {
                            $email_obj = \App\Email::where('email', $customer_email)->first();
                            if ($email_obj) {
                                $customer = $email_obj->customer;
                            }
                        }

                        // If still no customer, create a temporary one for the API call
                        if (!$customer) {
                            $customer = new \App\Customer();
                            $customer->shopify_customer_id = null;
                        }

                        $result = \Shopify::apiGetOrders($customer_email, $customer, $mailbox);

                        if (!empty($result['error'])) {
                            \Log::error('[Shopify] API Error: '.$result['error']);
                            $response['msg'] = $result['error'];
                        } elseif (is_array($result['data']) && count($result['data'])) {
                            $orders = $result['data'];

                            // Cache orders for an hour.
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
                } else {
                    $url = \Shopify::getSanitizedShopDomain();
                }

                $response['html'] = \View::make('shopify::partials/orders_list', [
                    'orders'         => $orders,
                    'load'           => false,
                    'url'            => $url,
                ])->render();

                $response['status'] = 'success';
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = 'Unknown error occured';
        }

        return \Response::json($response);
    }
}
