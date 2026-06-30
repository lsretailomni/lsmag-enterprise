<?php

declare(strict_types=1);

namespace Ls\Commerce\Test\Unit\Plugin;

use \Ls\Commerce\Plugin\BasketHelperPlugin;
use \Ls\Core\Model\LSR;
use \Ls\Omni\Client\CentralEcommerce\Entity\MobileTransactionLine;
use \Ls\Omni\Client\CentralEcommerce\Entity\RootMobileTransaction;
use \Ls\Omni\Helper\BasketHelper;
use Magento\Catalog\Model\Product;
use Magento\GiftCard\Model\Catalog\Product\Type\Giftcard;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BasketHelperPlugin.
 *
 * The plugin removes every MobileTransactionLine whose NUMBER (= lsrItemId) belongs to an
 * income_account giftcard product. Income_account items are identified by matching the
 * product SKU against entry['code'] in VoucherGiftCardConfiguration — consistent with
 * SalesObserverPlugin and DataHelperPlugin. Filtering uses lsrItemId because that is what
 * BasketHelper sets as MobileTransactionLine::NUMBER.
 */
class BasketHelperPluginTest extends TestCase
{
    private BasketHelperPlugin $plugin;

    /** @var LSR&MockObject */
    private LSR $lsr;

    /** @var BasketHelper&MockObject */
    private BasketHelper $subject;

    protected function setUp(): void
    {
        $this->lsr     = $this->createMock(LSR::class);
        $this->subject = $this->createMock(BasketHelper::class);
        $this->plugin  = new BasketHelperPlugin($this->lsr);
    }

