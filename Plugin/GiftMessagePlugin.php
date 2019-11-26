<?php

namespace Ls\Commerce\Plugin;

use Closure;
use Magento\GiftMessage\Helper\Message;

class GiftMessagePlugin
{
    /**
     * @param Message $subject
     * @param Closure $proceed
     * @return bool
     */
    public function aroundIsMessagesAllowed(Message $subject, Closure $proceed)
    {
        return false;
    }

}

?>