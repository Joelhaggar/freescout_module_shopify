<form class="form-horizontal margin-top margin-bottom" method="POST" action="">
    {{ csrf_field() }}

    @if (isset($settings['mailboxes_enabled']) && count($settings['mailboxes_enabled']))
        <div class="alert alert-warning">
            {{ __('The following mailboxes have Shopify connection configured:') }}
            <ul>
                @foreach($settings['mailboxes_enabled'] as $mailbox)
                    <li><a href="{{ route('mailboxes.shopify', ['id' => $mailbox->id]) }}">{{ $mailbox->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="form-group{{ $errors->has('settings.shopify->shop_domain') ? ' has-error' : '' }}">
        <label class="col-sm-2 control-label">{{ __('Shop Domain') }}</label>

        <div class="col-sm-6">
            <div class="input-group input-sized-lg">
                <span class="input-group-addon input-group-addon-grey">https://</span>
                <input type="text" class="form-control input-sized-lg" name="settings[shopify.shop_domain]" value="{{ old('settings') ? old('settings')['shopify.shop_domain'] : $settings['shopify.shop_domain'] }}" placeholder="mystore.myshopify.com">
            </div>

            @include('partials/field_error', ['field'=>'settings.shopify->shop_domain'])

            <p class="form-help">
                {{ __('Example') }}: mystore.myshopify.com
            </p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Admin API Access Token') }}</label>

        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" name="settings[shopify.access_token]" value="{{ $settings['shopify.access_token'] }}" placeholder="shpat_...">

            <p class="form-help">
                {{ __('Generate an Admin API access token from your Shopify admin under "Settings » Apps and sales channels » Develop apps"') }} (<a href="https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/generate-app-access-tokens-admin" target="_blank">{{ __('Instructions') }}</a>)
            </p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('API Version') }}</label>

        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" name="settings[shopify.api_version]" value="{{ $settings['shopify.api_version'] }}" placeholder="2025-01">

            <p class="form-help">
                {!! __('Shopify API version (e.g., 2025-01). Find current versions :%a_begin%here:%a_end%.', ['%a_begin%' => '<a href="https://shopify.dev/docs/api/usage/versioning" target="_blank">', '%a_end%' => '</a>']) !!}
            </p>
        </div>
    </div>

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>