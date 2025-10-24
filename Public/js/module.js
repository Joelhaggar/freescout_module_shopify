/**
 * Module's JavaScript.
 */

var shopify_customer_emails = [];
var shopify_orders_data = [];

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

		// Panel event handlers
		shopifyInitPanelHandlers();
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

				// Load orders data from embedded JSON
				shopifyLoadOrdersData();

				$('.shopify-refresh').click(function(e) {
					shopifyLoadOrders();
					e.preventDefault();
				});

				// Re-init panel handlers for newly loaded content
				shopifyInitPanelHandlers();
			} else {
				//showAjaxError(response);
			}
		}, true
	);
}

function shopifyLoadOrdersData()
{
	var dataElement = document.getElementById('shopify-orders-data');
	if (dataElement) {
		try {
			shopify_orders_data = JSON.parse(dataElement.textContent);
		} catch(e) {
			console.error('Failed to parse Shopify orders data:', e);
			shopify_orders_data = [];
		}
	}
}

function shopifyInitPanelHandlers()
{
	// Click handler for order items
	$(document).off('click', '.shopify-order-item').on('click', '.shopify-order-item', function(e) {
		e.preventDefault();
		var orderIndex = $(this).data('order-index');
		if (typeof orderIndex !== 'undefined' && shopify_orders_data[orderIndex]) {
			shopifyShowOrderPanel(shopify_orders_data[orderIndex]);
		}
	});

	// Close panel on overlay click
	$(document).off('click', '.shopify-panel-overlay').on('click', '.shopify-panel-overlay', function() {
		shopifyCloseOrderPanel();
	});

	// Close panel on close button click
	$(document).off('click', '.shopify-panel-close').on('click', '.shopify-panel-close', function() {
		shopifyCloseOrderPanel();
	});

	// Close on ESC key
	$(document).off('keyup.shopify').on('keyup.shopify', function(e) {
		if (e.key === 'Escape' && $('#shopify-order-panel').hasClass('active')) {
			shopifyCloseOrderPanel();
		}
	});
}

function shopifyShowOrderPanel(order)
{
	// Update order number in header
	$('#shopify-panel-title .order-number').text(order.order_number || order.name);

	// Build and inject order details HTML
	var html = shopifyBuildOrderDetailsHTML(order);
	$('#shopify-panel-body').html(html);

	// Show panel
	$('#shopify-order-panel').addClass('active');
	$('body').css('overflow', 'hidden');
}

function shopifyCloseOrderPanel()
{
	$('#shopify-order-panel').removeClass('active');
	$('body').css('overflow', '');
}

