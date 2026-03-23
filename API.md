# NoPayn Gateway API Reference

Source: https://dev.nopayn.io/Integration and https://nopayn.stoplight.io/docs/nopayn/6zfqjhsd9wug9-order-api

## Base URL

```
https://api.nopayn.co.uk
```

## Authentication

HTTP Basic Authentication. The username is the API key; the password is empty.

```
Authorization: Basic <base64(apikey:)>
```

Most HTTP clients handle this natively:
```bash
curl -X GET --user 'YOUR_API_KEY:' https://api.nopayn.co.uk/v1/orders/ORDER_ID/
```

## HTTP Status Codes

| Code | Definition | Notes |
|------|-----------|-------|
| 200 | OK | |
| 201 | Created | |
| 400 | Bad Request | Invalid request body/params |
| 401 | Unauthorized | Missing or invalid API key |
| 403 | Forbidden | Request not allowed |
| 404 | Not Found | Resource doesn't exist |
| 500 | Internal Server Error | Server-side issue |
| 502 | Bad Gateway | Upstream unreachable |
| 503 | Service Unavailable | System down |
| 504 | Gateway Timeout | System not responding in time |

---

## Hosted Payment Page (HPP) Flow

The HPP flow offloads payment method selection and card data handling to NoPayn,
significantly reducing PCI DSS compliance burden.

### Flow Summary

1. Merchant creates an order via `POST /v1/orders/`
2. API returns an `order_url` (and/or `transactions[].payment_url`)
3. Merchant redirects customer to that URL
4. Customer completes payment on NoPayn's hosted page
5. Customer is redirected to `return_url` (success) or `failure_url` (cancel/error/expired)
6. NoPayn sends webhook to `webhook_url` with status update

---

## Endpoints

### Create Order

```
POST /v1/orders/
```

Creates an order and returns a payment URL for the HPP.

#### Mandatory Fields

| Field | Type | Description |
|-------|------|-------------|
| `currency` | string | ISO 4217 alphabetic code (e.g. `EUR`, `GBP`, `USD`, `NOK`, `SEK`) |
| `amount` | integer | Amount in cents (e.g. 12.95 = `1295`) |

#### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `merchant_order_id` | string | Your internal order ID |
| `description` | string | Order description (stored by NoPayn) |
| `return_url` | string (URL) | Where to redirect after successful payment |
| `failure_url` | string (URL) | Where to redirect on cancel/expired/error |
| `webhook_url` | string (URL) | URL for status change notifications |
| `locale` | string | HPP language. Values: `en-GB`, `de-DE`, `nl-NL`, `nl-BE`, `fr-BE`, `sv-SE`, `no-NO`, `da-DK` |
| `payment_methods` | array of strings | Limit which payment methods are shown on HPP |
| `expiration_period` | string | ISO 8601 duration (e.g. `PT30M` for 30 minutes) |
| `customer` | object | Customer info (can contain `locale` as alternative) |

#### Available Payment Methods (for `payment_methods` filter)

- `credit-card`
- `apple-pay`
- `google-pay`
- `vipps-mobilepay`

#### Example Request

```json
POST /v1/orders/
Authorization: Basic <key>
Content-Type: application/json

{
  "currency": "EUR",
  "amount": 995,
  "description": "Example description",
  "merchant_order_id": "TEST001",
  "return_url": "https://www.example.com/",
  "failure_url": "https://www.example.com/failure/",
  "webhook_url": "https://www.example.com/webhook/",
  "payment_methods": ["credit-card", "vipps-mobilepay"],
  "locale": "de-DE"
}
```

#### Example Response

```json
{
  "id": "1c969951-f5f1-4290-ae41-6177961fb3cb",
  "currency": "EUR",
  "amount": 995,
  "description": "Example description",
  "merchant_order_id": "TEST001",
  "return_url": "https://www.example.com/",
  "failure_url": "https://www.example.com/failure/",
  "customer": {
    "locale": "nl"
  },
  "created": "2016-07-04T11:41:57.121017+00:00",
  "modified": "2016-07-04T11:41:57.183822+00:00",
  "order_url": "https://api.example.com/pay/1c969951-f5f1-4290-ae41-6177961fb3cb/",
  "status": "new",
  "transactions": [
    {
      "id": "e3ed069e-c931-40ae-8035-e022e8a4e5e7",
      "amount": 995,
      "currency": "EUR",
      "description": "Example description",
      "payment_method": "credit-card",
      "payment_url": "https://api.example.com/redirect/e3ed069e-c931-40ae-8035-e022e8a4e5e7/to/payment/",
      "status": "new",
      "created": "2020-10-14T13:15:12.650161+00:00",
      "modified": "2020-10-14T13:15:13.913284+00:00",
      "expiration_period": "PT30M",
      "balance": "internal",
      "credit_debit": "credit",
      "events": [
        {
          "event": "new",
          "id": "3cb2259b-a3f9-4908-bb98-eb51c773fc70",
          "noticed": "2020-10-14T13:15:12.812215+00:00",
          "occurred": "2020-10-14T13:15:12.650161+00:00",
          "source": "set_status"
        }
      ]
    }
  ]
}
```

