<div class="conv-sidebar-block">
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default @if ($load) shopify-loading @endif" id="shopify-orders">
            @include('shopify::partials/orders_list')
        </div>
    </div>
</div>

@section('javascript')
    @parent
    initShopify({!! json_encode($customer_emails) !!}, {{ (int)$load }});
@endsection