<?php

namespace CostPlus\NoPayN;

/**
 * Base class for all NoPayN payment method modules.
 *
 * Subclasses set $code and $nopayn_payment_method, then call parent::__construct().
 * Implements the modified eCommerce payment module lifecycle for HPP (redirect) flow.
 */
class NoPayNBase
{
    /** @var string Payment module code (e.g. 'payment_nopayn_creditcard') */
    public $code;

    /** @var string Display title */
    public $title;

    /** @var string Admin description */
    public $description;

    /** @var bool Whether this module is enabled */
    public $enabled = false;

    /** @var int Sort order for checkout display */
    public $sort_order = 0;

    /** @var int Order status ID for successful payments */
    public $order_status;

    /** @var string Form action URL — must be non-empty for tmpOrders to work */
    public $form_action_url = 'checkout_process.php';

    /** @var bool Use temporary orders for redirect flow */
    public $tmpOrders = true;

    /** @var int Temp order status ID */
    public $tmpStatus = 1;

    /** @var string NoPayN payment method identifier (e.g. 'credit-card') */
    protected $nopayn_payment_method = '';

    /** @var string Configuration key prefix (e.g. 'MODULE_PAYMENT_NOPAYN_CREDITCARD') */
    protected $config_prefix = '';

    /** @var string API base URL */
    protected $api_base_url = 'https://api.nopayn.co.uk';

    /** @var NoPayNLogger|null Logger instance */
    protected $logger = null;

    public function __construct()
    {
        // Config prefix must match what modified-shop framework expects:
        // MODULE_PAYMENT_ + strtoupper(class name) e.g. MODULE_PAYMENT_PAYMENT_NOPAYN_CREDITCARD
        $this->config_prefix = 'MODULE_PAYMENT_' . strtoupper($this->code);

        // Load configuration
        $this->title = defined($this->config_prefix . '_TEXT_TITLE')
            ? constant($this->config_prefix . '_TEXT_TITLE')
            : $this->code;

        $this->description = defined($this->config_prefix . '_TEXT_DESCRIPTION')
            ? constant($this->config_prefix . '_TEXT_DESCRIPTION')
            : '';

        $this->enabled = (defined($this->config_prefix . '_STATUS')
            && constant($this->config_prefix . '_STATUS') === 'True');

        $this->sort_order = defined($this->config_prefix . '_SORT_ORDER')
            ? (int) constant($this->config_prefix . '_SORT_ORDER')
            : 0;

        $this->order_status = defined($this->config_prefix . '_ORDER_STATUS_ID')
            ? (int) constant($this->config_prefix . '_ORDER_STATUS_ID')
            : 0;

        if (defined($this->config_prefix . '_PENDING_STATUS_ID')) {
            $this->tmpStatus = (int) constant($this->config_prefix . '_PENDING_STATUS_ID');
        }

        // Initialize logger
        $this->logger = $this->getLogger();
    }

