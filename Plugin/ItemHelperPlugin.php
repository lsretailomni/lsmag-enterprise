<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\ItemHelper;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Quote\Model\ResourceModel\Quote\Item as QuoteItemResourceModel;

class ItemHelperPlugin
{
    public function __construct(
        private readonly LSR $lsr,
        private readonly QuoteItemResourceModel $itemResourceModel
    ) {
    }

    /**
     * After plugin on compareQuoteItemsWithOrderLinesAndSetRelatedAmounts: for income_account
     * giftcard items that were excluded from basket calculation, explicitly set custom_price to
     * the open amount the customer entered. This runs before setGrandTotalGivenQuote calls
     * collectTotals(), ensuring the correct value is used in the subtotal/grand-total calculation.
     *
     * Without this, the quote item's custom_price remains null and row_total may be stale,
     * causing $0 display in mini cart, cart summary, and checkout summary.
     *
     * @param ItemHelper $subject
     * @param mixed $result
     * @param mixed $quote  (originally &$quote, but PHP objects are handles — same instance)
     * @param mixed $basketData
     * @param int   $type
     * @return mixed
     * @throws AlreadyExistsException
     */
    public function afterCompareQuoteItemsWithOrderLinesAndSetRelatedAmounts(
        ItemHelper $subject,
        mixed $result,
        mixed $quote,
        mixed $basketData,
        int $type = 1
    ): mixed {
        $incomeAccountSkus = [];
        foreach ($this->lsr->getVoucherGiftCardConfiguration() as $entry) {
            if (($entry['item_type'] ?? '') === 'income_account' && !empty($entry['code'])) {
                $incomeAccountSkus[(string)$entry['code']] = true;
            }
        }

        if (empty($incomeAccountSkus)) {
            return $result;
        }

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            if ($quoteItem->getProductType() !== Giftcard::TYPE_GIFTCARD) {
                continue;
            }
            if (!isset($incomeAccountSkus[(string)$quoteItem->getSku()])) {
                continue;
            }

            $amountOption = $quoteItem->getOptionByCode('giftcard_amount');
            if ($amountOption === null) {
                continue;
            }

            $amount = (float)$amountOption->getValue();
            if ($amount <= 0.0) {
                continue;
            }

            $qty      = (float)$quoteItem->getQty() ?: 1.0;
            $rowTotal = $amount * $qty;

            $quoteItem
                ->setCustomPrice($amount)
                ->setOriginalCustomPrice($amount)
                ->setRowTotal($rowTotal)
                ->setBaseRowTotal($rowTotal)
                ->setRowTotalInclTax($rowTotal)
                ->setBaseRowTotalInclTax($rowTotal);

            $quoteItem->getProduct()->setIsSuperMode(true);
            try {
                $this->itemResourceModel->save($quoteItem);
            } catch (AlreadyExistsException $e) {
                // non-fatal: collectTotals() will still pick up the in-memory values
            }
        }

        return $result;
    }
}
