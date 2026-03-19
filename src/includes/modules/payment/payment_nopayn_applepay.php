<?php

/**
 * NoPayN Apple Pay Payment Module for modified eCommerce
 */

if (file_exists(DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_DOCUMENT_ROOT . 'vendor-mmlc/autoload.php';
} elseif (file_exists(DIR_FS_CATALOG . 'vendor-mmlc/autoload.php')) {
    require_once DIR_FS_CATALOG . 'vendor-mmlc/autoload.php';
}

use CostPlus\NoPayN\NoPayNBase;

class payment_nopayn_applepay extends NoPayNBase
{
    public function __construct()
    {
        $this->code = 'payment_nopayn_applepay';
        $this->nopayn_payment_method = 'apple-pay';
        parent::__construct();
    }
}
