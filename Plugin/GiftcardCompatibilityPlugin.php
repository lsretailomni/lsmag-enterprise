<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin;

use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\ItemHelper as ItemHelperSubject;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;

/*
 *
 */
class GiftcardCompatibilityPlugin
{
    public function __construct(
        private readonly LSR $lsr
    ) {
    }

    /**
     * Around plugin for isValid: guards against the gift-card identifier branch calling
     * getProduct() on a null result.
     *
     * isValid() has two branches:
     *   (A) gift-card branch: $line->getNumber() == $quoteItem->getProduct()->getLsrItemId()
     *   (B) standard branch:  itemId == line.NUMBER && variantId == line.VariantCode
     *
     * In the order-history render path (type=2), $quoteItem is an order-doc-line DataObject
     * from Central (LscMemberSalesDocLine). DataObject's magic __call() resolves getProduct()
     * to getData('product') which returns null because that key is never set on order lines.
     * If the item's lsrItemId is in the gift-card identifier list, branch (A) fires and
     * crashes with "Call to a member function getLsrItemId() on null".
     *
     * We short-circuit to false when the null is detected: no match means no discount info
     * for the line, which is the correct result for income/expense account items that carry
     * no Central-calculated discount rows.
     *
     * @param ItemHelperSubject $subject
     * @param callable $proceed
     * @param mixed $quoteItem
     * @param mixed $line
     * @param mixed $itemId
     * @param mixed $variantId
     * @param mixed $uom
     * @param mixed $baseUnitOfMeasure
     * @return bool
     */
    public function aroundIsValid(
        ItemHelperSubject $subject,
        callable $proceed,
        mixed $quoteItem,
        mixed $line,
        mixed $itemId,
        mixed $variantId,
        mixed $uom,
        mixed $baseUnitOfMeasure
    ): bool {
        if (in_array($itemId, explode(',', $this->lsr->getGiftCardIdentifiers() ?? ''))
            && $quoteItem->getProduct() === null
        ) {
            return false;
        }

        return $proceed($quoteItem, $line, $itemId, $variantId, $uom, $baseUnitOfMeasure);
    }

    /**
     * Around plugin for getItemAttributesGivenQuoteItem to add Enterprise giftcard-type check only when available.
     * For giftcard products with a VoucherGiftCardConfig entry, substitutes item_account_no as the item ID
     * so LS Central basket calculation uses the configured selling item/account number.
     *
     * @param ItemHelperSubject $subject
     * @param callable $proceed
     * @param mixed $quoteItem
     * @return array|null
     */
    public function aroundGetItemAttributesGivenQuoteItem(
        ItemHelperSubject $subject,
        callable $proceed,
        mixed $quoteItem
    ): ?array {
        $result = $proceed($quoteItem);

        if (!is_array($result) || $quoteItem->getProductType() !== Giftcard::TYPE_GIFTCARD) {
            return $result;
        }

        $itemId = (string)($result[0] ?? '');
        if ($itemId === '') {
            return $result;
        }

        $config = $this->lsr->getVoucherConfigByCode($itemId);
        if ($config !== null && !empty($config['item_account_no'])) {
            $result[0] = $config['item_account_no'];
        }

        return $result;
    }
}