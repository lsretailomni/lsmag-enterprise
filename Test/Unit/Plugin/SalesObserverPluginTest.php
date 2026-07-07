<?php

declare(strict_types=1);

namespace Ls\Commerce\Test\Unit\Plugin;

use \Ls\Commerce\Plugin\SalesObserverPlugin;
use \Ls\Core\Model\LSR;
use \Ls\Omni\Helper\BasketHelper;
use \Ls\Omni\Helper\ItemHelper;
use \Ls\Omni\Observer\SalesObserver;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Address\AbstractAddress;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\Option;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Shipping;
use Magento\Quote\Model\ShippingAssignment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SalesObserverPlugin.
 *
 * The plugin adds the open amount of income_account giftcard items (which were removed from the
 * basket sent to LS Central) back onto the quote address totals after SalesObserver::execute has
 * written the Central-derived totals — on the same address-type pass, so the amount is added
 * exactly once. Per-item custom_price is set in memory only (not saved to DB) to prevent
 * SubtotalCollector from natively including the amount on a subsequent collectTotals() call,
 * which would cause the plugin to double-add it.
 */
class SalesObserverPluginTest extends TestCase
{
    private SalesObserverPlugin $plugin;

    /** @var LSR&MockObject */
    private LSR $lsr;

    /** @var ItemHelper&MockObject */
    private ItemHelper $itemHelper;

    /** @var SalesObserver&MockObject */
    private SalesObserver $subject;

    /** @var BasketHelper&MockObject */
    private BasketHelper $basketHelper;

    protected function setUp(): void
    {
        $this->lsr          = $this->createMock(LSR::class);
        $this->itemHelper   = $this->createMock(ItemHelper::class);
        $this->basketHelper = $this->createMock(BasketHelper::class);

        $this->subject = $this->createMock(SalesObserver::class);
        $this->subject->basketHelper = $this->basketHelper;

        $this->plugin = new SalesObserverPlugin($this->lsr, $this->itemHelper);
    }

