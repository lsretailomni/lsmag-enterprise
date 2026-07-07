<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\ItemHelper;
use \Ls\Omni\Observer\SalesObserver;
use Magento\Customer\Model\Address\AbstractAddress;
use Magento\Framework\Event\Observer;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;

/**
 * After plugin on Ls\Omni\Observer\SalesObserver::execute.
 *
 * income_account giftcard lines are removed from the basket sent to LS Central by
 * Ls\Commerce\Plugin\BasketHelperPlugin (Central's EcomCalculateBasket does not support
 * GL/income-expense lines). As a result the Central-derived totals that SalesObserver writes
 * onto the quote address total do not include the open amount the customer entered for those
 * items, and the cart/checkout subtotal and grand total are short by that amount.
 *
 * This plugin runs immediately after SalesObserver::execute returns — on the same Observer
 * instance and the same Total object SalesObserver just mutated — and adds the excluded
 * income_account amounts back onto the subtotal / subtotalInclTax / grandTotal (and the
 * base-currency variants, via ItemHelper::convertToBaseCurrency).
 *
 * It mirrors SalesObserver's address-type guard so the amount is added on exactly the single
 * pass where SalesObserver set the Central-derived total. When the cart contains only
 * income_account items the basket sent to Central is empty (no lines survive BasketHelperPlugin),
 * so no basket session is saved and SalesObserver does not set Central-derived totals — but the
 * native subtotal collector is also bypassed when LSR is active, so the correction must still run
 * to produce a non-zero subtotal. The $incomeAccountTotal <= 0 guard is sufficient protection.
 *
 * Per-item custom_price is set in-memory only — never persisted to DB. Persisting would cause
 * Magento's SubtotalCollector to include the income_account amount natively on the next
 * collectTotals() call, and SalesObserverPlugin would then add it a second time (doubling the
 * displayed order balance). The in-memory value is sufficient for cart/checkout display.
 */
class SalesObserverPlugin
{
    public function __construct(
        private readonly LSR $lsr,
        private readonly ItemHelper $itemHelper
    ) {
    }

