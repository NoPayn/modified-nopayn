<?php

/**
 * NoPayN Admin Refund Page
 *
 * Allows administrators to process full or partial refunds for NoPayN orders.
 * Accessible from admin panel: admin/nopayn_refund.php?oID=<order_id>
 */

require 'includes/application_top.php';

// Load MMLC autoloader
if (file_exists(DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php';
} elseif (file_exists(DIR_FS_CATALOG . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_CATALOG . 'vendor-mmlc/autoload.php';
}

use CostPlus\NoPayN\NoPayNApi;

// Check admin access
if (!isset($_SESSION['customer_id']) || !isset($_SESSION['customers_status'])) {
    xtc_redirect(xtc_href_link('login.php'));
    exit;
}

$orderId = isset($_GET['oID']) ? (int) $_GET['oID'] : 0;
$message = '';
$messageType = '';

// Look up the NoPayN transaction for this order
$transaction = null;
if ($orderId > 0) {
    $query = xtc_db_query(
        "SELECT * FROM nopayn_transactions WHERE orders_id = " . $orderId . " AND status = 'completed' LIMIT 1"
    );
    $transaction = xtc_db_fetch_array($query);
}

// Get previous refunds for this order
$previousRefunds = 0;
if ($transaction) {
    $refundQuery = xtc_db_query(
        "SELECT SUM(amount) as total_refunded FROM nopayn_refunds WHERE orders_id = " . $orderId . " AND status != 'failed'"
    );
    $refundRow = xtc_db_fetch_array($refundQuery);
    $previousRefunds = $refundRow ? (int) $refundRow['total_refunded'] : 0;
}

$remainingAmount = $transaction ? ((int) $transaction['amount'] - $previousRefunds) : 0;

// Process refund request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_amount']) && $transaction) {
    $refundAmountInput = str_replace(',', '.', $_POST['refund_amount']);
    $refundAmountCents = (int) round((float) $refundAmountInput * 100);
    $refundDescription = isset($_POST['refund_description']) ? trim($_POST['refund_description']) : '';

    if ($refundAmountCents <= 0) {
        $message = 'Please enter a valid refund amount.';
        $messageType = 'error';
    } elseif ($refundAmountCents > $remainingAmount) {
        $message = 'Refund amount exceeds the remaining refundable amount (' . number_format($remainingAmount / 100, 2) . ' ' . $transaction['currency'] . ').';
        $messageType = 'error';
    } else {
        // Get API key
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
            $message = 'No NoPayN API key configured. Please configure a NoPayN payment method first.';
            $messageType = 'error';
        } else {
            try {
                $api = new NoPayNApi($apiKey);
                $result = $api->createRefund($transaction['nopayn_order_id'], $refundAmountCents, $refundDescription);

                // Create refunds table if not exists
                xtc_db_query("CREATE TABLE IF NOT EXISTS nopayn_refunds (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    orders_id INT NOT NULL,
                    nopayn_order_id VARCHAR(255) NOT NULL,
                    nopayn_refund_id VARCHAR(255) DEFAULT '',
                    amount INT NOT NULL,
                    currency VARCHAR(3) NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    description TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_orders_id (orders_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Store refund record
                $refundId = isset($result['id']) ? $result['id'] : '';
                $refundStatus = isset($result['status']) ? $result['status'] : 'pending';
                xtc_db_query(
                    "INSERT INTO nopayn_refunds SET "
                    . "orders_id = " . $orderId . ", "
                    . "nopayn_order_id = '" . xtc_db_input($transaction['nopayn_order_id']) . "', "
                    . "nopayn_refund_id = '" . xtc_db_input($refundId) . "', "
                    . "amount = " . $refundAmountCents . ", "
                    . "currency = '" . xtc_db_input($transaction['currency']) . "', "
                    . "status = '" . xtc_db_input($refundStatus) . "', "
                    . "description = '" . xtc_db_input($refundDescription) . "', "
                    . "created_at = NOW()"
                );

                // Add order status history entry
                xtc_db_query(
                    "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " SET "
                    . "orders_id = " . $orderId . ", "
                    . "orders_status_id = (SELECT orders_status FROM " . TABLE_ORDERS . " WHERE orders_id = " . $orderId . "), "
                    . "date_added = NOW(), "
                    . "customer_notified = 0, "
                    . "comments = 'NoPayN refund: " . xtc_db_input(number_format($refundAmountCents / 100, 2)) . " " . xtc_db_input($transaction['currency']) . " - " . xtc_db_input($refundDescription) . "'"
                );

                $previousRefunds += $refundAmountCents;
                $remainingAmount = (int) $transaction['amount'] - $previousRefunds;

                $message = 'Refund of ' . number_format($refundAmountCents / 100, 2) . ' ' . $transaction['currency'] . ' submitted successfully (status: ' . htmlspecialchars($refundStatus) . ').';
                $messageType = 'success';
            } catch (\Exception $e) {
                $message = 'Refund failed: ' . htmlspecialchars($e->getMessage());
                $messageType = 'error';
            }
        }
    }
}

// Load refund history
$refundHistory = [];
if ($transaction) {
    $histQuery = xtc_db_query(
        "SELECT * FROM nopayn_refunds WHERE orders_id = " . $orderId . " ORDER BY created_at DESC"
    );
    while ($row = xtc_db_fetch_array($histQuery)) {
        $refundHistory[] = $row;
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>NoPayN Refund - Order #<?php echo $orderId; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; font-size: 1.5em; margin-bottom: 20px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        .info-table td:first-child { font-weight: bold; width: 200px; color: #666; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; color: #555; }
        .form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #007bff; color: #fff; }
        .btn-primary:hover { background: #0056b3; }
        .btn-back { background: #6c757d; color: #fff; text-decoration: none; display: inline-block; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .history-table th, .history-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        .history-table th { background: #f8f9fa; font-weight: bold; color: #555; }
        .status-pending { color: #856404; }
        .status-completed { color: #155724; }
        .status-failed { color: #721c24; }
    </style>
</head>
<body>
<div class="container">
    <h1>NoPayN Refund - Order #<?php echo $orderId; ?></h1>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if (!$transaction): ?>
        <div class="alert alert-error">
            No completed NoPayN transaction found for order #<?php echo $orderId; ?>.
        </div>
        <a href="orders.php" class="btn btn-back">Back to Orders</a>
    <?php else: ?>

        <table class="info-table">
            <tr>
                <td>Order ID</td>
                <td>#<?php echo $orderId; ?></td>
            </tr>
            <tr>
                <td>NoPayN Order ID</td>
                <td><?php echo htmlspecialchars($transaction['nopayn_order_id']); ?></td>
            </tr>
            <tr>
                <td>Payment Method</td>
                <td><?php echo htmlspecialchars($transaction['payment_method']); ?></td>
            </tr>
            <tr>
                <td>Original Amount</td>
                <td><?php echo number_format($transaction['amount'] / 100, 2); ?> <?php echo htmlspecialchars($transaction['currency']); ?></td>
            </tr>
            <tr>
                <td>Already Refunded</td>
                <td><?php echo number_format($previousRefunds / 100, 2); ?> <?php echo htmlspecialchars($transaction['currency']); ?></td>
            </tr>
            <tr>
                <td>Remaining Refundable</td>
                <td><strong><?php echo number_format($remainingAmount / 100, 2); ?> <?php echo htmlspecialchars($transaction['currency']); ?></strong></td>
            </tr>
        </table>

        <?php if ($remainingAmount > 0): ?>
            <form method="post" action="nopayn_refund.php?oID=<?php echo $orderId; ?>">
                <div class="form-group">
                    <label for="refund_amount">Refund Amount (<?php echo htmlspecialchars($transaction['currency']); ?>)</label>
                    <input type="text" id="refund_amount" name="refund_amount"
                           placeholder="e.g. <?php echo number_format($remainingAmount / 100, 2); ?>"
                           required pattern="[0-9]+([.,][0-9]{1,2})?"
                           title="Enter amount in major currency units (e.g. 12.95)">
                </div>
                <div class="form-group">
                    <label for="refund_description">Reason (optional)</label>
                    <textarea id="refund_description" name="refund_description" rows="2"
                              placeholder="Reason for refund..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('Are you sure you want to process this refund?');">
                    Process Refund
                </button>
            </form>
        <?php else: ?>
            <div class="alert alert-success">This order has been fully refunded.</div>
        <?php endif; ?>

        <?php if (!empty($refundHistory)): ?>
            <h2 style="margin-top: 30px; font-size: 1.2em; color: #333;">Refund History</h2>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($refundHistory as $refund): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($refund['created_at']); ?></td>
                            <td><?php echo number_format($refund['amount'] / 100, 2); ?> <?php echo htmlspecialchars($refund['currency']); ?></td>
                            <td class="status-<?php echo htmlspecialchars($refund['status']); ?>">
                                <?php echo htmlspecialchars(ucfirst($refund['status'])); ?>
                            </td>
                            <td><?php echo htmlspecialchars($refund['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="margin-top: 20px;">
            <a href="orders.php?oID=<?php echo $orderId; ?>&action=edit" class="btn btn-back">Back to Order</a>
        </div>

    <?php endif; ?>
</div>
</body>
</html>
<?php
require 'includes/application_bottom.php';
?>
