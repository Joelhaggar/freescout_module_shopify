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
                        <li>
                            <div>
                                <a href="{{ $url }}/admin/orders/{{ $order['id'] }}" target="_blank">#{{ $order['order_number'] ?? $order['name'] }}</a>
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
			@else
			    <div class="text-help margin-top-10 shopify-no-orders">{{ __("No orders found") }}</div>
			@endif
        @endif
   
        <div class="margin-top-10 shopify-refresh small">
            <a href="#" class="sidebar-block-link"><i class="glyphicon glyphicon-refresh"></i> {{ __("Refresh") }}</a>
        </div>
	   
    </div>
</div>
