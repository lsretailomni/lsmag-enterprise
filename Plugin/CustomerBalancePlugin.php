<?php

namespace Ls\Commerce\Plugin;

use Closure;
use Magento\CustomerBalance\Helper\Data;

class CustomerBalancePlugin
{
    /**
     * @param Data $subject
     * @param Closure $proceed
     * @return bool
     */
    public function aroundIsEnabled(Data $subject, Closure $proceed)
    {
        return false;
    }
}

?>