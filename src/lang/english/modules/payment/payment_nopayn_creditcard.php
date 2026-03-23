<?php

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_TEXT_TITLE', 'Credit / Debit Card (NoPayn)');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_TEXT_DESCRIPTION', 'Accept credit and debit card payments via NoPayn. Supports Visa, Mastercard, Amex, Maestro, V Pay, Bancontact, Diners Club, and Discover.');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_TEXT_INFO', 'Pay securely with your credit or debit card.');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_STATUS_TITLE', 'Enable Credit Card');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_STATUS_DESC', 'Accept credit card payments via NoPayn?');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_API_KEY_TITLE', 'API Key');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_API_KEY_DESC', 'Your NoPayn API key. Found in your NoPayn dashboard under Settings > API Key.');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_ORDER_STATUS_ID_TITLE', 'Completed Order Status');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_ORDER_STATUS_ID_DESC', 'Order status after successful payment.');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_PENDING_STATUS_ID_TITLE', 'Pending Order Status');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_PENDING_STATUS_ID_DESC', 'Order status while payment is being processed.');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_SORT_ORDER_TITLE', 'Sort Order');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_SORT_ORDER_DESC', 'Display order in checkout.');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_ZONE_TITLE', 'Payment Zone');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_ZONE_DESC', 'If a zone is selected, enable this payment method only for that zone.');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_ALLOWED_TITLE', 'Allowed Zones');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_ALLOWED_DESC', 'Comma-separated list of allowed zone IDs (leave empty for all).');

define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_MANUAL_CAPTURE_TITLE', 'Manual Capture');
define('MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD_MANUAL_CAPTURE_DESC', 'Enable manual capture for credit card payments. When enabled, payments are only authorized and must be captured manually (e.g. on order status change to completed).');

define('MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING_TITLE', 'Debug Logging');
define('MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING_DESC', 'Enable detailed debug logging for all NoPayn payment methods. Logs API requests, responses, and webhook events to the shop log directory.');
