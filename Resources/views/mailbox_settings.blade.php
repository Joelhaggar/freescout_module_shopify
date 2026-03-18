@extends('layouts.app')

@section('title_full', 'Shopify'.' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('Shopify') }}
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">
                <form class="form-horizontal margin-top margin-bottom" method="POST" action="{{ route('mailboxes.shopify.save', ['id' => $mailbox->id]) }}">
                    {{ csrf_field() }}

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Shop Domain') }}</label>
                        <div class="col-sm-6">
                            <div class="input-group input-sized-lg">
                                <span class="input-group-addon input-group-addon-grey">https://</span>
                                <input type="text" class="form-control input-sized-lg" name="settings[shopify.shop_domain]" value="{{ $settings['shopify.shop_domain'] ?? '' }}" placeholder="mystore.myshopify.com">
                            </div>
                            <p class="form-help">{{ __('Example') }}: mystore.myshopify.com</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Client ID') }}</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control input-sized-lg" name="settings[shopify.client_id]" value="{{ $settings['shopify.client_id'] ?? '' }}" placeholder="ex: 7a0f01aa3de1980fe8ed495d4c6e5feb">
                            <p class="form-help">{{ __('Votre Client ID depuis le Dev Dashboard Shopify → Paramètres → Identifiants') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('Client Secret') }}</label>
                        <div class="col-sm-6">
                            <input type="password" class="form-control input-sized-lg" name="settings[shopify.client_secret]" value="{{ $settings['shopify.client_secret'] ?? '' }}" placeholder="votre secret">
                            <p class="form-help">{{ __('Votre Client Secret depuis le Dev Dashboard Shopify → Paramètres → Identifiants') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{ __('API Version') }}</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control input-sized-lg" name="settings[shopify.api_version]" value="{{ $settings['shopify.api_version'] ?? '2026-01' }}" placeholder="2026-01">
                            <p class="form-help">
                                {!! __('Shopify API version (e.g., 2025-01). Find current versions :%a_begin%here:%a_end%.', ['%a_begin%' => '<a href="https://shopify.dev/docs/api/usage/versioning" target="_blank">', '%a_end%' => '</a>']) !!}
                            </p>
                        </div>
                    </div>

                    <div class="form-group margin-top margin-bottom">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
