<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Client\CentralEcommerce\Entity\MobileTransactionLine;
use \Ls\Omni\Client\CentralEcommerce\Entity\RootMobileTransaction;
use \Ls\Omni\Helper\BasketHelper;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Quote\Model\Quote;

class BasketHelperPlugin
{
    public function __construct(
        private readonly LSR $lsr
    ) {
    }

    /**
     * After plugin on setOneListQuote: remove income_account giftcard lines from the basket.
     *
     * income_account giftcard items map to GL (income/expense) accounts which LS Central's
     * EcomCalculateBasket does not support. Sending them as basket lines causes Central to
     * return a non-zero grossamount while netamount stays 0, so SalesObserver sets grandTotal
     * to that non-zero amount and SalesObserverPlugin then adds the income_account amount on
     * top — doubling it in the grand total display.
     *
     * We strip every MobileTransactionLine whose NUMBER matches the lsrItemId of an
     * income_account giftcard item. BasketHelper always sets NUMBER = lsrItemId (the product's
     * ls_item_id attribute), so filtering by lsrItemId is authoritative regardless of what
     * item_account_no is configured. Income_account items are identified by matching the
     * product SKU against entry['code'] in VoucherGiftCardConfiguration — consistent with
     * how SalesObserverPlugin and DataHelperPlugin identify the same items.
     *
     * The filtered array is re-indexed with array_values() so the SOAP serialiser that builds
     * the EcomCalculateBasket request does not see index gaps.
     *
     * @param BasketHelper $subject
     * @param RootMobileTransaction $result
     * @param Quote $quote
     * @return RootMobileTransaction
     */
    public function afterSetOneListQuote(
        BasketHelper $subject,
        RootMobileTransaction $result,
        Quote $quote
    ): RootMobileTransaction {
        $lines = $result->getMobiletransactionline();
        if (empty($lines)) {
            return $result;
        }

        // Identify income_account SKUs from config (same lookup strategy as SalesObserverPlugin).
        $incomeAccountSkus = [];
        foreach ($this->lsr->getVoucherGiftCardConfiguration() as $entry) {
            if (($entry['item_type'] ?? '') === 'income_account' && !empty($entry['code'])) {
                $incomeAccountSkus[(string) $entry['code']] = true;
            }
        }

        if (empty($incomeAccountSkus)) {
            return $result;
        }

        // Collect the lsrItemId for each income_account giftcard item in the quote.
        // BasketHelper sets MobileTransactionLine::NUMBER to lsrItemId, so filtering by
        // lsrItemId reliably removes the correct lines regardless of item_account_no value.
        $incomeAccountItemIds = [];
        foreach ($quote->getAllItems() as $item) {
            if ($item->getProductType() !== Giftcard::TYPE_GIFTCARD) {
                continue;
            }
            if (!isset($incomeAccountSkus[(string) $item->getSku()])) {
                continue;
            }

            $product = $item->getProduct();
            if ($product === null) {
                continue;
            }

            $lsrItemId = (string) $product->getData(LSR::LS_ITEM_ID_ATTRIBUTE_CODE);
            if ($lsrItemId === '') {
                continue;
            }

            $incomeAccountItemIds[$lsrItemId] = true;
        }

        if (empty($incomeAccountItemIds)) {
            return $result;
        }

        $filtered = [];
        foreach ($lines as $line) {
            if (!isset($incomeAccountItemIds[(string) $line->getData(MobileTransactionLine::NUMBER)])) {
                $filtered[] = $line;
            }
        }

        $result->setMobiletransactionline(array_values($filtered));

        // When all basket lines belonged to income_account items the basket sent to Central
        // will be empty. Central returns null for an empty basket, so update() never calls
        // setOneListCalculationInCheckoutSession() and any stale quote.basket_response from
        // a previous cart session persists. On the next collectTotals() SalesObserver reads
        // that stale data (wrong netamount / grossamount) instead of falling through to the
        // correct no-basket branch. Clear the stale session here so that calculate() sees an
        // empty session, returns null early, and SalesObserver takes the no-basket path.
        if (empty($filtered)) {
            $subject->setOneListCalculationInCheckoutSession(null);
        }

        return $result;
    }
}