> **Note**: The `order_url` is the HPP URL where the customer selects a payment method.
> Individual transactions also have a `payment_url` for direct payment method redirect.
> Our modified eCommerce module uses `transactions[0].payment_url` for redirect.

---

### Get Order Status

```
GET /v1/orders/{order_id}/
```

Returns the current order status and transaction details.

#### Order Statuses

| Status | Description | Final? |
|--------|-------------|--------|
| `new` | Order created in database | No |
| `processing` | Transactions are pending or processing | No |
| `completed` | All transactions accepted/completed; merchant can deliver | **Yes** |
| `cancelled` | All transactions cancelled | **Yes** |
| `expired` | Order expired (e.g. payment link timeout) | **Yes** |
| `error` | Processing failed (technical or transaction failure) | **Yes** |

#### Example Response (completed)

```json
{
  "id": "4c6afd74-a840-4aab-b411-1e6e0636d341",
  "amount": 100,
  "currency": "EUR",
  "description": "Example order #1",
  "status": "completed",
  "completed": "2020-10-13T07:16:44.959162+00:00",
  "created": "2020-10-13T07:16:35.402150+00:00",
  "modified": "2020-10-14T01:03:17.811770+00:00",
  "order_url": "https://api.example.com/pay/e19b9594-85ce-4177-a002-36d30a47d948/",
  "transactions": [
    {
      "id": "058cf70d-7a84-4957-941c-532c065cef72",
      "amount": 100,
      "currency": "EUR",
      "payment_method": "credit-card",
      "status": "completed",
      "completed": "2020-10-13T07:33:07.154914+00:00"
    }
  ]
}
```

---

### Create Refund

```
POST /v1/orders/{order_id}/refunds/
```

Creates a refund for a completed order. Supports partial and full refunds.

> **Note**: This endpoint is not publicly documented on the NoPayn developer portal
> but is implemented and functional in the API. Our module uses it for admin refunds.

#### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `amount` | integer | Yes | Refund amount in cents |
| `description` | string | No | Reason for the refund |

#### Example Request

```json
POST /v1/orders/4c6afd74-a840-4aab-b411-1e6e0636d341/refunds/
Authorization: Basic <key>
Content-Type: application/json

{
  "amount": 500,
  "description": "Customer returned item"
}
```

#### Example Response

```json
{
  "id": "refund-uuid",
  "amount": 500,
  "status": "pending"
}
```

---

### Capture Transaction

```
POST /v1/orders/{order_id}/transactions/{transaction_id}/captures/
```

Captures a previously authorized transaction. Used when credit card manual capture is enabled — the payment is authorized at checkout but funds are only captured when the merchant ships or completes the order.

> **Note**: Only applicable when the transaction was created with `"capture_mode": "manual"` in the transactions array.

#### Request Body

Empty body or `{}`.

#### Example Request

```json
POST /v1/orders/4c6afd74-a840-4aab-b411-1e6e0636d341/transactions/058cf70d-7a84-4957-941c-532c065cef72/captures/
Authorization: Basic <key>
Content-Type: application/json
```

#### Example Response

Returns the updated transaction object.

---

### Void Transaction

```
POST /v1/orders/{order_id}/transactions/{transaction_id}/voids/amount/
```

Voids a previously authorized (uncaptured) transaction. Used when the merchant cancels an order that was authorized but not yet captured.

> **Note**: Only applicable to authorized transactions that have not been captured or previously voided.

#### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `amount` | integer | Yes | Amount to void in cents |
| `description` | string | No | Reason for the void |

#### Example Request

```json
POST /v1/orders/4c6afd74-a840-4aab-b411-1e6e0636d341/transactions/058cf70d-7a84-4957-941c-532c065cef72/voids/amount/
Authorization: Basic <key>
Content-Type: application/json

{
  "amount": 995,
  "description": "Order cancelled by merchant"
}
```

#### Example Response

Returns the updated transaction object.

---

## Order Lines

When creating an order, you can include itemized `order_lines` for detailed transaction records and improved reconciliation.

#### Order Line Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | `physical`, `shipping_fee`, or `discount` |
| `name` | string | Yes | Product/item name |
| `quantity` | integer | Yes | Number of units |
| `amount` | integer | Yes | Price per unit in cents |
| `currency` | string | Yes | ISO 4217 code |
| `vat_percentage` | integer | No | VAT in basis points (19% = `1900`) |
| `merchant_order_line_id` | string | No | Your internal line item ID |

