<?php

/**
 * NoPayN Return URL Handler
 *
 * Customer is redirected here after completing (or cancelling) payment on the NoPayN HPP.
 * Verifies payment status via API before proceeding.
 *
 * Query params:
 *   - action=success  (customer completed payment flow)
 *   - action=failure  (customer cancelled or payment failed)
 */

// Bootstrap modified eCommerce
chdir(dirname(__FILE__));
require 'includes/application_top.php';

// Load MMLC autoloader
if (file_exists(DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php';
} elseif (file_exists('vendor-mmlc/autoload.php')) {
    require_once 'vendor-mmlc/autoload.php';
}

use CostPlus\NoPayN\NoPayNApi;

$action = isset($_GET['action']) ? $_GET['action'] : '';
$nopaynOrderId = isset($_SESSION['nopayn_order_id']) ? $_SESSION['nopayn_order_id'] : '';
$shopOrderId = isset($_SESSION['nopayn_shop_order_id']) ? (int) $_SESSION['nopayn_shop_order_id'] : 0;

// Validate session data
if (empty($nopaynOrderId) || $shopOrderId <= 0) {
    xtc_redirect(xtc_href_link('checkout_payment.php', '', 'SSL'));
    exit;
}

// Get API key from any configured NoPayN module
$apiKey = '';
$methods = ['CREDITCARD', 'APPLEPAY', 'GOOGLEPAY', 'MOBILEPAY'];
foreach ($methods as $method) {
    $key = 'MODULE_PAYMENT_PAYMENT_NOPAYN_' . $method . '_API_KEY';
    if (defined($key) && constant($key) !== '') {
        $apiKey = constant($key);
        break;
    }
}

if ($action === 'failure' || $apiKey === '') {
    // Customer cancelled or no API key — redirect to checkout with error
    $_SESSION['payment_error'] = 'payment_nopayn_creditcard';
    xtc_redirect(xtc_href_link(
        'checkout_payment.php',
        'payment_error=payment_nopayn_creditcard&nopayn_error=' . urlencode('Payment was cancelled or failed. Please try again.'),
        'SSL'
    ));
    exit;
}

// Verify payment status via API
try {
    $api = new NoPayNApi($apiKey);
    $orderData = $api->getOrder($nopaynOrderId);
    $status = $orderData['status'] ?? '';
} catch (\Exception $e) {
    error_log('NoPayN return: API error - ' . $e->getMessage());
    $status = '';
}

if ($status === 'completed') {
    // Payment successful — update transaction status
    xtc_db_query(
        "UPDATE nopayn_transactions SET status = 'completed', updated_at = NOW()"
        . " WHERE nopayn_order_id = '" . xtc_db_input($nopaynOrderId) . "'"
    );

    // Update order status to the configured completed status
    $transaction = xtc_db_fetch_array(xtc_db_query(
        "SELECT payment_method FROM nopayn_transactions WHERE nopayn_order_id = '" . xtc_db_input($nopaynOrderId) . "' LIMIT 1"
    ));

    if ($transaction) {
        // Map NoPayN API payment method to module config suffix
        $methodMap = [
            'credit-card' => 'CREDITCARD',
            'apple-pay' => 'APPLEPAY',
            'google-pay' => 'GOOGLEPAY',
            'vipps-mobilepay' => 'MOBILEPAY',
        ];
        $methodSuffix = $methodMap[$transaction['payment_method']] ?? strtoupper(str_replace(['-', ' '], '', $transaction['payment_method']));
        $statusKey = 'MODULE_PAYMENT_PAYMENT_NOPAYN_' . $methodSuffix . '_ORDER_STATUS_ID';
        $completedStatusId = defined($statusKey) ? (int) constant($statusKey) : 2;

        xtc_db_query(
            "UPDATE " . TABLE_ORDERS . " SET orders_status = " . $completedStatusId
            . ", last_modified = NOW() WHERE orders_id = " . $shopOrderId
        );

        xtc_db_query(
            "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " SET "
            . "orders_id = " . $shopOrderId . ", "
            . "orders_status_id = " . $completedStatusId . ", "
            . "date_added = NOW(), "
            . "customer_notified = 0, "
            . "comments = 'Payment completed via NoPayN'"
        );
    }

    // Redirect to checkout success
    xtc_redirect(xtc_href_link('checkout_success.php', '', 'SSL'));
} elseif ($status === 'processing') {
    // Payment still processing — redirect to checkout_process to finalize order, webhook will confirm final status
    xtc_redirect(xtc_href_link('checkout_process.php', '', 'SSL'));
} else {
    // Payment not completed — redirect to checkout with error
    $errorMsg = 'Payment was not completed (status: ' . htmlspecialchars($status) . '). Please try again.';
    xtc_redirect(xtc_href_link(
        'checkout_payment.php',
        'payment_error=payment_nopayn_creditcard&nopayn_error=' . urlencode($errorMsg),
        'SSL'
    ));
}
