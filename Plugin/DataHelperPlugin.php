<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\Data;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;

/**
 * After plugin on Ls\Omni\Helper\Data::getOrderBalance.
 *
 * When a basket response exists, getOrderBalance builds the order balance from
 * getGrossamount() — the Central-derived total that excludes income_account giftcard
 * items (which BasketHelperPlugin removed from the basket request). The balance is
 * therefore understated by the income_account open amounts.
 *
 * When no basket response exists (income_account-only cart), getOrderBalance falls
 * back to $quote->getGrandTotal(), which is already correct because SalesObserverPlugin
 * added the income_account amounts to the grand total. No adjustment is needed there.
 *
 * This plugin adds the income_account total to the returned balance only in the
 * basket-response branch.
 */
class DataHelperPlugin
{
    public function __construct(
        private readonly LSR $lsr,
        private readonly CheckoutSession $checkoutSession
    ) {
    }

    /**
     * @param Data  $subject
     * @param mixed $result
     * @param mixed $giftCardAmount
     * @param mixed $loyaltyPoints
     * @param mixed $basketData
     * @return mixed
     */
    public function afterGetOrderBalance(
        Data $subject,
        mixed $result,
        mixed $giftCardAmount,
        mixed $loyaltyPoints,
        mixed $basketData
    ): mixed {
        // Only adjust when getOrderBalance used getGrossamount() (basket-response branch).
        // The no-basket branch already uses $quote->getGrandTotal() which is correct.
        if (empty($basketData) || !is_array($basketData->getMobiletransaction())
            || empty($basketData->getMobiletransaction())
        ) {
            return $result;
        }

        $incomeAccountSkus = [];
        foreach ($this->lsr->getVoucherGiftCardConfiguration() as $entry) {
            if (($entry['item_type'] ?? '') === 'income_account' && !empty($entry['code'])) {
                $incomeAccountSkus[(string) $entry['code']] = true;
            }
        }

        if (empty($incomeAccountSkus)) {
            return $result;
        }

        $quote = $this->checkoutSession->getQuote();
        $incomeAccountTotal = 0.0;

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            if ($quoteItem->getProductType() !== Giftcard::TYPE_GIFTCARD) {
                continue;
            }
            if (!isset($incomeAccountSkus[(string) $quoteItem->getSku()])) {
                continue;
            }

            $amountOption = $quoteItem->getOptionByCode('giftcard_amount');
            if ($amountOption === null) {
                continue;
            }

            $amount = (float) $amountOption->getValue();
            if ($amount <= 0.0) {
                continue;
            }

            $incomeAccountTotal += $amount * ((float) $quoteItem->getQty() ?: 1.0);
        }

        if ($incomeAccountTotal <= 0.0) {
            return $result;
        }

        return $result + $incomeAccountTotal;
    }
}
