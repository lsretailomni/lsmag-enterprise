<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Client\CentralEcommerce\Entity\CustomerOrderCreateCOLineV6;
use \Ls\Omni\Client\CentralEcommerce\Entity\RootCustomerOrderCreateV6;
use \Ls\Omni\Client\CentralEcommerce\Entity\RootMobileTransaction;
use \Ls\Omni\Helper\OrderHelper;
use Magento\Sales\Model\Order;

class OrderHelperPlugin
{
    private const LINE_TYPE_INCOME_EXPENSE = 4;

    public function __construct(
        private readonly LSR $lsr
    ) {
    }

    /**
     * After plugin on prepareOrder: append IncomeExpense (LINE_TYPE=4) lines for
     * income_account VoucherGiftCardConfig entries.
     *
     * Income_account items are excluded from EcomCalculateBasket (by BasketHelperPlugin), so
     * the basket response contains no IncomeExpense lines. We therefore build the order lines
     * directly from the Magento order items using the price the customer paid.
     */
    public function afterPrepareOrder(
        OrderHelper $subject,
        mixed $result,
        Order $order,
        RootMobileTransaction $oneListCalculateResponse
    ): mixed {
        // Hospitality industry overrides prepareOrder via an around-plugin and returns a
        // different root type — bail out early so we don't cause a fatal type error.
        if (!($result instanceof RootCustomerOrderCreateV6)) {
            return $result;
        }

        // Map product SKU (= 'code' field) → item_account_no for income_account entries.
        $codeToAccountNo = [];
        foreach ($this->lsr->getVoucherGiftCardConfiguration() as $entry) {
            if (($entry['item_type'] ?? '') === 'income_account'
                && !empty($entry['item_account_no'])
                && !empty($entry['code'])
            ) {
                $codeToAccountNo[(string)$entry['code']] = (string)$entry['item_account_no'];
            }
        }

        if (empty($codeToAccountNo)) {
            return $result;
        }

        $storeId        = current((array)$oneListCalculateResponse->getMobiletransaction())->getStoreid();
        $shippingMethod = $order->getShippingMethod(true);
        $isClickCollect = $shippingMethod !== null && $shippingMethod->getData('carrier_code') === 'clickandcollect';
        $storeNo        = $isClickCollect ? $order->getPickupStore() : $storeId;

        $existingLines = (array)($result->getCustomerordercreatecolinev6() ?? []);

        // Build per-item data and collect lsrItemIds in one pass.
        //
        // When the basket session is absent (income_account-only cart), OrderObserver falls back
        // to BasketHelper::formulateCentralOrderRequestFromMagentoOrder(), which builds
        // MobileTransactionLines from order items with NUMBER = product->lsrItemId (via
        // ItemHelper::getComparisonValues). The base prepareOrder then converts each linetype=0
        // MobileTransactionLine into a COLine — producing a spurious LINE_TYPE=0 entry for the
        // income_account item alongside the LINE_TYPE=4 entry we add below.
        // We collect lsrItemIds here so we can strip those phantom lines.
        $incomeAccountLsrItemIds = [];
        $itemsToAdd = [];
        foreach ($order->getAllItems() as $orderItem) {
            if ($orderItem->getParentItemId() !== null) {
                continue;
            }
            $sku = (string)$orderItem->getSku();
            if (!isset($codeToAccountNo[$sku])) {
                continue;
            }

            $product = $orderItem->getProduct();
            if ($product !== null) {
                $lsrItemId = (string)$product->getData(LSR::LS_ITEM_ID_ATTRIBUTE_CODE);
                if ($lsrItemId !== '') {
                    $incomeAccountLsrItemIds[$lsrItemId] = true;
                }
            }

            $itemsToAdd[] = [
                'itemAccountNo' => $codeToAccountNo[$sku],
                'price'         => (float)$orderItem->getPrice(),
                'qty'           => (float)$orderItem->getQtyOrdered() ?: 1.0,
            ];
        }

        if (empty($itemsToAdd)) {
            return $result;
        }

        // Remove phantom LINE_TYPE=0 lines for income_account items.
        if (!empty($incomeAccountLsrItemIds)) {
            $existingLines = array_values(array_filter(
                $existingLines,
                static fn($line) => !isset($incomeAccountLsrItemIds[(string)$line->getData(CustomerOrderCreateCOLineV6::NUMBER)])
            ));
        }

        // Pick a line number that won't conflict with remaining lines.
        $maxLineNo = 0;
        foreach ($existingLines as $line) {
            $lineNo = (int)$line->getData(CustomerOrderCreateCOLineV6::LINE_NO);
            if ($lineNo > $maxLineNo) {
                $maxLineNo = $lineNo;
            }
        }

        $linesAdded = 0;
        foreach ($itemsToAdd as $item) {
            $netAmount = $item['price'] * $item['qty'];
            $lineNo    = $maxLineNo + (++$linesAdded * 10000);

            $coLine = $subject->createInstance(CustomerOrderCreateCOLineV6::class);
            $coLine->addData([
                CustomerOrderCreateCOLineV6::LINE_NO           => $lineNo,
                CustomerOrderCreateCOLineV6::LINE_TYPE         => self::LINE_TYPE_INCOME_EXPENSE,
                CustomerOrderCreateCOLineV6::NUMBER            => $item['itemAccountNo'],
                CustomerOrderCreateCOLineV6::PRICE             => $item['price'],
                CustomerOrderCreateCOLineV6::NET_PRICE         => $item['price'],
                CustomerOrderCreateCOLineV6::QUANTITY          => $item['qty'],
                CustomerOrderCreateCOLineV6::SERVICE_ITEM      => false,
                CustomerOrderCreateCOLineV6::DISCOUNT_AMOUNT   => 0.0,
                CustomerOrderCreateCOLineV6::DISCOUNT_PERCENT  => 0.0,
                CustomerOrderCreateCOLineV6::NET_AMOUNT        => $netAmount,
                CustomerOrderCreateCOLineV6::VAT_AMOUNT        => 0.0,
                CustomerOrderCreateCOLineV6::AMOUNT            => $netAmount,
                CustomerOrderCreateCOLineV6::CLICK_AND_COLLECT => $isClickCollect,
                CustomerOrderCreateCOLineV6::STORE_NO         => $storeNo,
            ]);

            $existingLines[] = $coLine;
        }

        $result->setCustomerordercreatecolinev6($existingLines);

        return $result;
    }
}
