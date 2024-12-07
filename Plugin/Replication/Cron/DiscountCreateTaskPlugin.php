<?php

namespace Ls\Commerce\Plugin\Replication\Cron;

use Exception;
use \Ls\Omni\Client\Ecommerce\Entity\Enum\ReplDiscountType;
use \Ls\Replication\Logger\Logger;
use \Ls\Replication\Model\ResourceModel\ReplDiscountSetup\CollectionFactory;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
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

    /**
     * @var Logger
     */
    public $logger;

    /**
     * @var DateTime
     */
    public $dateTime;

    /**
     * @var TimezoneInterface
     */
    public $timezone;

    /**
     * @param CollectionFactory $replDiscountCollection
     * @param UpdateFactory $updateFactory
     * @param UpdateRepositoryInterface $updateRepository
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param CatalogRuleStagingInterface $catalogRuleStaging
     * @param ResourceRule $resourceRule
     * @param RuleFactory $ruleFactory
     * @param VersionManager $versionManager
     * @param DateTime $dateTime
     * @param TimezoneInterface $timezone
     * @param Logger $logger
     */
    public function __construct(
        CollectionFactory $replDiscountCollection,
        UpdateFactory $updateFactory,
        UpdateRepositoryInterface $updateRepository,
        RuleCollectionFactory $ruleCollectionFactory,
        CatalogRuleStagingInterface $catalogRuleStaging,
        ResourceRule $resourceRule,
        RuleFactory $ruleFactory,
        VersionManager $versionManager,
        DateTime $dateTime,
        TimezoneInterface $timezone,
        Logger $logger
    ) {
        $this->replDiscountCollection = $replDiscountCollection;
        $this->updateFactory          = $updateFactory;
        $this->updateRepository       = $updateRepository;
        $this->ruleCollectionFactory  = $ruleCollectionFactory;
        $this->catalogRuleStaging     = $catalogRuleStaging;
        $this->resourceRule           = $resourceRule;
        $this->ruleFactory            = $ruleFactory;
        $this->versionManager         = $versionManager;
        $this->logger                 = $logger;
        $this->timezone               = $timezone;
        $this->dateTime               = $dateTime;
    }

    /**
     * After save plugin
     *
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
        $scopeId        = $replValidation->getScopeId();
        $discountOffers = $this->getDiscountsByValidationPeriod($navId, $scopeId);

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
                $model  = $this->ruleFactory->create();
                $this->resourceRule->load($model, $ruleId);
                $activationUpdate = $this->updateFactory->create();

                $startTime        = $replValidation->getStartDate() . " " . $replValidation->getStartTime();
                $startTimeStamp   = strtotime($startTime);
                $currentTimeStamp = $this->dateTime->gmtTimestamp();

                if ($startTimeStamp <= $currentTimeStamp) {
                    //If startTime is a past time, adding 15 minutes buffer from current timestamp
                    //for offer to start.
                    $startTimeStamp = $currentTimeStamp + (15 * 60);
                    $startTime      = $this->dateTime->date('Y-m-d H:i:s A', $startTimeStamp);
                }

                $startTime = $this->timezone->date(new \DateTime($startTime),null,true,true)->format('Y-m-d\TH:i:s\Z');

                $activationUpdate->setName($rule->getName())
                    ->setStartTime(
                        $startTime
                    )->setIsCampaign(false);
                if ($replValidation->getEndTime()) {
                    $endTime = $replValidation->getEndDate() . " " . $replValidation->getEndTime();
                    $endTime = $this->timezone->date(new \DateTime($endTime),null,true,true)->format('Y-m-d\TH:i:s\Z');

                    $activationUpdate->setEndTime($endTime);
                }
                try {
                    $activationUpdate = $this->updateRepository->save($activationUpdate);
                    $this->versionManager->setCurrentVersionId($activationUpdate->getId());
                    $this->catalogRuleStaging->schedule($model, $activationUpdate->getId());
                } catch (Exception $e) {
                    $this->logger->debug($e->getMessage());
                }

            }
        }
    }

    /**
     * Fetch offers based on validation period
     *
     * @param $navId
     * @param $scopeId
     * @return array|\Ls\Replication\Model\ResourceModel\ReplDiscountSetup\Collection
     */
    public function getDiscountsByValidationPeriod($navId, $scopeId)
    {
        $publishedOfferIds = [];
        $collection        = $this->replDiscountCollection->create();
        $collection->addFieldToFilter('scope_id', $scopeId);
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