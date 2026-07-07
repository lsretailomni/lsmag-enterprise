<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\OrderHelper;

/**
 * After plugin on Customer\Block\Order\Items::getItems() variants.
 *
 * All three order-detail item blocks (Items, Custom\Items, Custom\Hyva\Items) filter out
 * sales-doc lines with EntryType == 1 (G/L Account) and EntryType == 4. LS Central returns
 * income_account giftcard order lines with EntryType == 1 because those lines post directly
 * to a GL account, so they are silently excluded from the customer order detail view.
 *
 * This plugin re-adds any line whose NUMBER matches a configured income_account item_account_no
 * so those items are visible to the customer alongside regular product lines.
 *
 * It also replaces the GL-account description (which comes from Central and is typically the
 * account number or a cryptic ledger label) and the NUMBER field with the Magento product name
 * and SKU respectively, so the customer sees recognisable product information.
 */
class OrderItemsPlugin
{
    public function __construct(
        private readonly LSR $lsr,
        private readonly OrderHelper $orderHelper
    ) {
    }

    /**
     * Re-include income_account lines that were removed by the EntryType filter.
     *
     * Works for all three block variants:
     *   - Ls\Customer\Block\Order\Items::getItems($trans)          → $trans is the transaction DataObject
     *   - Ls\Customer\Block\Order\Custom\Items::getItems()         → $trans is null
     *   - Ls\Customer\Block\Order\Custom\Hyva\Items::getItems()    → $trans is null
     *
     * @param object     $subject The block instance
     * @param array      $result  The already-filtered lines
     * @param mixed|null $trans   Transaction DataObject (only for Items::getItems($trans))
     * @return array
     */
    public function afterGetItems(object $subject, array $result, mixed $trans = null): array
    {
        // Build both mappings from config:
        //   $incomeAccountNos[item_account_no] = code (Magento SKU)
        $incomeAccountNos = [];
        foreach ($this->lsr->getVoucherGiftCardConfiguration() as $entry) {
            if (($entry['item_type'] ?? '') === 'income_account'
                && !empty($entry['item_account_no'])
                && !empty($entry['code'])
            ) {
                $incomeAccountNos[(string)$entry['item_account_no']] = (string)$entry['code'];
            }
        }

        if (empty($incomeAccountNos)) {
            return $result;
        }

        $order = $subject->getOrder(true);
        if (!$order) {
            return $result;
        }

        // Determine the current documentId.
        // Items::getItems($trans) receives the transaction; others read from the request.
        $documentId = $trans !== null
            ? (string)$trans->getDocumentId()
            : (string)$subject->getRequest()->getParam('order_id');

        if ($documentId === '') {
            return $result;
        }

        $allLines = $order->getLscMemberSalesDocLine();
        $allLines = $allLines && is_array($allLines) ? $allLines :
            ($allLines && !is_array($allLines) ? [$allLines] : []);

        // Build SKU → name/SKU map from the Magento order for display substitution.
        $skuToName = [];
        $magentoOrder = $this->orderHelper->getGivenValueFromRegistry('current_mag_order');
        if ($magentoOrder) {
            foreach ($magentoOrder->getAllVisibleItems() as $orderItem) {
                $skuToName[(string)$orderItem->getSku()] = [
                    'name' => (string)$orderItem->getName(),
                    'sku'  => (string)$orderItem->getSku(),
                ];
            }
        }

        // Index already-present numbers so we don't add duplicates.
        $presentNumbers = [];
        foreach ($result as $line) {
            $presentNumbers[(string)$line->getNumber()] = true;
        }

        foreach ($allLines as $line) {
            $lineNumber = (string)$line->getNumber();
            if ((string)$line->getDocumentId() !== $documentId) {
                continue;
            }
            if (!isset($incomeAccountNos[$lineNumber])) {
                continue;
            }
            if (isset($presentNumbers[$lineNumber])) {
                continue;
            }

            // Substitute Central's GL description / account number with the Magento
            // product name and SKU so the customer sees recognisable product information.
            $magSku = $incomeAccountNos[$lineNumber]; // = config 'code' = Magento SKU
            if (isset($skuToName[$magSku])) {
                $line->setDescription($skuToName[$magSku]['name']);
                $line->setNumber($skuToName[$magSku]['sku']);
            }

            $result[] = $line;
            $presentNumbers[$lineNumber] = true;
        }

        return $result;
    }
}
