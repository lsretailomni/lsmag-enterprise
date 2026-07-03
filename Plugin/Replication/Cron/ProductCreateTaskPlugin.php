<?php

declare(strict_types=1);

namespace Ls\Commerce\Plugin\Replication\Cron;

use Ls\Core\Model\LSR;
use Ls\Replication\Cron\ProductCreateTask;
use Ls\Replication\Helper\ReplicationHelper;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\GiftCard\Model\Giftcard as GiftCardType;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ProductCreateTaskPlugin
{
    private const DEFAULT_DATA = [
        'giftcard_type'          => GiftCardType::TYPE_VIRTUAL,
        'giftcard_amounts'       => '',
        'allow_open_amount'      => '1',
        'open_amount_min'        => '',
        'open_amount_max'        => '',
        'is_redeemable'          => '1',
        'lifetime'               => '0',
        'allow_message'          => '1',
        'gift_message_available' => '1',
        'email_template'         => LSR::GIFT_CARD_RECIPIENT_TEMPLATE
    ];

    public $lsr;

    public function __construct(
        LSR $lsr,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ReplicationHelper $replicationHelper,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        $this->lsr = $lsr;
    }

    /**
     * After plugin to intercept getDefaultProductType.
     * Returns giftcard type if item NavId is in gift_card_items_list OR VoucherGiftCardConfig.
     *
     * @throws NoSuchEntityException
     */
    public function afterGetDefaultProductType(
        ProductCreateTask $subject,
        $result,
        $item
    ) {
        $giftCardIdentifier = $this->lsr->getGiftCardIdentifiers();

        if (in_array($item->getNavId(), explode(',', $giftCardIdentifier))) {
            return Giftcard::TYPE_GIFTCARD;
        }

        if ($this->lsr->getVoucherConfigByCode($item->getNavId()) !== null) {
            return Giftcard::TYPE_GIFTCARD;
        }

        return $result;
    }

    /**
     * After plugin to set gift card related attributes on replicated products.
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

    /**
     * After execute: create giftcard products for income_account VoucherGiftCardConfig entries.
     * These entries have no corresponding LS Central item in the replication feed, so they
     * must be created directly from the admin configuration.
     *
     * The POS Data Entry configuration is website-scoped (showInWebsite="1"), so the config is
     * read per website: an entry set at default scope resolves for every website (product on all
     * websites), while an entry set on a single website resolves only for that website (product
     * on that website only). The created product is a virtual giftcard and must not carry a
     * sourcing location, so the default source item auto-assigned on save is removed afterwards.
     */
    public function afterExecute(ProductCreateTask $subject, $result, $storeData = null)
    {
        // Build: entry code => payload, and entry code => website ids where it is configured.
        $entriesByCode  = [];
        $websitesByCode = [];

        foreach ($this->storeManager->getWebsites() as $website) {
            foreach ($this->getIncomeAccountEntriesForWebsite((int)$website->getId()) as $code => $entry) {
                $entriesByCode[$code]    = $entry;
                $websitesByCode[$code][] = (int)$website->getId();
            }
        }

        foreach ($entriesByCode as $code => $entry) {
            $itemAccountNo = trim((string)($entry['item_account_no'] ?? ''));
            $lsItemId      = $itemAccountNo !== '' ? $itemAccountNo : $code;

            $criteria = $this->searchCriteriaBuilder
                ->addFilter(LSR::LS_ITEM_ID_ATTRIBUTE_CODE, $lsItemId)
                ->addFilter('type_id', Giftcard::TYPE_GIFTCARD)
                ->create();

            if ($this->productRepository->getList($criteria)->getTotalCount() > 0) {
                continue;
            }

            $websiteIds = array_values(array_unique($websitesByCode[$code] ?? []));
            if (empty($websiteIds)) {
                continue;
            }

            try {
                $product = $this->productFactory->create();
                $product->setTypeId(Giftcard::TYPE_GIFTCARD);
                $product->setAttributeSetId($product->getDefaultAttributeSetId());
                $product->setSku($code);
                $product->setName($code);
                $product->setStatus(Status::STATUS_ENABLED);
                $product->setVisibility(Visibility::VISIBILITY_BOTH);
                $product->setPrice(0);
                $product->setCustomAttribute(LSR::LS_ITEM_ID_ATTRIBUTE_CODE, $lsItemId);
                $product->setWebsiteIds($websiteIds);
                $product->setData(array_merge(self::DEFAULT_DATA, $product->getData()));
                $product->setStockData([
                    'use_config_manage_stock' => 0,
                    'manage_stock'            => 0,
                    'is_in_stock'             => 1,
                ]);

                $this->productRepository->save($product);

                // Virtual giftcard must not carry a sourcing location. Saving with legacy stock
                // data makes MSI auto-assign the default source; remove that source item.
                $this->removeDefaultSourceItem($code);

                $this->logger->info(sprintf(
                    'Created giftcard product for income_account VoucherGiftCardConfig entry: %s',
                    $code
                ));
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Failed to create giftcard product for income_account entry "%s": %s',
                    $code,
                    $e->getMessage()
                ));
            }
        }

        return $result;
    }

    /**
     * Read the POS Data Entry configuration for a website and return the income_account entries
     * keyed by their (non-empty) code. Website-scope resolution returns the website override when
     * present, otherwise the default value.
     *
     * @param int $websiteId
     * @return array<string, array>
     */
    private function getIncomeAccountEntriesForWebsite(int $websiteId): array
    {
        $config = $this->scopeConfig->getValue(
            LSR::LS_VOUCHER_GIFT_CARD_CONFIGURATION,
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        );

        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        if (!is_array($config)) {
            return [];
        }

        $entries = [];
        foreach ($config as $entry) {
            if (($entry['item_type'] ?? '') !== 'income_account') {
                continue;
            }
            $code = trim((string)($entry['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $entries[$code] = $entry;
        }

        return $entries;
    }

    /**
     * Remove the default source item auto-assigned to the product on save, so the virtual
     * giftcard does not carry a sourcing location.
     *
     * @param string $sku
     * @return void
     */
    private function removeDefaultSourceItem(string $sku): void
    {
        try {
            $defaultSourceCode = $this->replicationHelper->getDefaultSourceObject()->create()->getCode();
            $this->replicationHelper->deleteSourceItemsBySku($defaultSourceCode, $sku);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Failed to remove default source item for income_account product "%s": %s',
                $sku,
                $e->getMessage()
            ));
        }
    }
}