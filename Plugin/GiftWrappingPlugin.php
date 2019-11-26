<?php

namespace Ls\Commerce\Plugin;

use Closure;
use Magento\GiftWrapping\Helper\Data;

class GiftWrappingPlugin
{
    /**
     * @param Data $subject
     * @param Closure $proceed
     * @return bool
     */
    public function aroundIsGiftWrappingAvailableForOrder(Data $subject, Closure $proceed)
    {
        return false;
    }

    /**
     * @param Data $subject
     * @param Closure $proceed
     * @return bool
     */
    public function aroundIsGiftWrappingAvailableForItems(Data $subject, Closure $proceed)
    {
        return false;
    }

}

?>