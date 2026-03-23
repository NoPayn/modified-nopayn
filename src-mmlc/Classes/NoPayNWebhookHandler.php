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
    private ?NoPayNLogger $logger;

    public function __construct(NoPayNApi $api, ?NoPayNLogger $logger = null)
    {
        $this->api = $api;
        $this->logger = $logger;
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

        if ($this->logger) {
            $this->logger->logWebhook('Received', ['order_id' => $nopaynOrderId, 'event' => $payload['event'] ?? '']);
        }

        // Look up the transaction in our table
        $transaction = $this->findTransaction($nopaynOrderId);
        if ($transaction === null) {
            if ($this->logger) {
                $this->logger->logWebhookError('Unknown order_id ' . $nopaynOrderId);
            }
            error_log('NoPayN webhook: unknown order_id ' . $nopaynOrderId);
            return false;
        }

        // Always verify via API
        $orderData = $this->api->getOrder($nopaynOrderId);
        $apiStatus = $orderData['status'] ?? '';

        if ($this->logger) {
            $this->logger->logWebhook('API status verified', ['order_id' => $nopaynOrderId, 'status' => $apiStatus]);
        }

        // Skip if already in a final state
        $currentStatus = $transaction['status'];
        if (in_array($currentStatus, ['completed', 'cancelled', 'expired'], true)) {
            if ($this->logger) {
                $this->logger->logWebhook('Skipped - already in final state', ['order_id' => $nopaynOrderId, 'current_status' => $currentStatus]);
            }
            return false;
        }

        // Map NoPayN status to shop order status
        $shopOrdersId = (int) $transaction['orders_id'];
        $paymentMethod = $transaction['payment_method'];
        $captureMode = $transaction['capture_mode'] ?? '';

        switch ($apiStatus) {
            case 'completed':
                // If manual capture is enabled, trigger capture before completing
                if ($captureMode === 'manual') {
                    $this->handleManualCapture($nopaynOrderId, $orderData, $transaction);
                }

                $newOrderStatusId = $this->getConfigValue($paymentMethod, 'ORDER_STATUS_ID');
                $this->updateOrderStatus($shopOrdersId, (int) $newOrderStatusId, 'Payment completed (NoPayN)');
                $this->updateTransactionStatus($nopaynOrderId, 'completed');
                return true;

            case 'cancelled':
            case 'expired':
            case 'error':
                // If manual capture was used and payment was authorized, void the transaction
                if ($captureMode === 'manual' && $apiStatus === 'cancelled') {
                    $this->handleVoid($nopaynOrderId, $orderData, $transaction);
                }

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
     * Handle capture for manual capture transactions when order is completed.
     *
     * @param string $nopaynOrderId NoPayN order UUID
     * @param array $orderData Full order data from API
     * @param array $transaction Local transaction record
     */
    private function handleManualCapture(string $nopaynOrderId, array $orderData, array $transaction): void
    {
        $transactionId = $this->getTransactionId($orderData);
        if ($transactionId === '') {
            if ($this->logger) {
                $this->logger->logWebhookError('Cannot capture - no transaction ID found', ['order_id' => $nopaynOrderId]);
            }
            error_log('NoPayN webhook: cannot capture - no transaction ID for order ' . $nopaynOrderId);
            return;
        }

        try {
            if ($this->logger) {
                $this->logger->logWebhook('Capturing transaction', ['order_id' => $nopaynOrderId, 'transaction_id' => $transactionId]);
            }
            $this->api->captureTransaction($nopaynOrderId, $transactionId);
            if ($this->logger) {
                $this->logger->logWebhook('Capture successful', ['order_id' => $nopaynOrderId, 'transaction_id' => $transactionId]);
            }
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->logWebhookError('Capture failed: ' . $e->getMessage(), ['order_id' => $nopaynOrderId]);
            }
            error_log('NoPayN webhook: capture failed for order ' . $nopaynOrderId . ': ' . $e->getMessage());
        }
    }

    /**
     * Handle void for manual capture transactions when order is cancelled.
     *
     * @param string $nopaynOrderId NoPayN order UUID
     * @param array $orderData Full order data from API
     * @param array $transaction Local transaction record
     */
    private function handleVoid(string $nopaynOrderId, array $orderData, array $transaction): void
    {
        $transactionId = $this->getTransactionId($orderData);
        if ($transactionId === '') {
            if ($this->logger) {
                $this->logger->logWebhookError('Cannot void - no transaction ID found', ['order_id' => $nopaynOrderId]);
            }
            error_log('NoPayN webhook: cannot void - no transaction ID for order ' . $nopaynOrderId);
            return;
        }

        $amount = (int) $transaction['amount'];
        try {
            if ($this->logger) {
                $this->logger->logWebhook('Voiding transaction', ['order_id' => $nopaynOrderId, 'transaction_id' => $transactionId, 'amount' => $amount]);
            }
            $this->api->voidTransaction($nopaynOrderId, $transactionId, $amount, 'Order cancelled');
            if ($this->logger) {
                $this->logger->logWebhook('Void successful', ['order_id' => $nopaynOrderId, 'transaction_id' => $transactionId]);
            }
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->logWebhookError('Void failed: ' . $e->getMessage(), ['order_id' => $nopaynOrderId]);
            }
            error_log('NoPayN webhook: void failed for order ' . $nopaynOrderId . ': ' . $e->getMessage());
        }
    }

    /**
     * Extract the first transaction ID from API order data.
     *
     * @param array $orderData Full order data from API
     * @return string Transaction UUID or empty string
     */
    private function getTransactionId(array $orderData): string
    {
        if (isset($orderData['transactions'][0]['id'])) {
            return (string) $orderData['transactions'][0]['id'];
        }
        return '';
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
