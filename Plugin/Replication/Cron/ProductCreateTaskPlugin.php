<?php

namespace Ls\Commerce\Plugin\Replication\Cron;

use \Ls\Core\Model\LSR;
use \Ls\Replication\Cron\ProductCreateTask;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\GiftCard\Model\Giftcard as GiftCardType;

class ProductCreateTaskPlugin
{
    private const DEFAULT_DATA = [
        'giftcard_type' => GiftCardType::TYPE_VIRTUAL,
        'giftcard_amounts' => '',
        'allow_open_amount' => '1',
        'open_amount_min' => '',
        'open_amount_max' => '',
        'is_redeemable' => '1',
        'lifetime' => '0',
        'allow_message' => '1',
        'gift_message_available' => '1',
        'email_template' => LSR::GIFT_CARD_RECIPIENT_TEMPLATE
    ];

    /**
     * @var LSR
     */
    public $lsr;

    /**
     * @param LSR $lsr
     */
    public function __construct(LSR $lsr)
    {
        $this->lsr = $lsr;
    }

    /**
     * After plugin to intercept getDefaultProductType
     *
     * @param ProductCreateTask $subject
     * @param $result
     * @param $item
     * @return mixed|string
     * @throws NoSuchEntityException
     */
    public function afterGetDefaultProductType(
        ProductCreateTask $subject,
        $result,
        $item
    ) {
        $giftCardIdentifier = $this->lsr->getGiftCardIdentifiers();

        return in_array($item->getNavId(), explode(',', $giftCardIdentifier)) ? Giftcard::TYPE_GIFTCARD : $result;
    }

    /**
     * After plugin to set gift card related attributes
     *
     * @param ProductCreateTask $subject
     * @param $result
     * @param $product
     * @param $item
     * @return mixed
     */
    public function afterPopulateDefaultProductAttributes(
        ProductCreateTask $subject,
        $result,
        &$product,
        $item
    ) {
        if ($product->getTypeId() == Giftcard::TYPE_GIFTCARD) {
            $product->setData(array_merge(self::DEFAULT_DATA, $product->getData()));
        }

        return $result;
    }
}
