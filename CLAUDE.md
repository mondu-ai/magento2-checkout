# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Mondu for Magento 2 (`Mondu\Mondu` namespace) - A B2B payment integration module enabling merchants to accept five payment methods from Mondu: Invoice, SEPA Direct Debit, Installments, Installments-by-Invoice, and Pay Now.

**Version:** 2.7.0 | **PHP:** >= 8.1 | **Magento:** 2.4.x

## Build & Development Commands

```bash
# Docker development environment (Magento + MariaDB + Elasticsearch)
docker-compose up           # Requires auth.json (see auth.json.example)

# Post-install / after code changes
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush

# Lint (PSR-12 + Magento2 standards, runs in CI on PRs to main)
magento/vendor/bin/phpcs --standard=PSR12,Magento2 --ignore=magento/* .

# Version release (updates composer.json + etc/module.xml)
./releaser.sh -v <new> -o <old> -c keep
```

No unit/integration tests exist in this codebase. CI only runs PHPCS.

## Architecture

### Order Lifecycle Flow

1. **Checkout** - Customer selects Mondu payment → `CreateOrder` observer sends order to Mondu API → redirects to Mondu checkout widget
2. **Webhook** - Mondu confirms/declines via `Controller/Webhooks/Index.php` (topics: `order/confirmed`, `order/pending`, `order/declined`) → updates Magento order state
3. **Shipment** - `ShipOrder` observer or cron bulk job calls Mondu `Ship` API to create invoices
4. **Credit Memo** - `UpdateOrder` observer calls `Memo` or `Adjust` API
5. **Cancellation** - `CancelOrder` observer calls `Cancel` API

### Request Handler Pattern (Central)

All Mondu API calls go through `Model/Request/Factory.php` which creates typed handlers via factory constants (e.g., `Factory::SHIP_ORDER`). Each handler extends `CommonRequest` (HTTP via Curl, error handling) and implements `RequestInterface`. The factory also attaches common headers, environment info, and an `ErrorEvents` handler to each request.

Key handlers: `Transactions`, `Confirm`, `Ship`, `Cancel`, `Memo`, `Webhooks`, `PaymentMethods`, `Adjust`/`Edit`, `ConfirmOrder`, `OrderInvoices`, `ErrorEvents`.

### Payment Methods

Five classes in `Model/Payment/` extend `AbstractMethod`, each with a unique `CODE` constant: `mondu`, `mondusepa`, `monduinstallment`, `monduinstallmentbyinvoice`, `mondupaynow`. All configured in `etc/config.xml`. The `Helpers/PaymentMethod.php` helper provides `PAYMENTS` array and `MAPPING` from API identifiers to codes.

### Multistore Support

`Helpers/ContextHelper` sets configuration scope per store. The webhook controller validates signatures per-store with a fallback that iterates all stores. `Factory::create()` accepts an optional `$storeId` parameter.

### Event Observers

All extend abstract `MonduObserver` (filters non-Mondu orders, sets context). Registered in `etc/events.xml` (frontend) and `etc/adminhtml/events.xml`:

| Event | Observer | Scope |
|-------|----------|-------|
| `sales_order_place_before` | `CreateOrder` | frontend |
| `sales_order_place_after` | `AfterPlaceOrder` | frontend |
| `order_cancel_after` | `CancelOrder` | frontend |
| `sales_order_shipment_save_after` | `ShipOrder` | adminhtml |
| `sales_order_creditmemo_save_after` | `UpdateOrder` | adminhtml |
| `admin_system_config_changed_section_payment` | `Config/Save` | adminhtml |

### Plugins

- `CsrfValidator` - Bypasses CSRF for `/mondu/webhooks/index` endpoint
- `PaymentHelper` - Filters available Mondu methods per store via `PaymentMethodList`
- `EmailTemplate` - Injects payment variables into order emails

### Cron

Single job in `Cron/Cron.php` runs every 30 minutes (`etc/crontab.xml`). Processes bulk shipments for orders updated in the last hour that have a `mondu_reference_id`. Controlled by `ConfigProvider::isCronEnabled()`.

### Frontend

Checkout widget uses Knockout.js (`view/frontend/web/js/view/payment/`). The SDK widget is loaded from `checkout.mondu.ai/widget.js` (or `checkout.demo.mondu.ai` for sandbox).

### Admin Panel

Transaction log grid at `Adminhtml/Log/`, bulk actions (ship/sync) at `Adminhtml/Bulk/`. UI components defined in `view/adminhtml/ui_component/`.

## Key Files

| File | Purpose |
|------|---------|
| `Model/Request/Factory.php` | Central API request factory |
| `Model/Ui/ConfigProvider.php` | All module config (API keys, URLs, modes, SDK) |
| `Helpers/OrderHelper.php` | Line items, tax calc, quote→API formatting |
| `Helpers/PaymentMethod.php` | Payment method codes, mapping, filtering |
| `Helpers/BulkActions.php` | Batch ship/sync operations |
| `Controller/Webhooks/Index.php` | Webhook receiver with signature validation |
| `etc/db_schema.xml` | Schema for `mondu_transactions`, `mondu_transaction_items` |
| `etc/adminhtml/system.xml` | Admin settings UI structure |

## Database

- **`mondu_transactions`** - Order references (reference_id, mondu_state, payment_method, invoice_iban, is_confirmed, etc.)
- **`mondu_transaction_items`** - Maps products/order items to transactions
- **`sales_order.mondu_reference_id`** - Extended column on core order table

## Logging

Debug logs write to `var/log/mondu.log` via `Helpers/Logger/Logger`. Enable debug mode in admin config.

## API Endpoints

- **Production:** `https://api.mondu.ai/api/v1`
- **Sandbox:** `https://api.demo.mondu.ai/api/v1`