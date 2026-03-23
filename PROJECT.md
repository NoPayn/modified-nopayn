# NoPayn Payment Module for modified eCommerce

## Overview

Payment gateway integration that connects modified eCommerce shops to the NoPayn/Cost+ payment platform via a Hosted Payment Page (HPP) redirect flow. No card data touches the shop server (PCI DSS compliant).

- **Developer**: Cost+ (https://costplus.io)
- **GitHub**: `git@github.com:NoPayn/modified-nopayn.git`
- **Version**: 1.0.0
- **License**: Free
- **Compatibility**: modified eCommerce 2.0.7.0 – 3.3.0
- **PHP**: >= 8.0
- **API Base URL**: `https://api.nopayn.co.uk`
- **API Auth**: HTTP Basic (API key as username, empty password)

## Supported Payment Methods

| Method | Class | NoPayN identifier | Card Networks |
|--------|-------|-------------------|---------------|
| Credit / Debit Card | `payment_nopayn_creditcard` | `credit-card` | Visa, Mastercard, Amex, Maestro, V Pay, Bancontact, Diners, Discover |
| Apple Pay | `payment_nopayn_applepay` | `apple-pay` | — |
| Google Pay | `payment_nopayn_googlepay` | `google-pay` | — |
| Vipps / MobilePay | `payment_nopayn_mobilepay` | `vipps-mobilepay` | — |

## Architecture

The module follows MMLC (Modified Module Loader Client) conventions with PSR-4 autoloading.

```
Namespace: CostPlus\NoPayN\
Autoload:  src-mmlc/Classes/ → CostPlus\NoPayN\
```

### File Structure

```
moduleinfo.json                                    # MMLC metadata (name, version, compatibility, autoload)
src-mmlc/Classes/
├── NoPayNBase.php                                 # Base class: full payment module lifecycle
├── NoPayNApi.php                                  # HTTP client for NoPayN REST API
└── NoPayNWebhookHandler.php                       # Webhook processor (verifies via API, updates order status)
src/
├── includes/modules/payment/
│   ├── payment_nopayn_creditcard.php              # Thin subclass → credit-card
│   ├── payment_nopayn_applepay.php                # Thin subclass → apple-pay
│   ├── payment_nopayn_googlepay.php               # Thin subclass → google-pay
│   └── payment_nopayn_mobilepay.php               # Thin subclass → vipps-mobilepay
├── lang/english/modules/payment/
│   ├── payment_nopayn_creditcard.php              # English translations
│   ├── payment_nopayn_applepay.php
│   ├── payment_nopayn_googlepay.php
│   └── payment_nopayn_mobilepay.php
├── lang/german/modules/payment/
│   ├── payment_nopayn_creditcard.php              # German translations
│   ├── payment_nopayn_applepay.php
│   ├── payment_nopayn_googlepay.php
│   └── payment_nopayn_mobilepay.php
├── nopayn_webhook.php                             # Webhook endpoint (POST from NoPayN servers)
├── nopayn_return.php                              # Return URL handler (customer redirect back from HPP)
└── admin/
    └── nopayn_refund.php                          # Admin refund page (full/partial refunds with history)
```

### Core Classes

**NoPayNBase** (`src-mmlc/Classes/NoPayNBase.php`)
- Abstract base for all four payment methods
- Implements the modified eCommerce payment module interface: `install()`, `remove()`, `check()`, `keys()`, `selection()`, `pre_confirmation_check()`, `confirmation()`, `process_button()`, `before_process()`, `payment_action()`, `after_process()`, `success()`, `get_error()`, `update_status()`
- Uses `tmpOrders = true` for the redirect flow
- `payment_action()` calls the API to create an order and redirects customer to the HPP
- `before_process()` verifies payment on return from HPP
- Zone-based availability filtering via `update_status()`
- Locale mapping: german→de-DE, english→en-GB, french→fr-FR, dutch→nl-NL, italian→it-IT, spanish→es-ES

**NoPayNApi** (`src-mmlc/Classes/NoPayNApi.php`)
- cURL-based HTTP client
- `createOrder(array $params): array` — POST `/v1/orders/`
- `getOrder(string $orderId): array` — GET `/v1/orders/{id}/`
- `createRefund(string $orderId, int $amountCents, string $description): array` — POST `/v1/orders/{id}/refunds/`
- SSL verification enabled, 30s timeout, 10s connect timeout
- Throws `\RuntimeException` on network errors or HTTP 4xx/5xx

**NoPayNWebhookHandler** (`src-mmlc/Classes/NoPayNWebhookHandler.php`)
- Processes `{"event": "status_changed", "order_id": "..."}` webhooks
- Always verifies order status via API (never trusts payload alone)
- Maps NoPayN statuses: `completed` → configured order status, `cancelled`/`expired`/`error` → shop cancelled status
- Skips orders already in final state (`completed`, `cancelled`, `expired`)
- Updates both `nopayn_transactions` table and shop order status + history

## Payment Flow

1. Customer selects a NoPayn method at checkout
2. modified eCommerce creates a temporary order (`tmpOrders = true`, `tmpStatus = 1`)
3. `payment_action()` calls `POST /v1/orders/` with amount (cents), currency, return/failure/webhook URLs, payment method, and locale
4. Customer is redirected to the NoPayN Hosted Payment Page
5. After payment, two things happen in parallel:
   - **Synchronous**: Customer returns to `nopayn_return.php` → verifies via API → updates order → redirects to success/failure
   - **Asynchronous**: NoPayN sends webhook to `nopayn_webhook.php` → verifies via API → updates order status

## Database Tables

**nopayn_transactions** (created on module install)
| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT | Primary key |
| orders_id | INT | Shop order ID |
| nopayn_order_id | VARCHAR(255) | NoPayN order UUID |
| payment_method | VARCHAR(64) | e.g. `credit-card`, `apple-pay` |
| amount | INT | Amount in cents |
| currency | VARCHAR(3) | e.g. `EUR`, `GBP` |
| status | VARCHAR(32) | `new`, `processing`, `completed`, `cancelled`, `expired`, `error` |
| created_at | DATETIME | |
| updated_at | DATETIME | Auto-updates on change |

**nopayn_refunds** (created on first refund)
| Column | Type | Description |
|--------|------|-------------|
| id | INT AUTO_INCREMENT | Primary key |
| orders_id | INT | Shop order ID |
| nopayn_order_id | VARCHAR(255) | NoPayN order UUID |
| nopayn_refund_id | VARCHAR(255) | NoPayN refund UUID |
| amount | INT | Refund amount in cents |
| currency | VARCHAR(3) | |
| status | VARCHAR(32) | `pending`, `completed`, `failed` |
| description | TEXT | Refund reason |
| created_at | DATETIME | |

## Admin Configuration (per payment method)

Each payment method has these settings in the modified eCommerce admin under Modules → Payment:

| Key Suffix | Default | Description |
|------------|---------|-------------|
| `_STATUS` | `True` | Enable/disable this payment method |
| `_API_KEY` | (empty) | NoPayn API key from dashboard |
| `_ORDER_STATUS_ID` | `2` | Order status after successful payment |
| `_PENDING_STATUS_ID` | `1` | Order status while payment is processing |
| `_SORT_ORDER` | `0` | Display order in checkout |
| `_ZONE` | `0` | Restrict to specific geo zone |
| `_ALLOWED` | (empty) | Comma-separated allowed zone IDs |

Config key format: `MODULE_PAYMENT_PAYMENT_NOPAYN_{METHOD}_{SUFFIX}`
e.g. `MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_API_KEY`

## Development Environment

- **Shop URL**: `https://modified-shop.nopayn.tech` (sandbox, via Traefik)
- **Shop container**: `sandbox-sandbox-shop-1` (PHP 8.2 Apache)
- **Database container**: `sandbox-sandbox-mysql-1` (MySQL 8.0)
- **Database**: `modified_shop`, user `modified`
- **Shop document root**: `/opt/sandbox/data/shop/`
- **Module source**: `/opt/sandbox/module/costplus/nopayn/`

## Git History

| Hash | Date | Message |
|------|------|---------|
| `c9f7e93` | 2026-03-19 | NoPayn payment module v1.0.0 for modified eCommerce |
| `e7264af` | 2026-03-19 | Fix payment method config mapping bug and rename NoPayN to NoPayn |

Tag: `v1.0.0` on `e7264af`
