<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Cart\Promotion\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\CountryAddToSalesChannelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Integration\Traits\Promotion\PromotionIntegrationTestBehaviour;
use Shopware\Core\Test\Integration\Traits\Promotion\PromotionTestFixtureBehaviour;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('checkout')]
class PromotionDiscountCompositionTest extends TestCase
{
    use CountryAddToSalesChannelTestBehaviour;
    use IntegrationTestBehaviour;
    use PromotionIntegrationTestBehaviour;
    use PromotionTestFixtureBehaviour;

    /**
     * @var EntityRepository<ProductCollection>
     */
    protected EntityRepository $productRepository;

    protected CartService $cartService;

    /**
     * @var EntityRepository<PromotionCollection>
     */
    protected EntityRepository $promotionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = static::getContainer()->get('product.repository');
        $this->promotionRepository = static::getContainer()->get('promotion.repository');
        $this->cartService = static::getContainer()->get(CartService::class);

        $this->addCountriesToSalesChannel();

        $this->context = static::getContainer()
            ->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);
    }

    /**
     * This test verifies that we have a correct composition node in the payload.
     * We use this as reference, to know of what products and quantities our discount consists of.
     * The absolute discount needs to contain all products and items in the composition. The price of these
     * composition-products need to be divided individually across all included products.
     * We have a product with price 50 EUR and quantity 3 and another product with price 100 and quantity 1.
     * If we have an absolute discount of 30 EUR, then product one should be referenced with 18 EUR and product 2 with 12 EUR (150 EUR vs. 100 EUR).
     **/
    #[Group('promotions')]
    public function testCompositionInAbsoluteDiscount(): void
    {
        $productId1 = Uuid::randomHex();
        $productId2 = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF19';

        // add a new sample product
        $this->createTestFixtureProduct($productId1, 50, 19, static::getContainer(), $this->context);
        $this->createTestFixtureProduct($productId2, 100, 19, static::getContainer(), $this->context);

        // add a new promotion
        $this->createTestFixtureAbsolutePromotion($promotionId, $code, 30, static::getContainer());

        $cart = $this->cartService->getCart($this->context->getToken(), $this->context);

        // create product and add to cart
        $cart = $this->addProduct($productId1, 3, $cart, $this->cartService, $this->context);
        $cart = $this->addProduct($productId2, 1, $cart, $this->cartService, $this->context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $this->context);

        // get discount line item
        $discountItem = $cart->getLineItems()->getFlat()[2];

        static::assertTrue($discountItem->hasPayloadValue('composition'), 'composition node is missing');

        $composition = $discountItem->getPayload()['composition'];

        static::assertSame($productId1, $composition[0]['id']);
        static::assertSame(3, $composition[0]['quantity']);
        static::assertSame(18.0, $composition[0]['discount']);

        static::assertSame($productId2, $composition[1]['id']);
        static::assertSame(1, $composition[1]['quantity']);
        static::assertSame(12.0, $composition[1]['discount']);
    }

    /**
     * This test verifies that our composition data is correct.
     * We apply a discount of 25% on all items. So every item should appear with its original
     * quantity and the 25% of its original price as discount.
     **/
    #[Group('promotions')]
    public function testCompositionInPercentageDiscount(): void
    {
        $productId1 = Uuid::randomHex();
        $productId2 = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF19';

        // add a new sample product
        $this->createTestFixtureProduct($productId1, 50, 19, static::getContainer(), $this->context);
        $this->createTestFixtureProduct($productId2, 100, 19, static::getContainer(), $this->context);

        // add a new promotion
        $this->createTestFixturePercentagePromotion($promotionId, $code, 25, null, static::getContainer());

        $cart = $this->cartService->getCart($this->context->getToken(), $this->context);

        // create product and add to cart
        $cart = $this->addProduct($productId1, 3, $cart, $this->cartService, $this->context);
        $cart = $this->addProduct($productId2, 1, $cart, $this->cartService, $this->context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $this->context);

        // get discount line item
        $discountItem = $cart->getLineItems()->getFlat()[2];

        static::assertTrue($discountItem->hasPayloadValue('composition'), 'composition node is missing');

        $composition = $discountItem->getPayload()['composition'];

        static::assertSame($productId1, $composition[0]['id']);
        static::assertSame(3, $composition[0]['quantity']);
        static::assertSame(150 * 0.25, $composition[0]['discount']);

        static::assertSame($productId2, $composition[1]['id']);
        static::assertSame(1, $composition[1]['quantity']);
        static::assertSame(100 * 0.25, $composition[1]['discount']);
    }

    #[Group('slow')]
    public function testPromotionRedemption(): void
    {
        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(
                Uuid::randomHex(),
                TestDefaults::SALES_CHANNEL,
                [SalesChannelContextService::CUSTOMER_ID => $this->createCustomer()]
            );

        $productId1 = Uuid::randomHex();
        $productId2 = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF19';

        // add a new sample product
        $this->createTestFixtureProduct($productId1, 50, 19, static::getContainer(), $context);
        $this->createTestFixtureProduct($productId2, 100, 19, static::getContainer(), $context);

        // add a new promotion
        $this->createTestFixturePercentagePromotion($promotionId, $code, 25, null, static::getContainer());

        // order promotion with two products
        $this->orderWithPromotion($code, [$productId1, $productId2], $context);

        $promotion = $this->promotionRepository
            ->search(new Criteria([$promotionId]), Context::createDefaultContext())
            ->get($promotionId);

        static::assertInstanceOf(PromotionEntity::class, $promotion);

        // verify that the promotion has a total order count of 1 and the current customer is although tracked
        static::assertSame(1, $promotion->getOrderCount());
        static::assertNotNull($context->getCustomer());
        static::assertSame(
            [$context->getCustomerId() => 1],
            $promotion->getOrdersPerCustomerCount()
        );

        // order promotion with two products
        $this->orderWithPromotion($code, [$productId1, $productId2], $context);

        $promotion = $this->promotionRepository
            ->search(new Criteria([$promotionId]), Context::createDefaultContext())
            ->get($promotionId);
        static::assertNotNull($promotion);

        // verify that the promotion has a total order count of 1 and the current customer is although tracked
        static::assertSame(2, $promotion->getOrderCount());
        static::assertSame(
            [$context->getCustomerId() => 2],
            $promotion->getOrdersPerCustomerCount()
        );

        $customerId1 = $context->getCustomerId();

        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(
                Uuid::randomHex(),
                TestDefaults::SALES_CHANNEL,
                [SalesChannelContextService::CUSTOMER_ID => $this->createCustomer()]
            );

        static::assertNotNull($context->getCustomer());
        // order promotion with two products and another customer
        $this->orderWithPromotion($code, [$productId1, $productId2], $context);

        $promotion = $this->promotionRepository
            ->search(new Criteria([$promotionId]), Context::createDefaultContext())
            ->get($promotionId);
        static::assertNotNull($promotion);

        static::assertSame(3, $promotion->getOrderCount());
        $expected = [
            $context->getCustomerId() => 1,
            $customerId1 => 2,
        ];

        $actual = $promotion->getOrdersPerCustomerCount() ?? [];

        ksort($expected);
        ksort($actual);
        static::assertSame($expected, $actual);
    }

    /**
     * This test verifies that our composition data is correct.
     * We apply a discount that sells every item for 10 EUR.
     * We have a product with quantity 3 and total of 150 EUR and another product with 100 EUR.
     * Both our composition entries should have a discount of 120 (-3x10) and 90 EUR (-1x10).
     **/
    #[Group('promotions')]
    public function testCompositionInFixedUnitDiscount(): void
    {
        $productId1 = Uuid::randomHex();
        $productId2 = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF19';

        // add a new sample product
        $this->createTestFixtureProduct($productId1, 50, 19, static::getContainer(), $this->context);
        $this->createTestFixtureProduct($productId2, 100, 19, static::getContainer(), $this->context);

        // add a new promotion
        $this->createTestFixtureFixedUnitDiscountPromotion($promotionId, 10, PromotionDiscountEntity::SCOPE_CART, $code, static::getContainer(), $this->context);

        $cart = $this->cartService->getCart($this->context->getToken(), $this->context);

        // create product and add to cart
        $cart = $this->addProduct($productId1, 3, $cart, $this->cartService, $this->context);
        $cart = $this->addProduct($productId2, 1, $cart, $this->cartService, $this->context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $this->context);

        // get discount line item
        $discountItem = $cart->getLineItems()->getFlat()[2];

        static::assertTrue($discountItem->hasPayloadValue('composition'), 'composition node is missing');

        $composition = $discountItem->getPayload()['composition'];

        static::assertSame($productId1, $composition[0]['id']);
        static::assertSame(3, $composition[0]['quantity']);
        static::assertSame(120.0, $composition[0]['discount']);

        static::assertSame($productId2, $composition[1]['id']);
        static::assertSame(1, $composition[1]['quantity']);
        static::assertSame(90.0, $composition[1]['discount']);
    }

    /**
     * This test verifies that our composition data is correct.
     * We apply a discount that sells all item for a total of 70 EUR.
     * We have a product with quantity 3 and total of 150 EUR and another product with 100 EUR.
     * Both our composition entries should have a discount of 108 and 72 EUR which should
     * make the rest of it a total of 70 EUR.
     * The calculation is based on their proportionate distribution.
     **/
    #[Group('promotions')]
    public function testCompositionInFixedDiscount(): void
    {
        $productId1 = Uuid::randomHex();
        $productId2 = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF19';

        // add a new sample product
        $this->createTestFixtureProduct($productId1, 50, 19, static::getContainer(), $this->context);
        $this->createTestFixtureProduct($productId2, 100, 19, static::getContainer(), $this->context);

        // add a new promotion
        $this->createTestFixtureFixedDiscountPromotion($promotionId, 70, PromotionDiscountEntity::SCOPE_CART, $code, static::getContainer(), $this->context);

        $cart = $this->cartService->getCart($this->context->getToken(), $this->context);

        // create product and add to cart
        $cart = $this->addProduct($productId1, 3, $cart, $this->cartService, $this->context);
        $cart = $this->addProduct($productId2, 1, $cart, $this->cartService, $this->context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $this->context);

        // get discount line item
        $discountItem = $cart->getLineItems()->getFlat()[2];

        static::assertTrue($discountItem->hasPayloadValue('composition'), 'composition node is missing');

        $composition = $discountItem->getPayload()['composition'];

        static::assertSame($productId1, $composition[0]['id']);
        static::assertSame(3, $composition[0]['quantity']);
        static::assertSame(108.0, $composition[0]['discount']);

        static::assertSame($productId2, $composition[1]['id']);
        static::assertSame(1, $composition[1]['quantity']);
        static::assertSame(72.0, $composition[1]['discount']);
    }

    /**
     * @param array<string> $productIds
     */
    private function orderWithPromotion(string $code, array $productIds, SalesChannelContext $context): string
    {
        $cart = $this->cartService->createNew($context->getToken());

        foreach ($productIds as $productId) {
            $cart = $this->addProduct($productId, 3, $cart, $this->cartService, $context);
        }

        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $context);

        $promotions = $cart->getLineItems()->filterType('promotion');
        static::assertCount(1, $promotions);

        return $this->cartService->order($cart, $context, new RequestDataBag());
    }

    private function createCustomer(): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();

        $customer = [
            'id' => $customerId,
            'number' => '1337',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'customerNumber' => '1337',
            'email' => Uuid::randomHex() . '@example.com',
            'password' => TestDefaults::HASHED_PASSWORD,
            'groupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => $this->getValidCountryId(),
                    'salutationId' => $this->getValidSalutationId(),
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                ],
            ],
        ];

        static::getContainer()
            ->get('customer.repository')
            ->upsert([$customer], Context::createDefaultContext());

        return $customerId;
    }
}
