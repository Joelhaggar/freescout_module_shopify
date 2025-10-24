/**
 * Module's JavaScript.
 */

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
			} else {
				//showAjaxError(response);
			}
		}, true
	);
}