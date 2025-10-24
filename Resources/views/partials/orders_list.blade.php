<div class="panel-heading">
    <h4 class="panel-title">
        <a data-toggle="collapse" href=".shopify-collapse-orders">
            {{ __("Recent Orders") }}
            <b class="caret"></b>
        </a>
    </h4>
</div>
<div class="shopify-collapse-orders panel-collapse collapse in">
    <div class="panel-body">
        <div class="sidebar-block-header2"><strong>{{ __("Recent Orders") }}</strong> (<a data-toggle="collapse" href=".shopify-collapse-orders">{{ __('close') }}</a>)</div>
       	<div id="shopify-loader">
        	<img src="{{ asset('img/loader-tiny.gif') }}" />
        </div>
        	
        @if (!$load)
            @if (count($orders))
			    <ul class="sidebar-block-list shopify-orders-list">
                    @foreach($orders as $order)
                        <li class="shopify-order-item" data-order-index="{{ $loop->index }}" style="cursor: pointer;">
                            <div>
                                <a href="javascript:void(0)" class="shopify-order-link">#{{ $order['order_number'] ?? $order['name'] }}</a>
                                <span class="pull-right">{{ $order['currency'] }} {{ $order['total_price'] }}</span>
                            </div>
                            <div>
                                <small class="text-help">{{ \Shopify::formatDate($order['created_at']) }}</small>
                                <small class="pull-right @if (in_array($order['financial_status'] ?? '', ['paid', 'refunded', 'partially_refunded'])) text-success @else text-warning @endif ">
                                    {{ __(ucfirst(str_replace('_', ' ', $order['financial_status'] ?? 'pending'))) }}
                                </small>
                            </div>
                        </li>
                    @endforeach
                </ul>

                {{-- Store orders data for JavaScript --}}
                <script type="application/json" id="shopify-orders-data">
                    {!! json_encode($orders) !!}
                </script>
			@else
			    <div class="text-help margin-top-10 shopify-no-orders">{{ __("No orders found") }}</div>
			@endif
        @endif
   
        <div class="margin-top-10 shopify-refresh small">
            <a href="#" class="sidebar-block-link"><i class="glyphicon glyphicon-refresh"></i> {{ __("Refresh") }}</a>
        </div>

    </div>
</div>

{{-- Slide-out panel for order details --}}
<div id="shopify-order-panel" class="shopify-order-panel">
    <div class="shopify-panel-overlay"></div>
    <div class="shopify-panel-content">
        <div class="shopify-panel-header">
            <button class="shopify-panel-close" aria-label="Close">&times;</button>
            <div class="shopify-panel-logo">
                <svg width="20" height="20" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M25.9 8.4c0-.1 0-.1-.1-.2 0 0 0-.1-.1-.1L20.5.7c-.1-.1-.2-.2-.3-.2h-.2L19.8.4c-.1 0-.2.1-.3.1L15.2.2c0 0 0 0-.1 0L12.8 0c-.1 0-.3.1-.4.2L7.6 8.1c0 .1-.1.1-.1.2 0 0 0 .1-.1.1v.2c0 .1 0 .2.1.3l7.7 22.5c0 .1.1.2.2.2.1 0 .2.1.3.1h.2c.1 0 .2 0 .3-.1.1 0 .2-.1.2-.2L24 8.9c0-.1 0-.2.1-.3v-.2c-.1 0-.1 0-.2 0zM19.9 2.2l3.7 6.1h-3.7V2.2zm-7.8 0v6.1h-3.7l3.7-6.1zm3.9 0v6.1h-3.7l1.8-6.1h1.9zm4.1 0v6.1h-3.7l1.8-6.1h1.9zM16 10.5l-6.8 19.8-6.4-19.8h13.2zm.2 0h13.2l-6.4 19.8L16.2 10.5z" fill="#95BF47"/>
                </svg>
                <span id="shopify-panel-title">Order #<span class="order-number"></span></span>
            </div>
        </div>
        <div class="shopify-panel-body" id="shopify-panel-body">
            {{-- Order details will be injected here via JavaScript --}}
        </div>
    </div>
</div>
