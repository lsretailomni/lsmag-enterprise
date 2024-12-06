<?php

namespace Ls\Commerce\Plugin\Replication\Cron;

use \Ls\Omni\Client\Ecommerce\Entity\Enum\ReplDiscountType;
use \Ls\Replication\Model\ResourceModel\ReplDiscountSetup\CollectionFactory;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\GiftCard\Model\Giftcard as GiftCardType;
use Magento\Staging\Model\UpdateFactory;
use Magento\Staging\Api\UpdateRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory;
use Magento\CatalogRuleStaging\Api\CatalogRuleStagingInterface;
use Magento\Staging\Model\EntityStaging;
use Magento\Staging\Model\Entity\HydratorInterface;
use Magento\CatalogRule\Model\ResourceModel\Rule as ResourceRule;
use Magento\Staging\Model\VersionManager;

/**
 * DiscountCreateTaskPlugin
 */
class DiscountCreateTaskPlugin
{

    /**
     * @var CollectionFactory
     */
    public $replDiscountCollection;

    /**
     * @var UpdateFactory 
     */
    public $updateFactory;
    /**
     * @var UpdateRepositoryInterface 
     */
    public $updateRepository;

    /**
     * @var RuleCollectionFactory 
     */
    public $ruleCollectionFactory;

    /**
     * @var CatalogRuleStagingInterface 
     */
    public $catalogRuleStaging;

    /**
     * @var ResourceRule 
     */
    public $resourceRule;

    /**
     * @var RuleFactory 
     */
    public $ruleFactory;

    /**
     * @var VersionManager 
     */
    public $versionManager;

    public function __construct(
        CollectionFactory $replDiscountCollection,
        UpdateFactory $updateFactory,
        UpdateRepositoryInterface $updateRepository,
        RuleCollectionFactory $ruleCollectionFactory,
        CatalogRuleStagingInterface $catalogRuleStaging,
        ResourceRule $resourceRule,
        RuleFactory $ruleFactory,
        VersionManager $versionManager
    ) {
        $this->replDiscountCollection = $replDiscountCollection;
        $this->updateFactory          = $updateFactory;
        $this->updateRepository       = $updateRepository;
        $this->ruleCollectionFactory  = $ruleCollectionFactory;
        $this->catalogRuleStaging     = $catalogRuleStaging;
        $this->resourceRule           = $resourceRule;
        $this->ruleFactory            = $ruleFactory;
        $this->versionManager         = $versionManager;
    }

    /**
     * @param $subject
     * @param $result
     * @param $replValidation
     * @return mixed
     */
    public function afterSave(
        $subject,
        $result,
        $replValidation
    ) {
        if ($replValidation->getStartTime() == null || $replValidation->getProcessed() == 0) {
            return $result;
        }
        $navId          = $replValidation->getNavId();
        $discountOffers = $this->getDiscountsByValidationPeriod($navId);

        foreach ($discountOffers as $discountOffer) {
            $this->updateValidationDateTime($discountOffer->getOfferNo(), $replValidation);
        }

        return $result;
    }

    /**
     * Update staging
     *
     * @param $name
     * @param $replValidation
     * @return void
     */
    public function updateValidationDateTime($name, $replValidation)
    {
        $ruleCollection = $this->ruleCollectionFactory->create();
        $ruleCollection->addFieldToFilter('name', ['like' => $name . '%']);

        if ($ruleCollection->getSize() > 0) {
            foreach ($ruleCollection as $rule) {
                $ruleId = $rule->getId();
                $rowId  = $rule->getRowId();
                $model  = $this->ruleFactory->create();
                $this->resourceRule->load($model, $ruleId);
                $ruleCollection = $this->ruleCollectionFactory->create();
                $ruleCollection->addFieldToFilter('row_id', $rowId);

                $activationUpdate = $this->updateFactory->create();
                $activationUpdate->setName($rule->getName())
                    ->setStartTime(
                        $replValidation->getStartDate() . " " . $replValidation->getStartTime()
                    )->setIsCampaign(false);
                if ($replValidation->getEndTime()) {
                    $activationUpdate->setEndTime($replValidation->getEndDate() . " " . $replValidation->getEndTime());
                }
                $activationUpdate = $this->updateRepository->save($activationUpdate);
                $this->versionManager->setCurrentVersionId($activationUpdate->getId());
                $this->catalogRuleStaging->schedule($model, $activationUpdate->getId());
            }
        }
    }

    /**
     * Fetch offers based on validation period
     * 
     * @param $navId
     * @return array|\Ls\Replication\Model\ResourceModel\ReplDiscountSetup\Collection
     */
    public function getDiscountsByValidationPeriod($navId)
    {
        $publishedOfferIds = [];
        $collection        = $this->replDiscountCollection->create();
        $collection->addFieldToFilter('ValidationPeriodId', $navId);
        $collection->getSelect()
            ->columns(['OfferNo', 'LineType', 'LineNumber']);

        $collection->addFieldToFilter(
            'Type',
            ReplDiscountType::DISC_OFFER
        );

        $collection->addFieldToFilter(
            'Enabled',
            1
        );

        $collection->addFieldToFilter(
            'IsDeleted',
            0
        );

        $query = $collection->getSelect()->__toString();
        if ($collection->getSize() > 0) {
            return $collection;
        }
        return $publishedOfferIds;
    }
}
