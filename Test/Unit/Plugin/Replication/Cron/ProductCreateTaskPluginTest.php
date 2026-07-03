<?php

declare(strict_types=1);

namespace Ls\Commerce\Test\Unit\Plugin\Replication\Cron;

use Ls\Commerce\Plugin\Replication\Cron\ProductCreateTaskPlugin;
use Ls\Core\Model\LSR;
use Ls\Replication\Cron\ProductCreateTask;
use Ls\Replication\Helper\ReplicationHelper;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResults;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ProductCreateTaskPlugin::afterExecute().
 *
 * Covers the two fixes for #84035:
 *  - website-scoped creation (product attached only to websites where the income_account
 *    entry is configured), and
 *  - removal of the auto-assigned default source item so the virtual giftcard carries no
 *    sourcing location.
 */
class ProductCreateTaskPluginTest extends TestCase
{
    private ProductCreateTaskPlugin $plugin;

    /** @var LSR&MockObject */
    private $lsr;

    /** @var ProductInterfaceFactory&MockObject */
    private $productFactory;

    /** @var ProductRepositoryInterface&MockObject */
    private $productRepository;

    /** @var SearchCriteriaBuilder&MockObject */
    private $searchCriteriaBuilder;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var ReplicationHelper&MockObject */
    private $replicationHelper;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var ProductCreateTask&MockObject */
    private $subject;

    protected function setUp(): void
    {
        $this->lsr                   = $this->createMock(LSR::class);
        $this->productFactory        = $this->createMock(ProductInterfaceFactory::class);
        $this->productRepository     = $this->createMock(ProductRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->storeManager          = $this->createMock(StoreManagerInterface::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->replicationHelper     = $this->createMock(ReplicationHelper::class);
        $this->scopeConfig           = $this->createMock(ScopeConfigInterface::class);
        $this->subject               = $this->createMock(ProductCreateTask::class);

        // SearchCriteriaBuilder is fluent.
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->plugin = new ProductCreateTaskPlugin(
            $this->lsr,
            $this->productFactory,
            $this->productRepository,
            $this->searchCriteriaBuilder,
            $this->storeManager,
            $this->logger,
            $this->replicationHelper,
            $this->scopeConfig
        );
    }

    /**
     * Build a website mock.
     */
    private function website(int $id): WebsiteInterface
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn($id);

        return $website;
    }

    /**
     * Stub the "no existing product" search result so creation proceeds.
     */
    private function stubNoExistingProduct(): void
    {
        $searchResults = $this->createMock(SearchResults::class);
        $searchResults->method('getTotalCount')->willReturn(0);
        $this->productRepository->method('getList')->willReturn($searchResults);
    }

    /**
     * Build a product mock whose getData() returns [] so array_merge stays valid.
     *
     * @return Product&MockObject
     */
    private function productMock()
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->addMethods(['setWebsiteIds']) // magic setter (setData('website_ids'))
            ->onlyMethods([
                'getData', 'getDefaultAttributeSetId', 'setTypeId', 'setAttributeSetId',
                'setSku', 'setName', 'setStatus', 'setVisibility', 'setPrice',
                'setCustomAttribute', 'setData', 'setStockData',
            ])
            ->getMock();
        $product->method('getData')->willReturn([]);
        $product->method('getDefaultAttributeSetId')->willReturn(4);

        return $product;
    }

    /**
     * Stub the default source provider chain: getDefaultSourceObject()->create()->getCode().
     */
    private function stubDefaultSource(string $code = 'default'): void
    {
        $source = $this->getMockBuilder(\stdClass::class)->addMethods(['getCode'])->getMock();
        $source->method('getCode')->willReturn($code);
        $factory = $this->getMockBuilder(\stdClass::class)->addMethods(['create'])->getMock();
        $factory->method('create')->willReturn($source);
        $this->replicationHelper->method('getDefaultSourceObject')->willReturn($factory);
    }