function shopifyBuildOrderDetailsHTML(order)
{
	var html = '';
	var shopUrl = window.location.origin; // Will be replaced with actual shop URL

	// Extract shop URL from order link if available
	if (order.order_status_url) {
		var matches = order.order_status_url.match(/https?:\/\/[^\/]+/);
		if (matches) {
			shopUrl = matches[0];
		}
	}

	// Summary section
	html += '<div class="shopify-detail-section">';
	html += '<div class="shopify-detail-row">';
	html += '<div class="shopify-detail-label">Summary</div>';
	html += '<div class="shopify-detail-value">';
	html += shopifyGetFulfillmentBadge(order.fulfillment_status);
	html += '</div>';
	html += '</div>';
	html += '<div class="shopify-detail-row">';
	html += '<div class="shopify-detail-label" style="width:100%;">';
	html += '<a href="' + shopUrl + '/admin/orders/' + order.id + '" target="_blank" class="shopify-panel-link">View on Shopify →</a>';
	html += '</div>';
	html += '</div>';
	html += '</div>';

	// Order details
	html += '<div class="shopify-detail-section">';
	html += '<div class="shopify-detail-section-title">Order Details</div>';
	html += '<div class="shopify-detail-row">';
	html += '<div class="shopify-detail-label">Order Placed</div>';
	html += '<div class="shopify-detail-value">' + shopifyFormatDate(order.created_at) + '</div>';
	html += '</div>';
	html += '<div class="shopify-detail-row">';
	html += '<div class="shopify-detail-label">Payment Status</div>';
	html += '<div class="shopify-detail-value">' + shopifyGetPaymentBadge(order.financial_status) + '</div>';
	html += '</div>';
	html += '</div>';

	// Shipping address
	if (order.shipping_address) {
		html += '<div class="shopify-detail-section">';
		html += '<div class="shopify-detail-section-title">Shipping Address</div>';
		html += '<div class="shopify-address-block">';
		html += shopifyFormatAddress(order.shipping_address);
		html += '</div>';
		html += '</div>';
	}

	// Tracking information
	if (order.fulfillments && order.fulfillments.length > 0) {
		html += '<div class="shopify-detail-section">';
		html += '<div class="shopify-detail-section-title">Tracking</div>';
		for (var i = 0; i < order.fulfillments.length; i++) {
			var fulfillment = order.fulfillments[i];
			html += '<div class="shopify-detail-row">';
			html += '<div class="shopify-detail-label">' + (fulfillment.tracking_company || 'Shipment') + '</div>';
			html += '<div class="shopify-detail-value">';
			html += shopifyGetFulfillmentBadge(fulfillment.shipment_status || 'pending');
			html += '</div>';
			html += '</div>';
			if (fulfillment.tracking_number) {
				html += '<div class="shopify-tracking">';
				if (fulfillment.tracking_url) {
					html += '<a href="' + fulfillment.tracking_url + '" target="_blank" class="shopify-tracking-number">#' + fulfillment.tracking_number + '</a>';
				} else {
					html += '<span class="shopify-tracking-number">#' + fulfillment.tracking_number + '</span>';
				}
				html += '</div>';
			}
		}
		html += '</div>';
	}

	// Line items
	if (order.line_items && order.line_items.length > 0) {
		html += '<div class="shopify-detail-section">';
		html += '<div class="shopify-detail-section-title">Items (' + order.line_items.length + ')</div>';
		for (var j = 0; j < order.line_items.length; j++) {
			var item = order.line_items[j];
			html += '<div class="shopify-line-item">';

			// Product icon/image placeholder
			html += '<div class="shopify-line-item-image">';
			html += '📦'; // Box emoji as placeholder
			html += '</div>';

			// Product details
			html += '<div class="shopify-line-item-details">';
			html += '<div class="shopify-line-item-name">' + shopifyEscapeHtml(item.name || item.title) + '</div>';
			if (item.variant_title) {
				html += '<div class="shopify-line-item-variant">' + shopifyEscapeHtml(item.variant_title) + '</div>';
			}
			if (item.sku) {
				html += '<div class="shopify-line-item-sku">SKU: ' + shopifyEscapeHtml(item.sku) + '</div>';
			}
			if (item.fulfillment_status) {
				html += '<div style="margin-top:4px;">' + shopifyGetFulfillmentBadge(item.fulfillment_status) + '</div>';
			}
			html += '</div>';

			// Price
			html += '<div class="shopify-line-item-price">';
			html += '<div class="shopify-line-item-amount">' + (order.currency || 'USD') + ' ' + item.price + '</div>';
			html += '<div class="shopify-line-item-quantity">× ' + item.quantity + '</div>';
			html += '</div>';

			html += '</div>';
		}
		html += '</div>';
	}

	// Receipt
	html += '<div class="shopify-detail-section">';
	html += '<div class="shopify-detail-section-title">Receipt</div>';
	html += '<div class="shopify-receipt-totals">';

	// Subtotal
	html += '<div class="shopify-receipt-row">';
	html += '<div class="shopify-receipt-label">Subtotal';
	if (order.line_items) {
		html += ' (' + order.line_items.length + ' item' + (order.line_items.length !== 1 ? 's' : '') + ')';
	}
	html += '</div>';
	html += '<div class="shopify-receipt-value">' + (order.currency || 'USD') + ' ' + (order.subtotal_price || order.total_line_items_price || '0.00') + '</div>';
	html += '</div>';

	// Discount
	if (order.total_discounts && parseFloat(order.total_discounts) > 0) {
		html += '<div class="shopify-receipt-row">';
		html += '<div class="shopify-receipt-label">Discount</div>';
		html += '<div class="shopify-receipt-value">-' + (order.currency || 'USD') + ' ' + order.total_discounts + '</div>';
		html += '</div>';
	}

	// Shipping
	html += '<div class="shopify-receipt-row">';
	html += '<div class="shopify-receipt-label">Shipping</div>';
	var shippingPrice = '0.00';
	if (order.total_shipping_price_set && order.total_shipping_price_set.shop_money) {
		shippingPrice = order.total_shipping_price_set.shop_money.amount;
	}
	html += '<div class="shopify-receipt-value">' + (order.currency || 'USD') + ' ' + shippingPrice + '</div>';
	html += '</div>';

	// Tax
	if (order.total_tax && parseFloat(order.total_tax) > 0) {
		html += '<div class="shopify-receipt-row">';
		html += '<div class="shopify-receipt-label">Tax</div>';
		html += '<div class="shopify-receipt-value">' + (order.currency || 'USD') + ' ' + order.total_tax + '</div>';
		html += '</div>';
	}

	// Total
	html += '<div class="shopify-receipt-row total">';
	html += '<div class="shopify-receipt-label">Total</div>';
	html += '<div class="shopify-receipt-value">' + (order.currency || 'USD') + ' ' + order.total_price + '</div>';
	html += '</div>';

	// Paid by customer
	html += '<div class="shopify-receipt-row" style="margin-top:12px;">';
	html += '<div class="shopify-receipt-label">Paid by customer</div>';
	html += '<div class="shopify-receipt-value">' + (order.currency || 'USD') + ' ' + (order.total_price || '0.00') + '</div>';
	html += '</div>';

	html += '</div>';
	html += '</div>';

	return html;
}

