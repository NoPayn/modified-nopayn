<?php

namespace CostPlus\NoPayN;

/**
 * Handles incoming NoPayN webhook notifications.
 *
 * Webhook payload: {"event": "status_changed", "order_id": "...", "project_id": "..."}
 * Always verifies order status via the API (never trusts webhook payload alone).
 */
class NoPayNWebhookHandler
{
    private NoPayNApi $api;

    public function __construct(NoPayNApi $api)
    {
        $this->api = $api;
    }

    /**
     * Process a webhook notification.
     *
     * @param array $payload Decoded webhook JSON
     * @return bool True if order was updated, false if skipped
     */
    public function handle(array $payload): bool
    {
        if (empty($payload['order_id'])) {
            return false;
        }

        $nopaynOrderId = $payload['order_id'];

        // Look up the transaction in our table
        $transaction = $this->findTransaction($nopaynOrderId);
        if ($transaction === null) {
            error_log('NoPayN webhook: unknown order_id ' . $nopaynOrderId);
            return false;
        }

        // Always verify via API
        $orderData = $this->api->getOrder($nopaynOrderId);
        $apiStatus = $orderData['status'] ?? '';

        // Skip if already in a final state
        $currentStatus = $transaction['status'];
        if (in_array($currentStatus, ['completed', 'cancelled', 'expired'], true)) {
            return false;
        }

        // Map NoPayN status to shop order status
        $shopOrdersId = (int) $transaction['orders_id'];
        $paymentMethod = $transaction['payment_method'];

        switch ($apiStatus) {
            case 'completed':
                $newOrderStatusId = $this->getConfigValue($paymentMethod, 'ORDER_STATUS_ID');
                $this->updateOrderStatus($shopOrdersId, (int) $newOrderStatusId, 'Payment completed (NoPayN)');
                $this->updateTransactionStatus($nopaynOrderId, 'completed');
                return true;

            case 'cancelled':
            case 'expired':
            case 'error':
                $cancelledStatusId = $this->getCancelledStatusId();
                $this->updateOrderStatus($shopOrdersId, $cancelledStatusId, 'Payment ' . $apiStatus . ' (NoPayN)');
                $this->updateTransactionStatus($nopaynOrderId, $apiStatus);
                return true;

            default:
                // processing, new — no action needed yet
                return false;
        }
    }

    /**
     * Find a NoPayN transaction by the NoPayN order ID.
     */
    private function findTransaction(string $nopaynOrderId): ?array
    {
        $query = xtc_db_query(
            "SELECT * FROM nopayn_transactions WHERE nopayn_order_id = '" . xtc_db_input($nopaynOrderId) . "' LIMIT 1"
        );
        $row = xtc_db_fetch_array($query);
        return $row ?: null;
    }

    /**
     * Update shop order status and add history entry.
     */
    private function updateOrderStatus(int $ordersId, int $statusId, string $comment): void
    {
        xtc_db_query(
            "UPDATE " . TABLE_ORDERS . " SET orders_status = " . (int) $statusId
            . ", last_modified = NOW() WHERE orders_id = " . $ordersId
        );

        xtc_db_query(
            "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " SET "
            . "orders_id = " . $ordersId . ", "
            . "orders_status_id = " . (int) $statusId . ", "
            . "date_added = NOW(), "
            . "customer_notified = 0, "
            . "comments = '" . xtc_db_input($comment) . "'"
        );
    }

    /**
     * Update the status in our nopayn_transactions table.
     */
    private function updateTransactionStatus(string $nopaynOrderId, string $status): void
    {
        xtc_db_query(
            "UPDATE nopayn_transactions SET status = '" . xtc_db_input($status)
            . "', updated_at = NOW() WHERE nopayn_order_id = '" . xtc_db_input($nopaynOrderId) . "'"
        );
    }

    /**
     * Get a module configuration value.
     */
    private function getConfigValue(string $paymentMethod, string $keySuffix): string
    {
        // Map NoPayN API payment method to module config suffix
        $methodMap = [
            'credit-card' => 'CREDITCARD',
            'apple-pay' => 'APPLEPAY',
            'google-pay' => 'GOOGLEPAY',
            'vipps-mobilepay' => 'MOBILEPAY',
        ];
        $suffix = $methodMap[$paymentMethod] ?? strtoupper(str_replace('-', '', $paymentMethod));
        $key = 'MODULE_PAYMENT_PAYMENT_NOPAYN_' . $suffix . '_' . $keySuffix;
        return defined($key) ? constant($key) : '';
    }

    /**
     * Get the cancelled/failed order status ID (default: 0).
     */
    private function getCancelledStatusId(): int
    {
        // Use order status 0 (default) or look for a "cancelled" status
        $query = xtc_db_query(
            "SELECT orders_status_id FROM " . TABLE_ORDERS_STATUS
            . " WHERE orders_status_name LIKE '%cancel%' LIMIT 1"
        );
        $row = xtc_db_fetch_array($query);
        return $row ? (int) $row['orders_status_id'] : 0;
    }
}