    public function testIncomeAccountGiftcardLineIsRemovedFromBasket(): void
    {
        // SKU = 'GC001' (matches entry['code']), lsrItemId = 'ACC-200' (matches NUMBER).
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC001', 'ACC-200')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC001', 'item_type' => 'income_account']]);

        $line   = $this->buildLine('ACC-200');
        $result = $this->buildTransaction([$line]);

        $result->expects($this->once())
            ->method('setMobiletransactionline')
            ->with([]);

        $returned = $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);

        $this->assertSame($result, $returned);
    }

    public function testNonGiftcardItemIsNotProcessed(): void
    {
        $quote = $this->buildQuote([$this->buildItem('simple', 'ITEM001', 'ITEM001')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'ITEM001', 'item_type' => 'income_account']]);

        $result = $this->buildTransaction([$this->buildLine('ITEM001')]);
        $result->expects($this->never())->method('setMobiletransactionline');

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testGiftcardWithItemTypeConfigIsNotProcessed(): void
    {
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC002', 'LSID-002')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC002', 'item_type' => 'item']]);

        $result = $this->buildTransaction([$this->buildLine('LSID-002')]);
        $result->expects($this->never())->method('setMobiletransactionline');

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testGiftcardNotInConfigIsNotProcessed(): void
    {
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC003', 'LSID-003')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([]);

        $result = $this->buildTransaction([$this->buildLine('LSID-003')]);
        $result->expects($this->never())->method('setMobiletransactionline');

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testGiftcardWithEmptyLsrItemIdIsSkipped(): void
    {
        // lsrItemId is empty — the item cannot produce a basket line NUMBER, so skip it.
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC004', '')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC004', 'item_type' => 'income_account']]);

        $result = $this->buildTransaction([$this->buildLine('GC004')]);
        $result->expects($this->never())->method('setMobiletransactionline');

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testNullProductOnQuoteItemIsSkippedSafely(): void
    {
        $quoteItem = $this->createMock(Item::class);
        $quoteItem->method('getProductType')->willReturn(Giftcard::TYPE_GIFTCARD);
        $quoteItem->method('getSku')->willReturn('GC005');
        $quoteItem->method('getProduct')->willReturn(null);

        $quote = $this->buildQuote([$quoteItem]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC005', 'item_type' => 'income_account']]);

        $result = $this->buildTransaction([$this->buildLine('GC005')]);
        $result->expects($this->never())->method('setMobiletransactionline');

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testLineWhoseNumberDoesNotMatchIsRetained(): void
    {
        // lsrItemId = 'LSID-006', but basket line NUMBER = 'DIFFERENT-NUMBER'.
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC006', 'LSID-006')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC006', 'item_type' => 'income_account']]);

        $line   = $this->buildLine('DIFFERENT-NUMBER');
        $result = $this->buildTransaction([$line]);

        $result->expects($this->once())
            ->method('setMobiletransactionline')
            ->with([$line]);

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testEmptyBasketLinesReturnsResultUnchanged(): void
    {
        // No lines in the RootMobileTransaction — early return before config lookup.
        $quote = $this->buildQuote([]);

        $this->lsr->expects($this->never())->method('getVoucherGiftCardConfiguration');

        $result = $this->buildTransaction([]);
        $result->expects($this->never())->method('setMobiletransactionline');

        $returned = $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);

        $this->assertSame($result, $returned);
    }

    public function testAllIncomeAccountLinesFilteredClearsStaleBasketSession(): void
    {
        // When ALL basket lines belong to income_account items the resulting basket is empty.
        // Any stale session must be cleared so SalesObserver takes the correct no-basket path.
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC-INC', 'ACC-100')]);

        $this->lsr->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC-INC', 'item_type' => 'income_account']]);

        $line   = $this->buildLine('ACC-100');
        $result = $this->buildTransaction([$line]);
        $result->expects($this->once())->method('setMobiletransactionline')->with([]);

        $this->subject->expects($this->once())
            ->method('setOneListCalculationInCheckoutSession')
            ->with(null);

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testPartialFilterDoesNotClearBasketSession(): void
    {
        // Only income_account lines are removed; regular lines remain → session must NOT be cleared.
        $quote = $this->buildQuote([
            $this->buildGiftcardItem('GC-INC', 'ACC-100'),
            $this->buildGiftcardItem('GC-ITEM', 'LSID-200'),
        ]);

        $this->lsr->method('getVoucherGiftCardConfiguration')
            ->willReturn([
                ['code' => 'GC-INC', 'item_type' => 'income_account'],
                ['code' => 'GC-ITEM', 'item_type' => 'item'],
            ]);

        $incLine = $this->buildLine('ACC-100');
        $retLine = $this->buildLine('LSID-200');
        $result  = $this->buildTransaction([$incLine, $retLine]);
        $result->expects($this->once())->method('setMobiletransactionline')->with([$retLine]);

        $this->subject->expects($this->never())->method('setOneListCalculationInCheckoutSession');

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testOnlyIncomeAccountLinesAreRemovedInMixedCart(): void
    {
        $quote = $this->buildQuote([
            $this->buildGiftcardItem('GC-INCOME', 'ACC-600'), // income_account → removed
            $this->buildGiftcardItem('GC-ITEM', 'SELL-007'),  // item type → retained
        ]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([
                ['code' => 'GC-INCOME', 'item_type' => 'income_account'],
                ['code' => 'GC-ITEM', 'item_type' => 'item'],
            ]);

        $incomeAccountLine = $this->buildLine('ACC-600');
        $itemLine          = $this->buildLine('SELL-007');

        $result = $this->buildTransaction([$incomeAccountLine, $itemLine]);

        $result->expects($this->once())
            ->method('setMobiletransactionline')
            ->with([$itemLine]);

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    public function testSkuMatchesEvenWhenLsrItemIdDiffersFromCode(): void
    {
        // This is the primary regression case: code (SKU) = 'GC-SKU', lsrItemId = 'ACC-GL-100'.
        // Old code used getVoucherConfigByCode(lsrItemId) which would fail when lsrItemId != code.
        // New code uses getSku() for identification and lsrItemId as the filter key.
        $quote = $this->buildQuote([$this->buildGiftcardItem('GC-SKU', 'ACC-GL-100')]);

        $this->lsr->expects($this->once())
            ->method('getVoucherGiftCardConfiguration')
            ->willReturn([['code' => 'GC-SKU', 'item_type' => 'income_account']]);

        $line   = $this->buildLine('ACC-GL-100');
        $result = $this->buildTransaction([$line]);

        $result->expects($this->once())
            ->method('setMobiletransactionline')
            ->with([]);

        $this->plugin->afterSetOneListQuote($this->subject, $result, $quote);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildQuote(array $items): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn($items);
        return $quote;
    }

    private function buildGiftcardItem(string $sku, string $lsrItemId): Item&MockObject
    {
        return $this->buildItem(Giftcard::TYPE_GIFTCARD, $sku, $lsrItemId);
    }

    private function buildItem(string $productType, string $sku, string $lsrItemId): Item&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')
            ->with(LSR::LS_ITEM_ID_ATTRIBUTE_CODE)
            ->willReturn($lsrItemId);

        $item = $this->createMock(Item::class);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getSku')->willReturn($sku);
        $item->method('getProduct')->willReturn($product);
        return $item;
    }

    private function buildLine(string $number): MobileTransactionLine&MockObject
    {
        $line = $this->createMock(MobileTransactionLine::class);
        $line->method('getData')
            ->with(MobileTransactionLine::NUMBER)
            ->willReturn($number);
        return $line;
    }

    private function buildTransaction(array $lines): RootMobileTransaction&MockObject
    {
        $transaction = $this->createMock(RootMobileTransaction::class);
        $transaction->method('getMobiletransactionline')->willReturn($lines);
        return $transaction;
    }
}
