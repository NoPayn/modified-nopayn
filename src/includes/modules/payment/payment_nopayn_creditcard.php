<?php

/**
 * NoPayN Credit Card Payment Module for modified eCommerce
 *
 * Supports: Visa, Mastercard, Amex, Maestro, V Pay, Bancontact, Diners Club, Discover
 */

// Load autoloader for MMLC classes
if (file_exists(DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php';
} elseif (file_exists(DIR_FS_CATALOG . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_CATALOG . 'vendor-mmlc/autoload.php';
}

use CostPlus\NoPayN\NoPayNBase;

class payment_nopayn_creditcard extends NoPayNBase
{
    public function __construct()
    {
        $this->code = 'payment_nopayn_creditcard';
        $this->nopayn_payment_method = 'credit-card';
        parent::__construct();
    }

    /**
     * Install module configuration into database.
     * Extends base install with manual capture toggle and debug logging toggle.
     */
    public function install()
    {
        parent::install();

        $sortCounter = 7; // Continue from parent's last sort order

        $this->addConfig('MANUAL_CAPTURE', 'False', 6, $sortCounter++,
            "xtc_cfg_select_option(array('True', 'False'),");

        // Install debug logging toggle (shared config, not per-method prefix)
        $key = 'MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING';
        $check = xtc_db_query(
            "SELECT configuration_value FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key = '" . xtc_db_input($key) . "'"
        );
        if (xtc_db_num_rows($check) === 0) {
            xtc_db_query(
                "INSERT INTO " . TABLE_CONFIGURATION
                . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, use_function, date_added)"
                . " VALUES ("
                . "'" . xtc_db_input($key) . "', "
                . "'False', "
                . "6, "
                . $sortCounter . ", "
                . "'xtc_cfg_select_option(array(''True'', ''False''),', "
                . "'', "
                . "NOW()"
                . ")"
            );
        }

        // Add capture_mode column to existing nopayn_transactions table if missing
        xtc_db_query("ALTER TABLE nopayn_transactions ADD COLUMN IF NOT EXISTS capture_mode VARCHAR(32) NOT NULL DEFAULT '' AFTER currency");
    }

    /**
     * Return configuration keys for admin display.
     * Extends base keys with manual capture toggle and debug logging toggle.
     */
    public function keys()
    {
        $keys = parent::keys();
        $keys[] = $this->config_prefix . '_MANUAL_CAPTURE';
        $keys[] = 'MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING';
        return $keys;
    }

    /**
     * Remove module configuration from database.
     * Extends base remove to also clean up the shared debug logging config.
     */
    public function remove()
    {
        parent::remove();

        // Only remove shared debug config if no other NoPayN method is installed
        $otherMethods = ['APPLEPAY', 'GOOGLEPAY', 'MOBILEPAY'];
        $hasOther = false;
        foreach ($otherMethods as $method) {
            $check = xtc_db_query(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = 'MODULE_PAYMENT_PAYMENT_NOPAYN_" . $method . "_STATUS'"
            );
            if (xtc_db_num_rows($check) > 0) {
                $hasOther = true;
                break;
            }
        }
        if (!$hasOther) {
            xtc_db_query(
                "DELETE FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = 'MODULE_PAYMENT_NOPAYN_DEBUG_LOGGING'"
            );
        }
    }
}