    public function testCreatesProductOnlyOnConfiguredWebsite(): void
    {
        $entry = json_encode([
            ['code' => 'GC01', 'item_type' => 'income_account', 'item_account_no' => 'ACC01'],
        ]);

        // Two websites; only website 2 has the entry configured.
        $this->storeManager->method('getWebsites')->willReturn([$this->website(1), $this->website(2)]);
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scope, $websiteId) use ($entry) {
                $this->assertSame(LSR::LS_VOUCHER_GIFT_CARD_CONFIGURATION, $path);
                $this->assertSame(ScopeInterface::SCOPE_WEBSITES, $scope);
                return $websiteId === 2 ? $entry : null;
            }
        );

        $this->stubNoExistingProduct();
        $this->stubDefaultSource();

        $product = $this->productMock();
        $product->expects($this->once())->method('setWebsiteIds')->with([2]);
        $this->productFactory->method('create')->willReturn($product);
        $this->productRepository->expects($this->once())->method('save')->with($product);

        $this->plugin->afterExecute($this->subject, 'ok');
    }

    public function testDefaultScopeEntryAttachesToAllWebsites(): void
    {
        $entry = json_encode([
            ['code' => 'GC01', 'item_type' => 'income_account', 'item_account_no' => 'ACC01'],
        ]);

        // Config resolves for every website (default-scope value).
        $this->storeManager->method('getWebsites')->willReturn([$this->website(1), $this->website(2)]);
        $this->scopeConfig->method('getValue')->willReturn($entry);

        $this->stubNoExistingProduct();
        $this->stubDefaultSource();

        $capturedWebsiteIds = null;
        $product = $this->productMock();
        $product->expects($this->once())->method('setWebsiteIds')
            ->willReturnCallback(function ($ids) use (&$capturedWebsiteIds) {
                $capturedWebsiteIds = $ids;
            });
        $this->productFactory->method('create')->willReturn($product);

        $this->plugin->afterExecute($this->subject, 'ok');

        // Assert set membership, not iteration order.
        sort($capturedWebsiteIds);
        $this->assertSame([1, 2], $capturedWebsiteIds);
    }

    public function testDefaultSourceItemRemovedAfterSave(): void
    {
        $entry = json_encode([
            ['code' => 'GC01', 'item_type' => 'income_account', 'item_account_no' => 'ACC01'],
        ]);

        $this->storeManager->method('getWebsites')->willReturn([$this->website(1)]);
        $this->scopeConfig->method('getValue')->willReturn($entry);

        $this->stubNoExistingProduct();
        $this->stubDefaultSource('default');

        $product = $this->productMock();
        $this->productFactory->method('create')->willReturn($product);

        // The default source item must be removed for the created product SKU.
        $this->replicationHelper->expects($this->once())
            ->method('deleteSourceItemsBySku')
            ->with('default', 'GC01');

        $this->plugin->afterExecute($this->subject, 'ok');
    }

    public function testExistingProductIsSkipped(): void
    {
        $entry = json_encode([
            ['code' => 'GC01', 'item_type' => 'income_account', 'item_account_no' => 'ACC01'],
        ]);

        $this->storeManager->method('getWebsites')->willReturn([$this->website(1)]);
        $this->scopeConfig->method('getValue')->willReturn($entry);

        $searchResults = $this->createMock(SearchResults::class);
        $searchResults->method('getTotalCount')->willReturn(1); // already exists
        $this->productRepository->method('getList')->willReturn($searchResults);

        $this->productFactory->expects($this->never())->method('create');
        $this->productRepository->expects($this->never())->method('save');
        $this->replicationHelper->expects($this->never())->method('deleteSourceItemsBySku');

        $this->plugin->afterExecute($this->subject, 'ok');
    }

    public function testNonIncomeAccountEntryIgnored(): void
    {
        $entry = json_encode([
            ['code' => 'GC01', 'item_type' => 'item', 'item_account_no' => 'ACC01'],
        ]);

        $this->storeManager->method('getWebsites')->willReturn([$this->website(1)]);
        $this->scopeConfig->method('getValue')->willReturn($entry);

        $this->productFactory->expects($this->never())->method('create');

        $this->plugin->afterExecute($this->subject, 'ok');
    }
}
