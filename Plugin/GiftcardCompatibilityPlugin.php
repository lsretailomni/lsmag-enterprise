<?php

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\ItemHelper as ItemHelperSubject;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Catalog\Model\Product\Type;

class GiftcardCompatibilityPlugin
{
    /**
     * Around plugin for getItemAttributesGivenQuoteItem to add Enterprise giftcard-type check only when available.     
     *
     * @param ItemHelperSubject $subject
     * @param callable $proceed
     * @param mixed $quoteItem
     * @return array|null
     */
    public function aroundGetItemAttributesGivenQuoteItem(ItemHelperSubject $subject, callable $proceed, $quoteItem)
    {
        if ($quoteItem->getProductType() != Type::DEFAULT_TYPE &&
            $quoteItem->getProductType() != Giftcard::TYPE_GIFTCARD
        ) {
            $quoteItem = current($quoteItem->getChildren());
        }

        $itemId    = $quoteItem->getProduct()->getData(LSR::LS_ITEM_ID_ATTRIBUTE_CODE);
        $variantId = $quoteItem->getProduct()->getData(LSR::LS_VARIANT_ID_ATTRIBUTE_CODE);
        $uom       = $quoteItem->getProduct()->getData('uom');
        $barCode   = $quoteItem->getProduct()->getData('barcode');
        $uomQty    = $quoteItem->getProduct()->getData(LSR::LS_UOM_ATTRIBUTE_QTY);

        return [$itemId, $variantId, $uom, $barCode, $uomQty];
    }
}

