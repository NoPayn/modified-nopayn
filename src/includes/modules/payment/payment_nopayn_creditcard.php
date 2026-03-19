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
}
