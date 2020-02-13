<?php

namespace Ls\Commerce\Plugin;

use Closure;
use Magento\Rma\Helper\Data;

class RmaPlugin
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
