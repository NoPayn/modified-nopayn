# NoPayn Payment Module for modified eCommerce

Accept payments via **NoPayn / Cost+** in your [modified eCommerce](https://www.modified-shop.org/) shop.

Supported payment methods:

- Credit / Debit Card (Visa, Mastercard, Amex, Maestro, V Pay, Bancontact, Diners, Discover)
- Apple Pay
- Google Pay
- Vipps / MobilePay

Payments are processed on a PCI DSS compliant **Hosted Payment Page (HPP)** — no card data touches your server.

## Requirements

- modified eCommerce 2.0.7.0 – 3.3.0
- PHP 8.0+
- MMLC (Modified Module Loader Client) installed in your shop
- A NoPayn / Cost+ merchant account ([costplus.io](https://costplus.io))

## Installation

### Via MMLC (recommended)

1. Open MMLC in your shop admin (`/ModifiedModuleLoaderClient/`)
2. Go to **Remote modules** or use the search
3. Find **NoPayn Payment Gateway** and click **Install**
4. MMLC will copy all files to the correct locations automatically

### Via GitHub

1. Download the [latest release](https://github.com/CostPlus-Pay/modified-nopayn/releases) or clone this repo
2. Copy the contents of the `src/` directory into your shop root:
   ```
   src/includes/modules/payment/*  →  {shop}/includes/modules/payment/
   src/lang/english/modules/payment/*  →  {shop}/lang/english/modules/payment/
   src/lang/german/modules/payment/*  →  {shop}/lang/german/modules/payment/
   src/nopayn_return.php  →  {shop}/nopayn_return.php
   src/nopayn_webhook.php  →  {shop}/nopayn_webhook.php
   src/admin/nopayn_refund.php  →  {shop}/admin/nopayn_refund.php
   ```
3. Copy `src-mmlc/Classes/` to your shop's vendor autoload directory:
   ```
   src-mmlc/Classes/*  →  {shop}/vendor-mmlc/CostPlus/NoPayN/
   ```
4. Ensure the PSR-4 autoloader in `vendor-mmlc/autoload.php` maps the `CostPlus\NoPayN\` namespace, or add:
   ```php
   spl_autoload_register(function ($class) {
       $prefix = 'CostPlus\\NoPayN\\';
       $base_dir = __DIR__ . '/CostPlus/NoPayN/';
       if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
       $file = $base_dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
       if (file_exists($file)) require $file;
   });
   ```

## Configuration

1. Log in to your shop admin panel
2. Go to **Modules → Payment Modules**
3. You will see four new modules (each can be enabled independently):
   - Credit / Debit Card (NoPayn)
   - Apple Pay (NoPayn)
   - Google Pay (NoPayn)
   - Vipps / MobilePay (NoPayn)
4. Click **Install** on each payment method you want to offer
5. Configure each module:

| Setting | Description |
|---------|-------------|
| **Status** | Enable or disable this payment method |
| **API Key** | Your NoPayn API key (from your Cost+ dashboard) |
| **Order Status (Success)** | Order status after successful payment (default: Processing) |
| **Order Status (Pending)** | Order status while payment is pending (default: Open) |
| **Sort Order** | Display order in checkout |
| **Payment Zone** | Restrict to specific geo zones (optional) |
| **Allowed Zones** | Comma-separated zone IDs (optional) |

> **Tip:** Use the same API key for all four payment methods — they share the same NoPayn project.

## How It Works

1. Customer selects a payment method at checkout
2. A temporary order is created in your shop
3. Customer is redirected to the NoPayn Hosted Payment Page
4. After payment, customer returns to your shop
5. The order status is updated automatically (via return redirect + webhook)

### Webhooks

The module automatically registers a webhook URL with each payment. NoPayn sends status updates to `https://your-shop.com/nopayn_webhook.php`. The webhook handler:

- Verifies the payment status via the NoPayn API (never trusts the webhook payload alone)
- Updates the order status and adds a history entry
- Is idempotent — duplicate webhooks are handled safely

No manual webhook configuration is needed in your NoPayn dashboard.

### Refunds

To issue a refund:

1. Open the order in your shop admin
2. Navigate to **Admin → nopayn_refund.php?oID={order_id}** (or add a link in your admin template)
3. Enter the refund amount (full or partial)
4. The refund is processed via the NoPayn API

## Test Cards

Use these cards in NoPayn test mode:

| Card | Number | Notes |
|------|--------|-------|
| Visa (frictionless) | `4018 8100 0010 0036` | No 3DS challenge |
| Mastercard (frictionless) | `5420 7110 0021 0016` | No 3DS challenge |
| Visa (3DS) | `4018 8100 0015 0015` | OTP: `0101` (success), `3333` (fail) |
| Mastercard (3DS) | `5299 9100 1000 0015` | OTP: `4445` (success), `9999` (fail) |

Use any future expiry date and any 3-digit CVC.

> **Note:** Apple Pay cannot be tested in test mode (Apple restriction).

## Support

- Documentation: [docs.costplus.io](https://docs.costplus.io)
- Issues: [GitHub Issues](https://github.com/CostPlus-Pay/modified-nopayn/issues)
- Contact: [costplus.io](https://costplus.io)

## License

This module is free to use for all NoPayn / Cost+ merchants.