    /**
     * Update module availability based on zone restrictions.
     */
    public function update_status()
    {
        global $order;

        if ($this->enabled && defined($this->config_prefix . '_ZONE') && (int) constant($this->config_prefix . '_ZONE') > 0) {
            $check_flag = false;
            $check = xtc_db_query(
                "SELECT zone_id FROM " . TABLE_ZONES_TO_GEO_ZONES
                . " WHERE geo_zone_id = " . (int) constant($this->config_prefix . '_ZONE')
                . " AND zone_country_id = " . (int) $order->billing['country']['id']
                . " ORDER BY zone_id"
            );
            while ($check_row = xtc_db_fetch_array($check)) {
                if ($check_row['zone_id'] < 1 || $check_row['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
            }
            if (!$check_flag) {
                $this->enabled = false;
            }
        }
    }

    /**
     * Display this payment method in the checkout payment selection.
     */
    public function selection()
    {
        // Show clean title to customers (strip admin branding suffix)
        $frontendTitle = preg_replace('/\s*\(NoPayn\)\s*$/', '', $this->title);

        return [
            'id' => $this->code,
            'module' => $frontendTitle,
            'description' => defined($this->config_prefix . '_TEXT_INFO')
                ? constant($this->config_prefix . '_TEXT_INFO')
                : $this->description,
        ];
    }

    /**
     * Validate before showing confirmation page.
     */
    public function pre_confirmation_check()
    {
        return false;
    }

    /**
     * Display confirmation details.
     */
    public function confirmation()
    {
        return [
            'title' => $this->title,
        ];
    }

    /**
     * Hidden form fields for the confirmation form.
     */
    public function process_button()
    {
        return '';
    }

    /**
     * Called before order is processed.
     * On first pass (no tmp_oID): does nothing, temp order created by framework.
     * On second pass (returning from HPP): verifies payment status via API.
     */
    public function before_process()
    {
        global $order, $insert_id;

        // Second pass — customer returned from HPP, tmp_oID exists
        if (isset($_SESSION['tmp_oID']) && isset($_SESSION['nopayn_order_id'])) {
            $nopaynOrderId = $_SESSION['nopayn_order_id'];
            try {
                $api = $this->getApi();
                $orderData = $api->getOrder($nopaynOrderId);
                $status = $orderData['status'] ?? '';
            } catch (\Exception $e) {
                $this->logError('before_process: API error - ' . $e->getMessage());
                $status = '';
            }

            if ($status !== 'completed' && $status !== 'processing') {
                $this->redirectWithError('Payment was not completed (status: ' . $status . '). Please try again.');
                return;
            }

            // Update transaction status
            xtc_db_query(
                "UPDATE nopayn_transactions SET status = '" . xtc_db_input($status) . "', updated_at = NOW()"
                . " WHERE nopayn_order_id = '" . xtc_db_input($nopaynOrderId) . "'"
            );
        }
    }

    /**
     * Called after temporary order is created. Creates NoPayN order via API
     * and redirects customer to the hosted payment page.
     */
    public function payment_action()
    {
        global $order, $insert_id;

        $orderId = isset($insert_id) ? (int) $insert_id : 0;
        if ($orderId <= 0) {
            $this->redirectWithError('Order creation failed.');
            return;
        }

        $api = $this->getApi();

        // Calculate amount in cents
        $amountCents = (int) round($order->info['total'] * 100);
        $currency = $order->info['currency'];

        // Determine shop base URL
        $shopUrl = $this->getShopUrl();

        // Build transaction data
        $transactionData = ['payment_method' => $this->nopayn_payment_method];

        // Check if manual capture is enabled (credit card only)
        $captureMode = '';
        if ($this->isManualCaptureEnabled()) {
            $transactionData['capture_mode'] = 'manual';
            $captureMode = 'manual';
        }

        // Build order params
        $params = [
            'merchant_order_id' => (string) $orderId,
            'amount' => $amountCents,
            'currency' => $currency,
            'description' => 'Order #' . $orderId,
            'return_url' => $shopUrl . 'nopayn_return.php?action=success',
            'failure_url' => $shopUrl . 'nopayn_return.php?action=failure',
            'webhook_url' => $shopUrl . 'nopayn_webhook.php',
            'transactions' => [$transactionData],
        ];

        // Add locale if available
        $locale = $this->getLocale();
        if ($locale) {
            $params['locale'] = $locale;
        }

        // Add itemized order lines
        $orderLines = $this->buildOrderLines($order, $currency);
        if (!empty($orderLines)) {
            $params['order_lines'] = $orderLines;
        }

        $this->logDebug('Creating order #' . $orderId, ['params' => $params]);

        try {
            $response = $api->createOrder($params);
        } catch (\RuntimeException $e) {
            $this->logError('Create order error: ' . $e->getMessage());
            $this->redirectWithError('Payment gateway error. Please try again.');
            return;
        }

        if (empty($response['id']) || empty($response['transactions'][0]['payment_url'])) {
            $this->logError('Invalid response - missing id or payment_url');
            $this->redirectWithError('Payment gateway error. Please try again.');
            return;
        }

        $nopaynOrderId = $response['id'];
        $paymentUrl = $response['transactions'][0]['payment_url'];

        // Store transaction in our tracking table
        $this->saveTransaction($orderId, $nopaynOrderId, $amountCents, $currency, $captureMode);

        // Save NoPayN order ID in session for return handler
        $_SESSION['nopayn_order_id'] = $nopaynOrderId;
        $_SESSION['nopayn_shop_order_id'] = $orderId;

        $this->logDebug('Redirecting to payment page', ['nopayn_order_id' => $nopaynOrderId, 'payment_url' => $paymentUrl]);

        // Redirect customer to NoPayN hosted payment page
        xtc_redirect($paymentUrl);
    }

    /**
     * Post-processing after order is finalized.
     */
    public function after_process()
    {
        global $insert_id;

        // Clean up session
        unset($_SESSION['nopayn_order_id']);
        unset($_SESSION['nopayn_shop_order_id']);
    }

    /**
     * Display on success page.
     */
    public function success()
    {
        return false;
    }

    /**
     * Return error message for display.
     */
    public function get_error()
    {
        if (isset($_GET['nopayn_error'])) {
            return [
                'title' => $this->title,
                'error' => htmlspecialchars(urldecode($_GET['nopayn_error'])),
            ];
        }
        return false;
    }

    /**
     * Client-side validation (none needed for HPP).
     */
    public function javascript_validation()
    {
        return '';
    }

    /**
     * Check if module is installed.
     */
    public function check()
    {
        if (!isset($this->_check)) {
            $check_query = xtc_db_query(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . $this->config_prefix . "_STATUS'"
            );
            $this->_check = xtc_db_num_rows($check_query);
        }
        return $this->_check;
    }

    /**
     * Install module configuration into database.
     */
    public function install()
    {
        $sortCounter = 0;

        $this->addConfig('STATUS', 'True', 6, $sortCounter++,
            "xtc_cfg_select_option(array('True', 'False'),");

        $this->addConfig('API_KEY', '', 6, $sortCounter++);

        $this->addConfig('ORDER_STATUS_ID', '2', 6, $sortCounter++,
            'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name');

        $this->addConfig('PENDING_STATUS_ID', '1', 6, $sortCounter++,
            'xtc_cfg_pull_down_order_statuses(', 'xtc_get_order_status_name');

        $this->addConfig('SORT_ORDER', '0', 6, $sortCounter++);

        $this->addConfig('ZONE', '0', 6, $sortCounter++,
            'xtc_cfg_pull_down_zone_classes(', 'xtc_get_zone_class_title');

        $this->addConfig('ALLOWED', '', 6, $sortCounter++);

        // Create tracking table if not exists
        $this->createTransactionsTable();
    }

    /**
     * Remove module configuration from database.
     */
    public function remove()
    {
        xtc_db_query(
            "DELETE FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key LIKE '" . $this->config_prefix . "_%'"
        );
    }

    /**
     * Return configuration keys for admin display.
     */
    public function keys()
    {
        return [
            $this->config_prefix . '_STATUS',
            $this->config_prefix . '_API_KEY',
            $this->config_prefix . '_ORDER_STATUS_ID',
            $this->config_prefix . '_PENDING_STATUS_ID',
            $this->config_prefix . '_SORT_ORDER',
            $this->config_prefix . '_ZONE',
            $this->config_prefix . '_ALLOWED',
        ];
    }

    // -------------------------------------------------------------------------
    // Protected helpers
    // -------------------------------------------------------------------------

    /**
     * Get an API client instance.
     */
    protected function getApi(): NoPayNApi
    {
        $apiKey = defined($this->config_prefix . '_API_KEY')
            ? constant($this->config_prefix . '_API_KEY')
            : '';

        return new NoPayNApi($apiKey, $this->api_base_url, $this->logger);
    }

    /**
     * Get the shop's base URL (with trailing slash).
     */
    protected function getShopUrl(): string
    {
        if (defined('HTTPS_SERVER') && HTTPS_SERVER !== '') {
            return rtrim(HTTPS_SERVER, '/') . '/' . ltrim(DIR_WS_CATALOG, '/');
        }
        if (defined('HTTP_SERVER') && defined('DIR_WS_CATALOG')) {
            return rtrim(HTTP_SERVER, '/') . '/' . ltrim(DIR_WS_CATALOG, '/');
        }
        return '/';
    }

    /**
     * Map modified-shop language to NoPayN locale code.
     */
    protected function getLocale(): string
    {
        $lang = $_SESSION['language'] ?? '';
        $map = [
            'german' => 'de-DE',
            'english' => 'en-GB',
            'french' => 'fr-FR',
            'dutch' => 'nl-NL',
            'italian' => 'it-IT',
            'spanish' => 'es-ES',
        ];
        return $map[$lang] ?? '';
    }

    /**
     * Check if manual capture mode is enabled for this payment method.
     */
    protected function isManualCaptureEnabled(): bool
    {
        return defined($this->config_prefix . '_MANUAL_CAPTURE')
            && constant($this->config_prefix . '_MANUAL_CAPTURE') === 'True';
    }

    /**
     * Build itemized order lines from the cart/order for the API payload.
     *
     * @param object $order The modified eCommerce order object
     * @param string $currency ISO 4217 currency code
     * @return array Order lines array for the API
     */
    protected function buildOrderLines($order, string $currency): array
    {
        $orderLines = [];
        $lineId = 1;

        // Add product lines
        if (isset($order->products) && is_array($order->products)) {
            foreach ($order->products as $product) {
                $unitPrice = isset($product['final_price']) ? (float) $product['final_price'] : (float) ($product['price'] ?? 0);
                $unitPriceCents = (int) round($unitPrice * 100);
                $quantity = isset($product['qty']) ? (int) $product['qty'] : 1;
                $taxRate = isset($product['tax']) ? (int) round((float) $product['tax'] * 100) : 0;
                $name = $product['name'] ?? 'Product';

                $orderLines[] = [
                    'type' => 'physical',
                    'name' => $name,
                    'quantity' => $quantity,
                    'amount' => $unitPriceCents,
                    'currency' => $currency,
                    'vat_percentage' => $taxRate,
                    'merchant_order_line_id' => (string) $lineId,
                ];

                $lineId++;
            }
        }

        // Add shipping fee line if applicable
        $shippingCost = 0;
        if (isset($order->info['shipping_cost'])) {
            $shippingCost = (float) $order->info['shipping_cost'];
        }
        if ($shippingCost > 0) {
            $shippingCents = (int) round($shippingCost * 100);
            $shippingTitle = isset($order->info['shipping_method']) ? $order->info['shipping_method'] : 'Shipping';

            // Determine shipping tax rate
            $shippingTaxRate = 0;
            if (isset($order->info['shipping_tax']) && $shippingCost > 0) {
                $shippingTaxRate = (int) round(((float) $order->info['shipping_tax'] / $shippingCost) * 10000);
            }

            $orderLines[] = [
                'type' => 'shipping_fee',
                'name' => $shippingTitle,
                'quantity' => 1,
                'amount' => $shippingCents,
                'currency' => $currency,
                'vat_percentage' => $shippingTaxRate,
                'merchant_order_line_id' => (string) $lineId,
            ];
        }

        return $orderLines;
    }

    /**
     * Save a transaction record.
     */
    protected function saveTransaction(int $ordersId, string $nopaynOrderId, int $amount, string $currency, string $captureMode = ''): void
    {
        xtc_db_query(
            "INSERT INTO nopayn_transactions SET "
            . "orders_id = " . $ordersId . ", "
            . "nopayn_order_id = '" . xtc_db_input($nopaynOrderId) . "', "
            . "payment_method = '" . xtc_db_input($this->nopayn_payment_method) . "', "
            . "amount = " . $amount . ", "
            . "currency = '" . xtc_db_input($currency) . "', "
            . "capture_mode = '" . xtc_db_input($captureMode) . "', "
            . "status = 'new', "
            . "created_at = NOW(), "
            . "updated_at = NOW()"
        );
    }

    /**
     * Create the nopayn_transactions tracking table.
     */
    protected function createTransactionsTable(): void
    {
        xtc_db_query("CREATE TABLE IF NOT EXISTS nopayn_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            orders_id INT NOT NULL,
            nopayn_order_id VARCHAR(255) NOT NULL,
            payment_method VARCHAR(64) NOT NULL,
            amount INT NOT NULL,
            currency VARCHAR(3) NOT NULL,
            capture_mode VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_orders_id (orders_id),
            INDEX idx_nopayn_order_id (nopayn_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * Add a configuration entry to the database.
     */
    protected function addConfig(
        string $keySuffix,
        string $defaultValue,
        int $groupId,
        int $sortOrder,
        string $setFunction = '',
        string $useFunction = ''
    ): void {
        $key = $this->config_prefix . '_' . $keySuffix;
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added)"
            . " VALUES ("
            . "'" . xtc_db_input($key) . "', "
            . "'" . xtc_db_input($defaultValue) . "', "
            . $groupId . ", "
            . $sortOrder . ", "
            . "'" . xtc_db_input($setFunction) . "', "
            . "'" . xtc_db_input($useFunction) . "', "
            . "NOW()"
            . ")"
        );
    }

    /**
     * Redirect back to checkout with an error message.
     */
    protected function redirectWithError(string $message): void
    {
        xtc_redirect(
            xtc_href_link('checkout_payment.php', 'payment_error=' . $this->code . '&nopayn_error=' . urlencode($message), 'SSL')
        );
    }

    /**
     * Get a logger instance.
     */
    protected function getLogger(): NoPayNLogger
    {
        return new NoPayNLogger();
    }

    /**
     * Log an error message (always logged).
     */
    protected function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        }
        error_log('NoPayN ' . $message);
    }

    /**
     * Log a debug message (only when debug logging is enabled).
     */
    protected function logDebug(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->debug($message, $context);
        }
    }
}