#### Example (in create order request)

```json
{
  "currency": "EUR",
  "amount": 1200,
  "order_lines": [
    {
      "type": "physical",
      "name": "Widget",
      "quantity": 2,
      "amount": 500,
      "currency": "EUR",
      "vat_percentage": 1900,
      "merchant_order_line_id": "WIDGET-001"
    },
    {
      "type": "shipping_fee",
      "name": "Standard Shipping",
      "quantity": 1,
      "amount": 200,
      "currency": "EUR",
      "vat_percentage": 1900,
      "merchant_order_line_id": "SHIPPING"
    }
  ]
}
```

---

## Manual Capture Flow

For credit card payments, you can use authorization + manual capture instead of immediate capture.

### Flow

1. Create order with `"capture_mode": "manual"` in the transactions array:
   ```json
   {
     "transactions": [{
       "payment_method": "credit-card",
       "capture_mode": "manual"
     }]
   }
   ```
2. Customer completes payment — funds are **authorized** but not captured
3. Merchant ships/completes order → call `POST /v1/orders/{id}/transactions/{txn}/captures/`
4. If merchant cancels → call `POST /v1/orders/{id}/transactions/{txn}/voids/amount/`

---

## Webhooks

NoPayn sends an HTTP POST to the `webhook_url` when an order status changes.

### Webhook Payload

```json
POST /your-webhook-endpoint
Content-Type: application/json

{
  "event": "status_changed",
  "order_id": "4c6afd74-a840-4aab-b411-1e6e0636d341",
  "project_id": "b5f39273-44e7-4385-8e08-44612ef3e117"
}
```

### Important

- The webhook payload only contains the order ID — **never trust it for status**.
  Always verify by calling `GET /v1/orders/{order_id}/` before updating order status.
- Return HTTP 200 to acknowledge receipt. Any other response triggers retries.

### Retry Policy

- Up to **10 retries** on failure (4xx, 5xx, timeout, connection refused)
- Retries are **2 minutes apart**
- First attempt: 4 second connect/read timeout
- Subsequent attempts: 10 second timeout
- After 10 failed attempts, delivery stops

### Webhook URL Configuration

- Can be set at the **project level** (default for all orders)
- Can be **overridden per order** by including `webhook_url` in the create order request

### Recommended Backup

NoPayn recommends implementing a background process that polls `GET /v1/orders/{id}/`
for all orders older than 10 minutes that haven't reached a final status, in case
webhooks are missed due to network issues.

---

## Testing

- Projects can be set to test mode with status `active-testing`
- In test mode, payments go through a sandbox environment with no charges
- Test API keys are available in the merchant portal: Settings > API Key
- Merchant portal: https://manage.nopayn.io/

---

## How Our Module Maps to the API

| Module Action | API Call | Notes |
|---------------|----------|-------|
| `NoPayNBase::payment_action()` | `POST /v1/orders/` | Creates order with order lines, redirects to `transactions[0].payment_url` |
| `NoPayNBase::before_process()` | `GET /v1/orders/{id}/` | Verifies status on customer return |
| `nopayn_return.php` | `GET /v1/orders/{id}/` | Return URL handler, verifies before redirect |
| `nopayn_webhook.php` → `NoPayNWebhookHandler` | `GET /v1/orders/{id}/` | Webhook verifies via API, updates shop order |
| `NoPayNWebhookHandler` (manual capture) | `POST /v1/orders/{id}/transactions/{txn}/captures/` | Auto-captures on order completion |
| `NoPayNWebhookHandler` (void on cancel) | `POST /v1/orders/{id}/transactions/{txn}/voids/amount/` | Auto-voids on order cancellation |
| `admin/nopayn_refund.php` | `POST /v1/orders/{id}/refunds/` | Admin refund page |

### Module vs API Field Mapping

Our module currently sends `transactions` array with `payment_method` inside it.
The API also supports a top-level `payment_methods` array to filter HPP methods.
Both approaches work — our module uses per-transaction method specification since
each payment module class maps to exactly one payment method.

---

## Other NoPayn Plugins (for reference)

| Platform | Repository |
|----------|-----------|
| WooCommerce | WordPress Plugin Repository (search "NoPayn Payments") |
| PrestaShop 1.7 | https://github.com/NoPayn/nopayn-prestashop-1.7 |
| PrestaShop 8 | https://github.com/NoPayn/nopayn-prestashop-8 |
| Magento 2.4.6–2.4.8 | https://github.com/NoPayn/nopayn-magento-2 |
| Shopify | https://apps.shopify.com/nopayn-payments |
| modified eCommerce | https://github.com/NoPayn/modified-nopayn (this module) |
