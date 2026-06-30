<?php

declare(strict_types=1);

namespace Ls\Commerce\Test\Unit\Plugin;

use Ls\Commerce\Plugin\GiftcardCompatibilityPlugin;
use Ls\Core\Model\LSR;
use Ls\Omni\Helper\ItemHelper;
use Magento\Catalog\Model\Product\Type;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GiftcardCompatibilityPlugin.
 *
 * The plugin always calls $proceed to obtain the base attribute array and only
 * substitutes $result[0] (the item ID) with the configured item_account_no for
 * giftcard-type products that have a matching VoucherGiftCardConfig entry.
 * Product/child-item resolution belongs to the original method ($proceed).
 */
class GiftcardCompatibilityPluginTest extends TestCase
{
    private GiftcardCompatibilityPlugin $plugin;

    /** @var LSR&MockObject */
    private LSR $lsr;

    /** @var ItemHelper&MockObject */
    private ItemHelper $subject;

    /** Tracks how many times the $proceed callable was invoked. */
    private int $proceedCalls = 0;

    protected function setUp(): void
    {
        $this->lsr = $this->createMock(LSR::class);
        $this->subject = $this->createMock(ItemHelper::class);
        $this->proceedCalls = 0;

        $this->plugin = new GiftcardCompatibilityPlugin($this->lsr);
    }

    public function testGiftcardWithMatchingConfigReturnsItemAccountNo(): void
    {
        $quoteItem = $this->buildQuoteItem(Giftcard::TYPE_GIFTCARD);

        $this->lsr->expects($this->once())
            ->method('getVoucherConfigByCode')
            ->with('GC001')
            ->willReturn([
                'code' => 'GC001',
                'item_type' => 'item',
                'item_account_no' => 'SELL001',
                'tender_type' => '',
            ]);

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed('GC001'),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('SELL001', $result[0]);
        // variantId, uom, barCode, uomQty unchanged.
        $this->assertSame('VAR001', $result[1]);
        $this->assertSame('PCS', $result[2]);
        $this->assertSame('BAR001', $result[3]);
        $this->assertSame('1', $result[4]);
    }

    public function testGiftcardWithNoConfigReturnsOriginalItemId(): void
    {
        $quoteItem = $this->buildQuoteItem(Giftcard::TYPE_GIFTCARD);

        $this->lsr->expects($this->once())
            ->method('getVoucherConfigByCode')
            ->with('GC002')
            ->willReturn(null);

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed('GC002'),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('GC002', $result[0]);
    }

    public function testGiftcardWithEmptyItemAccountNoReturnsOriginalItemId(): void
    {
        $quoteItem = $this->buildQuoteItem(Giftcard::TYPE_GIFTCARD);

        $this->lsr->expects($this->once())
            ->method('getVoucherConfigByCode')
            ->with('GC003')
            ->willReturn([
                'code' => 'GC003',
                'item_type' => 'item',
                'item_account_no' => '',
                'tender_type' => '',
            ]);

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed('GC003'),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('GC003', $result[0]);
    }

    public function testNonGiftcardSimpleProductIsNotLookedUp(): void
    {
        $quoteItem = $this->buildQuoteItem(Type::DEFAULT_TYPE);

        $this->lsr->expects($this->never())->method('getVoucherConfigByCode');

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed('SIMPLE001'),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('SIMPLE001', $result[0]);
    }

    public function testGiftcardWithEmptyItemIdSkipsConfigLookup(): void
    {
        $quoteItem = $this->buildQuoteItem(Giftcard::TYPE_GIFTCARD);

        $this->lsr->expects($this->never())->method('getVoucherConfigByCode');

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed(''),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('', $result[0]);
    }

    public function testNonGiftcardProductReturnsProceedResultUnchanged(): void
    {
        // Product/child resolution now belongs to the original method ($proceed);
        // for a non-giftcard type the plugin returns the proceed result as-is and
        // never performs a voucher config lookup.
        $quoteItem = $this->buildQuoteItem('configurable');

        $this->lsr->expects($this->never())->method('getVoucherConfigByCode');

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed('CHILD001'),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('CHILD001', $result[0]);
    }

    public function testProceedResultIsReturnedUnchangedWhenNotArray(): void
    {
        $quoteItem = $this->buildQuoteItem(Giftcard::TYPE_GIFTCARD);

        $this->lsr->expects($this->never())->method('getVoucherConfigByCode');

        $proceed = function () {
            $this->proceedCalls++;

            return null;
        };

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $proceed,
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertNull($result);
    }

    public function testIncomeAccountConfigAlsoSubstitutesItemId(): void
    {
        $quoteItem = $this->buildQuoteItem(Giftcard::TYPE_GIFTCARD);

        $this->lsr->expects($this->once())
            ->method('getVoucherConfigByCode')
            ->with('GC004')
            ->willReturn([
                'code' => 'GC004',
                'item_type' => 'income_account',
                'item_account_no' => 'ACC-200',
                'tender_type' => '',
            ]);

        $result = $this->plugin->aroundGetItemAttributesGivenQuoteItem(
            $this->subject,
            $this->buildProceed('GC004'),
            $quoteItem
        );

        $this->assertSame(1, $this->proceedCalls);
        $this->assertSame('ACC-200', $result[0]);
    }

    /**
     * Build a quote item mock with the given product type.
     */
    private function buildQuoteItem(string $productType): Item&MockObject
    {
        $item = $this->createMock(Item::class);
        $item->method('getProductType')->willReturn($productType);

        return $item;
    }

    /**
     * Build a $proceed callable that returns the five-element base attribute
     * array as the original method would, with the given item ID at index 0.
     */
    private function buildProceed(string $itemId): callable
    {
        return function () use ($itemId): array {
            $this->proceedCalls++;

            return [$itemId, 'VAR001', 'PCS', 'BAR001', '1'];
        };
    }
}
