<?php

/**
 * NoPayN Webhook Handler
 *
 * Receives POST notifications from NoPayN when order status changes.
 * Payload: {"event": "status_changed", "order_id": "...", "project_id": "..."}
 *
 * Always verifies order status via API before updating.
 * Returns HTTP 200 immediately to prevent retries.
 */

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

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
use CostPlus\NoPayN\NoPayNWebhookHandler;

// Read and decode JSON payload
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload) || empty($payload['order_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

// Determine API key: try each NoPayN payment method's config
$apiKey = '';
$methods = ['CREDITCARD', 'APPLEPAY', 'GOOGLEPAY', 'MOBILEPAY'];
foreach ($methods as $method) {
    $key = 'MODULE_PAYMENT_PAYMENT_NOPAYN_' . $method . '_API_KEY';
    if (defined($key) && constant($key) !== '') {
        $apiKey = constant($key);
        break;
    }
}

if ($apiKey === '') {
    error_log('NoPayN webhook: no API key configured');
    // Still return 200 to prevent retry storms
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => 'No API key configured']);
    exit;
}

try {
    $api = new NoPayNApi($apiKey);
    $handler = new NoPayNWebhookHandler($api);
    $updated = $handler->handle($payload);

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'updated' => $updated]);
} catch (\Exception $e) {
    error_log('NoPayN webhook error: ' . $e->getMessage());
    // Return 200 even on error to prevent unnecessary retries for permanent failures
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => 'Processing error']);
}