    /**
     * Add removed income_account amounts back onto the quote totals.
     *
     * @param SalesObserver $subject
     * @param mixed $result SalesObserver::execute returns $this
     * @param Observer $observer
     * @return mixed
     */
    public function afterExecute(
        SalesObserver $subject,
        mixed $result,
        Observer $observer
    ): mixed {
        $event              = $observer->getEvent();
        $quote              = $event->getQuote();
        $total              = $event->getTotal();
        $shippingAssignment = $event->getShippingAssignment();

        $addressType = $shippingAssignment->getShipping()->getAddress()->getAddressType();

        // Mirror SalesObserver's address-type guard: only apply on the single pass where
        // SalesObserver overwrites the Central-derived totals.
        $appliesToAddress = ($quote->isVirtual() && $addressType == AbstractAddress::TYPE_BILLING)
            || (!$quote->isVirtual() && $addressType == AbstractAddress::TYPE_SHIPPING);
        if (!$appliesToAddress) {
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

        // Detect which branch SalesObserver::execute() took.
        //
        // Basket branch: SalesObserver replaced total.subtotal with Central's netamount
        //   (which excludes income_account lines). We must add the full giftcard amount.
        //
        // No-basket branch: SalesObserver did NOT touch total; the native SubtotalCollector
        //   value is still in total.subtotal. For a fixed-price giftcard the collector already
        //   contributed the item's catalog price, so we must add only the difference
        //   (giftcard_amount − catalog row total) to avoid doubling.
        $isBasketBranch = !empty($subject->basketHelper->getBasketSessionValue());

        // Two separate accumulators:
        //
        // $inclTaxTotal  — the full giftcard row amount for every income_account item.
        //   Used for subtotalInclTax and the base-currency incl-tax variants.  LSR bypasses
        //   the native Magento Tax\Subtotal collector, so subtotalInclTax is never populated
        //   by any collector — the full amount must always be added here.
        //
        // $subtotalDelta — net adjustment for subtotal/grandTotal. In the no-basket branch
        //   SubtotalCollector may have already contributed the item's catalog price; we
        //   subtract that native contribution to avoid doubling.  In the basket branch
        //   Central's netamount excludes income_account items entirely, so the native
        //   contribution is zero and the full giftcard amount is added.
        $inclTaxTotal   = 0.0;
        $subtotalDelta  = 0.0;
        $foundIncomeAccount = false;

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

            $foundIncomeAccount = true;
            $qty                = (float) $quoteItem->getQty() ?: 1.0;
            $newRowTotal        = $amount * $qty;
            $nativeRowTotal     = $isBasketBranch ? 0.0 : (float) $quoteItem->getRowTotal();

            $inclTaxTotal  += $newRowTotal;
            $subtotalDelta += $newRowTotal - $nativeRowTotal;

            // Set per-item prices in-memory so the cart price column displays correctly. This
            // mirrors ItemHelperPlugin but runs unconditionally — ItemHelperPlugin only fires
            // when compareQuoteItemsWithOrderLinesAndSetRelatedAmounts is called (non-empty
            // basket), which is skipped for income_account-only carts.
            //
            // Intentionally NOT saved to DB: persisting custom_price causes SubtotalCollector
            // to include the amount natively on the next collectTotals() call, after which this
            // plugin would add it again — doubling the displayed order balance.
            $quoteItem
                ->setCustomPrice($amount)
                ->setOriginalCustomPrice($amount)
                ->setRowTotal($newRowTotal)
                ->setBaseRowTotal($newRowTotal)
                ->setRowTotalInclTax($newRowTotal)
                ->setBaseRowTotalInclTax($newRowTotal);
            $quoteItem->getProduct()->setIsSuperMode(true);
            // Clear the calculation_price cache so the cart price column reflects custom_price.
            // The cache is written by tax/subtotal collectors (which run before this plugin) and
            // would otherwise hold 0 (the catalog price of an open-amount giftcard).
            $quoteItem->unsetData('calculation_price');
            // Set price_incl_tax so stores with tax-inclusive price display show the correct
            // amount. base_price_incl_tax is intentionally left alone — setting it to the
            // base-currency equivalent here would cause some renderers to display that (smaller)
            // value as the unit price instead of the display-currency amount.
            $quoteItem->setData('price_incl_tax', $amount);
        }

        if (!$foundIncomeAccount) {
            return $result;
        }

        // Convert to base currency once (proportional scaling avoids a second API call).
        $baseInclTaxTotal  = $this->itemHelper->convertToBaseCurrency($inclTaxTotal);
        $baseSubtotalDelta = $inclTaxTotal > 0.001
            ? $baseInclTaxTotal * ($subtotalDelta / $inclTaxTotal)
            : 0.0;

        // GL/income-expense entries are tax-exempt; tax amounts are intentionally untouched.
        //
        // subtotalInclTax: always add the full giftcard amount regardless of the subtotal
        // delta. LSR bypasses the native Tax\Subtotal collector so this field is always 0
        // coming into this plugin; omitting the update leaves the Subtotal (Inc. Tax) row
        // blank even when subtotal and grand total are correct.
        $total->setSubtotalInclTax($total->getSubtotalInclTax() + $inclTaxTotal)
            ->setBaseSubtotalInclTax($total->getBaseSubtotalInclTax() + $baseInclTaxTotal)
            ->setBaseSubtotalTotalInclTax($total->getBaseSubtotalTotalInclTax() + $baseInclTaxTotal);

        if (abs($subtotalDelta) >= 0.001) {
            $total->setSubtotal($total->getSubtotal() + $subtotalDelta)
                ->setBaseSubtotal($total->getBaseSubtotal() + $baseSubtotalDelta)
                ->setGrandTotal($total->getGrandTotal() + $subtotalDelta)
                ->setBaseGrandTotal($total->getBaseGrandTotal() + $baseSubtotalDelta);
        }

        return $result;
    }
}