    public function testAddsIncomeAccountAmountToSubtotalsAndGrandTotalForPhysicalQuoteOnShippingAddress(): void
    {
        $this->basketHelper->method('getBasketSessionValue')->willReturn(new DataObject());
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        $this->itemHelper->method('convertToBaseCurrency')->willReturnArgument(0);

        $item     = $this->buildGiftcardItem('GC001', 50.0, 1.0);
        $quote    = $this->buildQuote(false, [$item]);
        $total    = $this->buildTotal(['subtotal' => 100.0, 'base_subtotal' => 100.0,
            'subtotal_incl_tax' => 100.0, 'base_subtotal_incl_tax' => 100.0,
            'base_subtotal_total_incl_tax' => 100.0, 'grand_total' => 100.0, 'base_grand_total' => 100.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $result = $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame($this->subject, $result);
        $this->assertSame(150.0, $total->getSubtotal());
        $this->assertSame(150.0, $total->getBaseSubtotal());
        $this->assertSame(150.0, $total->getSubtotalInclTax());
        $this->assertSame(150.0, $total->getBaseSubtotalInclTax());
        $this->assertSame(150.0, $total->getBaseSubtotalTotalInclTax());
        $this->assertSame(150.0, $total->getGrandTotal());
        $this->assertSame(150.0, $total->getBaseGrandTotal());
    }

    public function testAddsAmountForVirtualQuoteOnBillingAddress(): void
    {
        $this->basketHelper->method('getBasketSessionValue')->willReturn(new DataObject());
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        $this->itemHelper->method('convertToBaseCurrency')->willReturnArgument(0);

        $item     = $this->buildGiftcardItem('GC001', 50.0, 1.0);
        $quote    = $this->buildQuote(true, [$item]);
        $total    = $this->buildTotal(['subtotal' => 0.0, 'base_subtotal' => 0.0,
            'subtotal_incl_tax' => 0.0, 'base_subtotal_incl_tax' => 0.0,
            'base_subtotal_total_incl_tax' => 0.0, 'grand_total' => 0.0, 'base_grand_total' => 0.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_BILLING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(50.0, $total->getSubtotal());
        $this->assertSame(50.0, $total->getGrandTotal());
    }

    public function testDoesNotApplyOnWrongAddressType(): void
    {
        // Physical quote on a billing address — SalesObserver did not set Central totals here.
        $this->lsr->expects($this->never())->method('getVoucherGiftCardConfiguration');

        $quote    = $this->buildQuote(false, [$this->buildGiftcardItem('GC001', 50.0, 1.0)]);
        $total    = $this->buildTotal(['subtotal' => 100.0, 'grand_total' => 100.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_BILLING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(100.0, $total->getSubtotal());
        $this->assertSame(100.0, $total->getGrandTotal());
    }

    public function testNoIncomeAccountItemsLeavesTotalsUnchanged(): void
    {
        $this->basketHelper->method('getBasketSessionValue')->willReturn(new DataObject());
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC900', 'item_type' => 'item'],
        ]);

        $quote    = $this->buildQuote(false, [$this->buildGiftcardItem('GC001', 50.0, 1.0)]);
        $total    = $this->buildTotal(['subtotal' => 100.0, 'grand_total' => 100.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(100.0, $total->getSubtotal());
        $this->assertSame(100.0, $total->getGrandTotal());
    }

    public function testNoBasketSessionStillAddsIncomeAccountAmounts(): void
    {
        // When the basket is empty (all items were income_account and removed by BasketHelperPlugin),
        // Central returns null and no session is saved. The correction must still run because the
        // native Magento subtotal collector is bypassed by Ls_Omni in all LSR-enabled stores.
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        $this->itemHelper->method('convertToBaseCurrency')->willReturnArgument(0);

        $quote    = $this->buildQuote(false, [$this->buildGiftcardItem('GC001', 50.0, 1.0)]);
        $total    = $this->buildTotal(['subtotal' => 0.0, 'base_subtotal' => 0.0,
            'subtotal_incl_tax' => 0.0, 'base_subtotal_incl_tax' => 0.0,
            'base_subtotal_total_incl_tax' => 0.0, 'grand_total' => 0.0, 'base_grand_total' => 0.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(50.0, $total->getSubtotal());
        $this->assertSame(50.0, $total->getGrandTotal());
    }

    public function testMultipleQtyMultipliesAmount(): void
    {
        $this->basketHelper->method('getBasketSessionValue')->willReturn(new DataObject());
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        $this->itemHelper->method('convertToBaseCurrency')->willReturnArgument(0);

        $item     = $this->buildGiftcardItem('GC001', 50.0, 2.0);
        $quote    = $this->buildQuote(false, [$item]);
        $total    = $this->buildTotal(['subtotal' => 0.0, 'grand_total' => 0.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(100.0, $total->getSubtotal());
        $this->assertSame(100.0, $total->getGrandTotal());
    }

    public function testZeroOrMissingGiftcardAmountOptionIsSkipped(): void
    {
        $this->basketHelper->method('getBasketSessionValue')->willReturn(new DataObject());
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
            ['code' => 'GC002', 'item_type' => 'income_account'],
        ]);

        $zeroAmountItem  = $this->buildGiftcardItem('GC001', 0.0, 1.0);
        $missingOptItem  = $this->buildGiftcardItem('GC002', null, 1.0);
        $quote           = $this->buildQuote(false, [$zeroAmountItem, $missingOptItem]);
        $total           = $this->buildTotal(['subtotal' => 100.0, 'grand_total' => 100.0]);
        $observer        = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(100.0, $total->getSubtotal());
        $this->assertSame(100.0, $total->getGrandTotal());
    }

    public function testNoBasketBranchSubtractsNativeRowTotalToAvoidDoubling(): void
    {
        // In the no-basket branch SubtotalCollector already added the item's catalog price
        // ($50) to total.subtotal. The plugin must add only the delta (giftcard_amount −
        // catalog row total) so subtotal/grandTotal end up at exactly one item's worth, not two.
        // However, subtotalInclTax is NEVER set by any collector in LSR (the Tax\Subtotal
        // collector is bypassed), so the plugin must always add the full giftcard amount there.
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        $this->itemHelper->method('convertToBaseCurrency')->willReturnArgument(0);

        // Item with catalog price $50 (rowTotal = $50) AND giftcard_amount = $50.
        $item  = $this->buildGiftcardItem('GC001', 50.0, 1.0, /* rowTotal */ 50.0);
        $quote = $this->buildQuote(false, [$item]);
        // LSR reality: SubtotalCollector sets subtotal=$50 but subtotalInclTax stays 0
        // (Tax\Subtotal collector is bypassed). subtotal/grandTotal start at $50.
        $total    = $this->buildTotal(['subtotal' => 50.0, 'base_subtotal' => 50.0,
            'subtotal_incl_tax' => 0.0, 'base_subtotal_incl_tax' => 0.0,
            'base_subtotal_total_incl_tax' => 0.0, 'grand_total' => 50.0, 'base_grand_total' => 50.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(50.0, $total->getSubtotal(), 'subtotal must not double when catalog price equals giftcard amount');
        $this->assertSame(50.0, $total->getSubtotalInclTax(), 'subtotalInclTax must be set even when subtotal delta is zero');
        $this->assertSame(50.0, $total->getGrandTotal(), 'grandTotal must not double when catalog price equals giftcard amount');
    }

    public function testNoBasketBranchAddsOnlyGapWhenCatalogPriceIsLowerThanGiftcardAmount(): void
    {
        // Catalog price $10 (SubtotalCollector added $10), giftcard_amount $50.
        // subtotal/grandTotal must gain only $40 (the gap). subtotalInclTax must gain $50
        // (full amount, because LSR's Tax\Subtotal collector is bypassed and starts at 0).
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        $this->itemHelper->method('convertToBaseCurrency')->willReturnArgument(0);

        $item  = $this->buildGiftcardItem('GC001', 50.0, 1.0, /* rowTotal */ 10.0);
        $quote = $this->buildQuote(false, [$item]);
        $total = $this->buildTotal(['subtotal' => 10.0, 'base_subtotal' => 10.0,
            'subtotal_incl_tax' => 0.0, 'base_subtotal_incl_tax' => 0.0,
            'base_subtotal_total_incl_tax' => 0.0, 'grand_total' => 10.0, 'base_grand_total' => 10.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(50.0, $total->getSubtotal());
        $this->assertSame(50.0, $total->getSubtotalInclTax());
        $this->assertSame(50.0, $total->getGrandTotal());
    }

    public function testBaseCurrencyConversionAppliedToBaseTotals(): void
    {
        $this->basketHelper->method('getBasketSessionValue')->willReturn(new DataObject());
        $this->lsr->method('getVoucherGiftCardConfiguration')->willReturn([
            ['code' => 'GC001', 'item_type' => 'income_account'],
        ]);
        // Display currency amount 50 → base currency 25 (e.g. 0.5 exchange rate).
        // convertToBaseCurrency is called once for the running total (base subtotal/grand-total).
        $this->itemHelper->expects($this->once())
            ->method('convertToBaseCurrency')
            ->with(50.0)
            ->willReturn(25.0);

        $item     = $this->buildGiftcardItem('GC001', 50.0, 1.0);
        $quote    = $this->buildQuote(false, [$item]);
        $total    = $this->buildTotal(['subtotal' => 0.0, 'base_subtotal' => 0.0,
            'subtotal_incl_tax' => 0.0, 'base_subtotal_incl_tax' => 0.0,
            'base_subtotal_total_incl_tax' => 0.0, 'grand_total' => 0.0, 'base_grand_total' => 0.0]);
        $observer = $this->buildObserver($quote, $total, AbstractAddress::TYPE_SHIPPING);

        $this->plugin->afterExecute($this->subject, $this->subject, $observer);

        $this->assertSame(50.0, $total->getSubtotal());
        $this->assertSame(25.0, $total->getBaseSubtotal());
        $this->assertSame(25.0, $total->getBaseSubtotalInclTax());
        $this->assertSame(25.0, $total->getBaseSubtotalTotalInclTax());
        $this->assertSame(50.0, $total->getGrandTotal());
        $this->assertSame(25.0, $total->getBaseGrandTotal());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildTotal(array $data): DataObject
    {
        return new DataObject($data);
    }

    private function buildQuote(bool $isVirtual, array $items): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn($isVirtual);
        $quote->method('getAllVisibleItems')->willReturn($items);
        return $quote;
    }

    /**
     * @param float|null  $amount    null → no giftcard_amount option present
     * @param float       $rowTotal  value returned by getRowTotal() (SubtotalCollector's contribution)
     */
    private function buildGiftcardItem(string $sku, ?float $amount, float $qty, float $rowTotal = 0.0): Item&MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->addMethods(['setIsSuperMode'])
            ->getMock();
        $product->method('setIsSuperMode')->willReturnSelf();

        // setCustomPrice exists as a real method; the rest are DataObject magic methods.
        $magicMethods = [
            'setOriginalCustomPrice',
            'setRowTotal', 'setBaseRowTotal',
            'setRowTotalInclTax', 'setBaseRowTotalInclTax',
            'getRowTotal',
        ];
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->addMethods($magicMethods)
            ->onlyMethods(['getProductType', 'getSku', 'getQty', 'getProduct', 'getOptionByCode', 'setCustomPrice'])
            ->getMock();
        $item->method('getProductType')->willReturn(Giftcard::TYPE_GIFTCARD);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getProduct')->willReturn($product);
        $item->method('setCustomPrice')->willReturnSelf();
        $item->method('getRowTotal')->willReturn($rowTotal);
        foreach ($magicMethods as $method) {
            if (str_starts_with($method, 'set')) {
                $item->method($method)->willReturnSelf();
            }
        }

        if ($amount === null) {
            $item->method('getOptionByCode')->with('giftcard_amount')->willReturn(null);
        } else {
            $option = $this->createMock(Option::class);
            $option->method('getValue')->willReturn((string) $amount);
            $item->method('getOptionByCode')->with('giftcard_amount')->willReturn($option);
        }

        return $item;
    }

    private function buildObserver(Quote $quote, DataObject $total, string $addressType): Observer&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['getAddressType'])
            ->getMock();
        $address->method('getAddressType')->willReturn($addressType);

        $shipping = $this->createMock(Shipping::class);
        $shipping->method('getAddress')->willReturn($address);

        $shippingAssignment = $this->createMock(ShippingAssignment::class);
        $shippingAssignment->method('getShipping')->willReturn($shipping);

        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getQuote', 'getTotal', 'getShippingAssignment'])
            ->getMock();
        $event->method('getQuote')->willReturn($quote);
        $event->method('getTotal')->willReturn($total);
        $event->method('getShippingAssignment')->willReturn($shippingAssignment);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