function shopifyGetFulfillmentBadge(status)
{
	if (!status) return '<span class="shopify-status-badge shopify-status-unfulfilled">Unfulfilled</span>';

	var statusLower = status.toLowerCase().replace(/_/g, ' ');
	var statusClass = 'shopify-status-unfulfilled';

	if (status === 'fulfilled' || status === 'success' || status === 'delivered') {
		statusClass = 'shopify-status-fulfilled';
	} else if (status === 'partial' || status === 'partially_fulfilled' || status === 'label_printed' || status === 'in_transit') {
		statusClass = 'shopify-status-partial';
	}

	return '<span class="shopify-status-badge ' + statusClass + '">' + shopifyCapitalize(statusLower) + '</span>';
}

function shopifyGetPaymentBadge(status)
{
	if (!status) return '<span class="shopify-status-badge shopify-status-pending">Pending</span>';

	var statusLower = status.toLowerCase().replace(/_/g, ' ');
	var statusClass = 'shopify-status-pending';

	if (status === 'paid' || status === 'refunded' || status === 'partially_refunded') {
		statusClass = 'shopify-status-paid';
	}

	return '<span class="shopify-status-badge ' + statusClass + '">' + shopifyCapitalize(statusLower) + '</span>';
}

function shopifyFormatAddress(address)
{
	var parts = [];

	if (address.name) parts.push(shopifyEscapeHtml(address.name));
	else if (address.first_name || address.last_name) {
		parts.push(shopifyEscapeHtml((address.first_name || '') + ' ' + (address.last_name || '')).trim());
	}

	if (address.address1) parts.push(shopifyEscapeHtml(address.address1));
	if (address.address2) parts.push(shopifyEscapeHtml(address.address2));

	var cityLine = [];
	if (address.city) cityLine.push(shopifyEscapeHtml(address.city));
	if (address.province_code || address.province) cityLine.push(shopifyEscapeHtml(address.province_code || address.province));
	if (address.zip) cityLine.push(shopifyEscapeHtml(address.zip));
	if (cityLine.length > 0) parts.push(cityLine.join(' '));

	if (address.country) parts.push(shopifyEscapeHtml(address.country));

	return parts.join('<br>');
}

function shopifyFormatDate(dateString)
{
	if (!dateString) return '';
	var date = new Date(dateString);
	return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function shopifyCapitalize(str)
{
	return str.replace(/\b\w/g, function(char) {
		return char.toUpperCase();
	});
}

function shopifyEscapeHtml(text)
{
	if (!text) return '';
	var map = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#039;'
	};
	return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}
