<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Cart\LineItem\Group;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemQuantitySplitter;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(LineItemQuantitySplitter::class)]
class LineItemQuantitySplitterTest extends TestCase
{
    use KernelTestBehaviour;

    private SalesChannelContext $salesChannelContext;

    protected function setUp(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getTaxState')->willReturn(CartPrice::TAX_STATE_GROSS);
        $context->method('getItemRounding')->willReturn(new CashRoundingConfig(2, 0.01, true));

        $this->salesChannelContext = $context;
    }

    #[DataProvider('splitProvider')]
    public function testSplit(int $itemQty, int $splitterQty, int $calcExpects): void
    {
        $splitter = $this->createQtySplitter($calcExpects);

        $lineItem = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE, Uuid::randomHex(), $itemQty);
        $lineItem->setPrice(new CalculatedPrice(10, 99, new CalculatedTaxCollection(), new TaxRuleCollection(), $itemQty));
        $lineItem->setStackable(true);

        $newLineItem = $splitter->split($lineItem, $splitterQty, $this->salesChannelContext);

        if ($calcExpects <= 0) {
            static::assertEquals($lineItem, $newLineItem);
        } else {
            $expectedPrice = 10.0 * $splitterQty;

            static::assertNotSame($lineItem, $newLineItem);
            static::assertSame($splitterQty, $newLineItem->getQuantity());
            static::assertNotNull($newLineItem->getPrice());
            static::assertSame($expectedPrice, $newLineItem->getPrice()->getTotalPrice());
        }
    }

    /**
     * @return \Generator<string, int[]>
     */
    public static function splitProvider(): \Generator
    {
        yield 'should not split items when item qty = 10 and splitter qty = 10' => [10, 10, 0];
        yield 'should split items when item qty = 10 and splitter qty = 9' => [10, 9, 1];
        yield 'should split items when item qty = 9 and splitter qty = 10' => [9, 10, 1];
    }

    private function createQtySplitter(int $expects): LineItemQuantitySplitter
    {
        $qtyCalc = $this->createMock(QuantityPriceCalculator::class);
        $qtyCalc
            ->expects($this->exactly($expects))
            ->method('calculate')
            ->willReturnCallback(fn (QuantityPriceDefinition $definition, SalesChannelContext $context) => static::getContainer()->get(QuantityPriceCalculator::class)->calculate($definition, $context));

        return new LineItemQuantitySplitter($qtyCalc);
    }
}
