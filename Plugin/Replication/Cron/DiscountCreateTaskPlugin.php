<?php

namespace Ls\Commerce\Plugin\Replication\Cron;

use Exception;
use \Ls\Core\Model\LSR;
use \Ls\Omni\Client\Ecommerce\Entity\Enum\ReplDiscountType;
use \Ls\Replication\Logger\Logger;
use \Ls\Replication\Model\ResourceModel\ReplDiscountSetup\CollectionFactory;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\CatalogRuleStaging\Model\Rule\Retriever;
use Magento\Staging\Model\UpdateFactory;
use Magento\Staging\Api\UpdateRepositoryInterface;
use Magento\CatalogRule\Api\Data\RuleInterfaceFactory;
use Magento\CatalogRuleStaging\Api\CatalogRuleStagingInterface;
use Magento\Staging\Model\EntityStaging;
use Magento\Staging\Model\Entity\HydratorInterface;
use Magento\CatalogRule\Model\ResourceModel\Rule as ResourceRule;
use Magento\Staging\Model\VersionManager;
use Magento\Store\Api\Data\StoreConfigInterface;
use Magento\Store\Model\ScopeInterface;

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
     * @var EntityStaging
     */
    public $entityStaging;

    /**
     * @var Retriever
     */
    public $entityRetriever;

    /**
     * @var LSR
     */
    public $lsr;

    /**
     * @var StoreConfigInterface
     */
    public $storeConfig;

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
     * @param EntityStaging $entityStaging
     * @param Retriever $entityRetriever
     * @param LSR $lsr
     * @param StoreConfigInterface $storeConfig
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
        EntityStaging $entityStaging,
        Retriever $entityRetriever,
        LSR $lsr,
        StoreConfigInterface $storeConfig,
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
        $this->entityStaging          = $entityStaging;
        $this->entityRetriever        = $entityRetriever;
        $this->lsr                    = $lsr;
        $this->storeConfig            = $storeConfig;
    }

    /**
     * After save plugin
     *
     * @param $subject
     * @param $result
     * @param $replValidation
     * @return mixed
     * @throws \DateMalformedStringException
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
     * @throws \DateMalformedStringException
     */
    public function updateValidationDateTime($name, $replValidation)
    {
        $ruleCollection = $this->ruleCollectionFactory->create();
        $ruleCollection->addFieldToFilter('name', ['like' => $name . '%']);
        $ruleCollection->addFieldToFilter('created_in', ['eq' => 1]);
        $ruleCollection->getSelect()->setPart('disable_staging_preview',true); //To prevent staging data in collection

        if ($ruleCollection->getSize() > 0) {
            foreach ($ruleCollection as $rule) {
                $ruleId = $rule->getId();
                $model  = $this->ruleFactory->create();
                $this->resourceRule->load($model, $ruleId);
                $activationUpdate = $this->updateFactory->create();

                $startTime = $replValidation->getStartDate() . " " . $replValidation->getStartTime();
                //convert start time from central timezone to UTC timezone
                // to check start time is past current time or not.
                $startTimeObj           = $this->convertTimezone($startTime);
                $startTimeUTC           = $startTimeObj->format('Y-m-d h:i:s A');
                $startTimeInUtcTimezone = $startTimeObj->getTimestamp();

                //Get Current UTC Timestamp
                $currentDateTime     = new \DateTime();
                $currentUtcTimeStamp = $currentDateTime->getTimestamp();

                if ($startTimeInUtcTimezone <= $currentUtcTimeStamp) {
                    //If startTime is a past time, adding 1 minute buffer from current timestamp
                    //for offer to start.
                    $startTimeStamp = $currentUtcTimeStamp + (1 * 60);
                    $startTimeUTC   = $this->dateTime->date('Y-m-d h:i:s A', $startTimeStamp);
                }

                $startTime = $this->timezone->date(
                    new \DateTime($startTimeUTC),
                    null,
                    false,
                    true
                )->format('Y-m-d\TH:i:s\Z');

                $activationUpdate->setName($rule->getName())
                    ->setStartTime(
                        $startTime
                    )->setIsCampaign(false);
                if ($replValidation->getEndTime()) {
                    $endTime    = $replValidation->getEndDate() . " " . $replValidation->getEndTime();
                    $endTimeObj = $this->convertTimezone($endTime);
                    $endTimeUTC = $endTimeObj->format('Y-m-d h:i:s A');
                    $endTime    = $this->timezone->date(
                        new \DateTime($endTimeUTC),
                        null,
                        false,
                        true
                    )->format('Y-m-d\TH:i:s\Z');

                    $activationUpdate->setEndTime($endTime);
                }
                try {
                    //if not the default value, schedule already exists.
                    //Unschedule the existing one to update new schedule.
                    if ($rule->getUpdatedIn() != "2147483647") {
                        $updateId = $rule->getUpdatedIn();
                        $this->versionManager->setCurrentVersionId($updateId);
                        $entity = $this->entityRetriever->getEntity($ruleId);
                        $this->entityStaging->unschedule($entity, $this->versionManager->getVersion()->getId());
                    }

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
            ->columns(['OfferNo', 'LineType', 'LineNumber'])
            ->group('OfferNo');

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

    /**
     * @return array|string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getCentralTimeZone()
    {
        return $this->lsr->getStoreConfig(
            LSR::SC_SERVICE_LCY_TIMEZONE,
            $this->lsr->getCurrentStoreId()
        );
    }

    /**
     * Convert timezone of a date time
     *
     * @param $datetime
     * @param $fromTimezone
     * @param $toTimezone
     * @return string
     * @throws \DateInvalidTimeZoneException
     * @throws \DateMalformedStringException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function convertTimezone($datetime, $fromTimezone = null, $toTimezone = null)
    {
        $fromTimezone = $fromTimezone ?: $this->getCentralTimeZone();
        $toTimezone   = $toTimezone ?: 'UTC';
        // Create a DateTime object with the original datetime and timezone
        $date = new \DateTime($datetime, new \DateTimeZone($fromTimezone));

        // Set the target timezone
        $date->setTimezone(new \DateTimeZone($toTimezone));

        // Return the formatted datetime string in the new timezone
        return $date;
    }
}