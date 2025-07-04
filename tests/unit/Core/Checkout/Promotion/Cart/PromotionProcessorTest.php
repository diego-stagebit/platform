<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Promotion\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\Group\LineItemGroupBuilder;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Promotion\Cart\Error\PromotionsOnCartPriceZeroError;
use Shopware\Core\Checkout\Promotion\Cart\PromotionCalculator;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(PromotionProcessor::class)]
class PromotionProcessorTest extends TestCase
{
    public function testProcess(): void
    {
        $promotionCalculatorMock = $this->createMock(PromotionCalculator::class);
        $groupBuilderMock = $this->createMock(LineItemGroupBuilder::class);

        $promotionProcessor = new PromotionProcessor($promotionCalculatorMock, $groupBuilderMock);

        $originalCart = new Cart('test');
        $originalCart->add(new LineItem('A', 'promotion', 'A', 2)); // 2 items of promotion A

        $toCalculateCart = new Cart('test');
        $toCalculateCart->setPrice(new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_NET));

        $context = $this->createMock(SalesChannelContext::class);
        $behavior = new CartBehavior();

        $data = new CartDataCollection();
        $data->set(PromotionProcessor::DATA_KEY, new LineItemCollection(
            [new LineItem('B', PromotionProcessor::LINE_ITEM_TYPE, Uuid::randomHex(), 1)],
        ));

        $promotionCalculatorMock->expects($this->once())
            ->method('calculate')
            ->with(
                static::callback(function (LineItemCollection $data) {
                    static::assertTrue($data->has('B'));
                    static::assertTrue($data->get('B')->isShippingCostAware());

                    return true;
                }),
                static::anything(),
                static::anything(),
                static::anything()
            );

        $promotionProcessor->process($data, $originalCart, $toCalculateCart, $context, $behavior);
    }

    public function testProcessWithCartZeroPriceAndPromotionIsGlobal(): void
    {
        $promotionCalculatorMock = $this->createMock(PromotionCalculator::class);
        $groupBuilderMock = $this->createMock(LineItemGroupBuilder::class);

        $promotionProcessor = new PromotionProcessor($promotionCalculatorMock, $groupBuilderMock);

        $originalCart = new Cart('test');
        $originalCart->add(new LineItem('A', 'promotion', 'A', 2)); // 2 items of promotion A

        $toCalculateCart = new Cart('test');
        $toCalculateCart->setPrice(new CartPrice(0, 0, 0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_NET));

        $context = $this->createMock(SalesChannelContext::class);
        $behavior = new CartBehavior();

        $data = new CartDataCollection();
        $data->set(PromotionProcessor::DATA_KEY, new LineItemCollection(
            // `promotionCodeType` => global means the promotion is automatically applied if matched conditions
            [(new LineItem('B', PromotionProcessor::LINE_ITEM_TYPE, Uuid::randomHex(), 1))->setPayload(['promotionCodeType' => PromotionItemBuilder::PROMOTION_TYPE_GLOBAL])],
        ));

        $promotionCalculatorMock->expects($this->never())
            ->method('calculate');

        $promotionProcessor->process($data, $originalCart, $toCalculateCart, $context, $behavior);

        static::assertCount(0, $toCalculateCart->getErrors());
    }

    public function testProcessWithCartZeroPriceAndPromotionIsNotGlobal(): void
    {
        $promotionCalculatorMock = $this->createMock(PromotionCalculator::class);
        $groupBuilderMock = $this->createMock(LineItemGroupBuilder::class);

        $promotionProcessor = new PromotionProcessor($promotionCalculatorMock, $groupBuilderMock);

        $originalCart = new Cart('test');
        $originalCart->add(new LineItem('A', 'promotion', 'A', 2)); // 2 items of promotion A

        $toCalculateCart = new Cart('test');
        $toCalculateCart->setPrice(new CartPrice(0, 0, 0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_NET));

        $context = $this->createMock(SalesChannelContext::class);
        $behavior = new CartBehavior();

        $data = new CartDataCollection();
        $data->set(PromotionProcessor::DATA_KEY, new LineItemCollection(
            // `promotionCodeType` => fixed means the promotion is applied only if the promotion code is input.
            [(new LineItem('B', PromotionProcessor::LINE_ITEM_TYPE, Uuid::randomHex(), 1))->setPayload(['promotionCodeType' => PromotionItemBuilder::PROMOTION_TYPE_FIXED])],
        ));

        $promotionCalculatorMock->expects($this->never())
            ->method('calculate');

        $promotionProcessor->process($data, $originalCart, $toCalculateCart, $context, $behavior);

        static::assertCount(1, $toCalculateCart->getErrors());
        static::assertInstanceOf(PromotionsOnCartPriceZeroError::class, $toCalculateCart->getErrors()->first());
    }
}
