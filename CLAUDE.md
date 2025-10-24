# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Overview

This is a FreeScout module that integrates Shopify with the FreeScout helpdesk system. It displays customer Shopify order history in the Customer Profile pane within conversations, allowing support agents to view recent purchases while handling support tickets.

**Module Alias**: `shopify`
**Module Namespace**: `Modules\Shopify`

## Architecture

### Service Provider Pattern (Laravel Module System)

This module uses FreeScout's hook-based architecture via the `Eventy` system (similar to WordPress hooks). The main logic is in Providers/ShopifyServiceProvider.php, which:

- Registers filters and actions via `\Eventy::addFilter()` and `\Eventy::addAction()`
- Hooks into FreeScout's UI to inject Shopify-specific elements
- Provides static helper methods accessible via the `\Shopify` facade

### Configuration Levels

The module supports **two-tier configuration**:

1. **Global settings** (`/settings` → Shopify section)
   - Configured via environment variables: `SHOPIFY_SHOP_DOMAIN`, `SHOPIFY_ACCESS_TOKEN`, `SHOPIFY_API_VERSION`
   - Stored in config at `config('shopify.*')`
   - Used as fallback for all mailboxes

2. **Per-mailbox settings** (`/mailbox/shopify/{id}`)
   - Stored in `mailboxes.shopify` JSON column (added by migration)
   - Overrides global settings when configured
   - Checked via `ShopifyServiceProvider::isMailboxApiEnabled($mailbox)`

### API Integration Flow with Customer ID Caching (OPTIMIZATION)

The Shopify REST API integration uses an optimized two-step approach with customer ID caching:

1. **Authentication**: Admin API Access Token via `X-Shopify-Access-Token` header
2. **Customer Lookup**: `GET /admin/api/{version}/customers/search.json?query=email:{email}`
3. **Customer ID Caching**: Store `shopify_customer_id` in FreeScout's `customers` table
4. **Orders Fetch**: `GET /admin/api/{version}/customers/{customer_id}/orders.json?status=any&limit=5`
5. **Order Caching**: Order data cached for 60 minutes

**Performance Optimization:**
- **First request**: 2 API calls (customer search + orders fetch + store customer_id)
- **Subsequent requests**: 1 API call (direct orders fetch using cached customer_id)
- **Result**: 50% reduction in API calls after initial lookup

## Database Schema

### Migration 1: Mailbox Settings Column

Adds `mailboxes.shopify` TEXT column to store per-mailbox Shopify settings as JSON

### Migration 2: Customer ID Caching (OPTIMIZATION)

Adds `customers.shopify_customer_id` VARCHAR column with index to cache Shopify customer IDs

## Shopify API Details

### Authentication

**Method**: Admin API Access Token
**Header**: `X-Shopify-Access-Token: {token}`

### API Endpoints

**Customer Search:**
```
GET https://{shop_domain}/admin/api/{version}/customers/search.json?query=email:{email}
```

**Customer Orders:**
```
GET https://{shop_domain}/admin/api/{version}/customers/{customer_id}/orders.json?status=any&limit=5
```

### API Versioning

- Format: Date-based (e.g., `2025-01`)
- Release Schedule: Quarterly (January, April, July, October)
- Current Supported: `2025-01`, `2024-10`, `2024-07`, `2024-04`
- Support Window: Minimum 12 months per version

### API Status

**Important**: The REST Admin API is legacy as of October 1, 2024. However:
- Custom apps (our use case) can continue using REST API
- New public apps (starting April 1, 2025) must use GraphQL
- REST API remains fully functional for existing integrations

## Shopify Order Data Structure

| Field | Shopify Field Name |
|-------|-------------------|
| Order Number | `order_number` or `name` |
| Total | `total_price` |
| Currency | `currency` |
| Date Created | `created_at` |
| Status | `financial_status` |
| Admin URL | `/admin/orders/{id}` |

### Order Statuses

**Shopify Financial Statuses:**
- `pending`, `authorized`, `partially_paid`, `paid`
- `partially_refunded`, `refunded`, `voided`

**Status Color Coding:**
- Green (success): `paid`, `refunded`, `partially_refunded`
- Yellow (warning): All others

## Constants

- `SHOPIFY_MODULE` = `'shopify'` - Module identifier
- `MAX_ORDERS` = `5` - Maximum orders fetched per customer

## Important Methods

### ShopifyServiceProvider

- `isApiEnabled()` - Check if global Shopify API is configured
- `isMailboxApiEnabled($mailbox)` - Check if mailbox has Shopify configured
- `getMailboxShopifySettings($mailbox)` - Get per-mailbox settings from JSON
- `getSanitizedShopDomain($shop_domain)` - Sanitize and validate shop domain
- `apiGetOrders($customer_email, $customer, $mailbox)` - Main API method with customer ID caching
- `makeShopifyApiCall($url, $access_token)` - Helper for authenticated API calls
- `formatDate($date)` - Format date for display
- `errorCodeDescr($code)` - Get human-readable error descriptions

## Rate Limiting Considerations

Shopify REST API limits:
- **Standard plans**: 2 calls/second
- **Shopify Plus**: 4 calls/second

The module handles this via:
- Order caching (60 minutes)
- Customer ID caching (indefinite)
- Reduced API calls (1 instead of 2 after initial lookup)

## Troubleshooting

### "Shop not found" error
- Verify shop domain format: `mystore.myshopify.com` (no protocol, no path)
- Ensure shop is active and accessible

### "Authentication error"
- Check access token is correct (starts with `shpat_`)
- Verify custom app has required scopes: `read_customers`, `read_orders`
- Ensure app is installed on the shop

### "No orders found" but orders exist
- Check customer email matches exactly in Shopify
- Verify customer has orders with `status=any` (not just completed)

## References

- [Shopify Admin REST API Documentation](https://shopify.dev/docs/api/admin-rest)
- [Orders API Reference](https://shopify.dev/docs/api/admin-rest/2025-01/resources/order)
- [Customers API Reference](https://shopify.dev/docs/api/admin-rest/2025-01/resources/customer)
- [API Versioning](https://shopify.dev/docs/api/usage/versioning)
