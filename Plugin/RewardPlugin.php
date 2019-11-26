<?php

namespace Ls\Commerce\Plugin;

use Closure;
use Magento\Reward\Helper\Data;

class RewardPlugin
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

    /**
     * @param Data $subject
     * @param Closure $proceed
     * @return bool
     */
    public function aroundIsEnabledOnFront(Data $subject, Closure $proceed)
    {
        return false;
    }
}

?>