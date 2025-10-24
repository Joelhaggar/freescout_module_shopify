<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Shopify\Http\Controllers'], function()
{
    Route::post('/shopify/ajax', ['uses' => 'ShopifyController@ajax', 'laroute' => true])->name('shopify.ajax');

    Route::get('/mailbox/shopify/{id}', ['uses' => 'ShopifyController@mailboxSettings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.shopify');
    Route::post('/mailbox/shopify/{id}', ['uses' => 'ShopifyController@mailboxSettingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.shopify.save');
});
