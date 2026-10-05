# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Mondu for Magento 2 - A B2B payment integration module enabling merchants to accept multiple payment methods from Mondu (Invoice, SEPA Direct Debit, Installments, Installments-by-Invoice, Pay Now).

**Version:** 2.7.0
**PHP Requirement:** >= 8.1
**Magento:** 2.4.x

## Build & Development Commands

### Installation
```bash
# Via Composer (recommended)
composer require mondu/magento2-payment

# Post-installation
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy  # production only
php bin/magento cache:flush
```

### Docker Development
```bash
docker-compose up     # Starts Magento + MariaDB + Elasticsearch
```
Requires `auth.json` with repo.magento.com credentials (see `auth.json.example`).

### Code Quality
```bash
# PHPCS with PSR-12 + Magento2 standards (runs in CI on PRs)
magento/vendor/bin/phpcs --standard=PSR12,Magento2 --ignore=magento/* .
```

### Version Release
```bash
./releaser.sh -v 2.8.0 -o 2.7.0 -c keep
# -v: new version, -o: old version, -c: "keep" to commit/push
```

## Architecture

### Request Handler Pattern
All API interactions go through `Model/Request/Factory.php` which creates specific handlers:
- `Transactions` - Create orders
- `Confirm` - Get order status
- `Ship` - Create invoices
- `Cancel` - Cancel orders
- `Memo` - Create credit notes
- `Webhooks` - Register/manage webhooks
- `PaymentMethods` - Fetch available methods
- `Adjust` / `Edit` - Modify orders

All handlers extend `CommonRequest` and implement `RequestInterface`.

### Payment Methods
Five payment method classes in `Model/Payment/`:
- `Mondu` (Invoice/Bank Transfer)
- `MonduSepa` (SEPA Direct Debit)
- `MonduInstallment` (Installments)
- `MonduInstallmentByInvoice`
- `MonduPayNow`

All extend `Magento\Payment\Model\Method\AbstractMethod`.

### Event Observers
Located in `Observer/`, triggered by Magento events:
- `CreateOrder` - Pre-order creation (`sales_order_place_before`)
- `AfterPlaceOrder` - Post-order actions (`sales_order_place_after`)
- `ShipOrder` - Shipment handling
- `CancelOrder` - Cancellation logic (`order_cancel_after`)
- `Config/Save` - Configuration changes

### Key Helper Classes
- `OrderHelper` - Line item formatting, tax calculation, quote management
- `BulkActions` - Batch shipments, cancellations, adjustments
- `PaymentMethod` - Payment method detection and filtering
- `ConfigProvider` - Payment configuration, API URLs, checkout config for the storefront

### Plugins
- `CsrfValidator` - Bypasses CSRF for webhook endpoints
- `PaymentHelper` - Filters available payment methods
- `EmailTemplate` - Injects payment variables into emails

### Cron
Two cron jobs, configured in `etc/crontab.xml`:
- `Cron/Cron.php` runs every 30 minutes for batch shipment processing.
- `Cron/RecoverCheckouts.php` runs every 10 minutes and places the order for authorized Mondu orders whose buyer never came back to the success page (see Checkout recovery).

### Checkout recovery
The Magento order is normally placed when the buyer's browser returns to `mondu/payment_checkout/success`. If it never does (tab closed during the redirect), `Service/CheckoutRecovery.php` places and confirms it from the quote:
- `Model/Request/Transactions.php` stores the Mondu order uuid with its quote in `mondu_pending_checkouts` when the order is created.
- The `order/authorized` and `order/pending` webhooks and the `RecoverCheckouts` cron call the service; it is a no-op when the order already exists.
- A lock per Mondu order uuid serializes the service and the `Success` controller, so a returning buyer and a webhook cannot place two orders.
- Without a buyer session, `Model/Checkout/OrderUuidContext.php` hands the uuid to the `CreateOrder` observer.

## Configuration Files

| File | Purpose |
|------|---------|
| `etc/config.xml` | Default payment settings for all 5 methods |
| `etc/di.xml` | Dependency injection, plugins, logger config |
| `etc/events.xml` | Core event listeners |
| `etc/db_schema.xml` | Database tables: `mondu_transactions`, `mondu_transaction_items`, `mondu_pending_checkouts` |
| `etc/adminhtml/system.xml` | Admin settings (API key, titles, descriptions) |

## API Endpoints

- **Production:** `https://api.mondu.ai/api/v1`
- **Sandbox:** `https://api.demo.mondu.ai/api/v1`

## Database

Three custom tables:
- `mondu_transactions` - Stores order references (reference_id, order_id, mondu_state, payment_method)
- `mondu_transaction_items` - Maps products to transactions
- `mondu_pending_checkouts` - Links a Mondu order uuid to its quote until the Magento order is placed

Extended `sales_order` table with `mondu_reference_id` column.