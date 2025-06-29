<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Cart\Promotion\Integration\Calculation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\Test\Integration\Traits\Promotion\PromotionIntegrationTestBehaviour;
use Shopware\Core\Test\Integration\Traits\Promotion\PromotionTestFixtureBehaviour;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('checkout')]
class PromotionAbsoluteCalculationTest extends TestCase
{
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

        $this->context = static::getContainer()->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);
        $this->productRepository = static::getContainer()->get('product.repository');
        $this->promotionRepository = static::getContainer()->get('promotion.repository');
        $this->cartService = static::getContainer()->get(CartService::class);
    }

    /**
     * This test verifies that our absolute promotions are correctly added.
     * We add a product and also an absolute promotion.
     * Our final price should then be as expected.
     *
     * @throws CartException
     */
    #[Group('promotions')]
    public function testAbsoluteDiscount(): void
    {
        $productId = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF' . Random::getAlphanumericString(5);

        $context = static::getContainer()->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);

        // add a new sample product
        $this->createTestFixtureProduct($productId, 60, 17, static::getContainer(), $context);

        // add a new promotion black friday
        $this->createTestFixtureAbsolutePromotion($promotionId, $code, 45, static::getContainer());

        $cart = $this->cartService->getCart($context->getToken(), $context);

        // create product and add to cart
        $cart = $this->addProduct($productId, 2, $cart, $this->cartService, $context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $context);

        static::assertSame(75.0, $cart->getPrice()->getTotalPrice());
        static::assertSame(75.0, $cart->getPrice()->getPositionPrice());
        static::assertSame(64.1, $cart->getPrice()->getNetPrice());
    }

    /**
     * This test verifies that our promotion components are really involved in our checkout.
     * We add a product to the cart and apply a code for a promotion with a currency dependent discount.
     * The standard value of discount would be 15, but our currency price value is 30
     * Our cart should have a total value of 70,00 (and not 85 as standard) in the end.
     */
    #[Group('promotions')]
    public function testAbsoluteDiscountWithCurrencyPriceValues(): void
    {
        $productId = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF' . Random::getAlphanumericString(5);
        $context = static::getContainer()->get(SalesChannelContextFactory::class)->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL);

        // add a new sample product
        $this->createTestFixtureProduct($productId, 100, 19, static::getContainer(), $context);

        $this->createAdvancedCurrencyPriceValuePromotion($promotionId, $code, 15, 30);

        $cart = $this->cartService->getCart($context->getToken(), $context);

        // create product and add to cart
        $cart = $this->addProduct($productId, 1, $cart, $this->cartService, $context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $context);

        static::assertSame(70.0, $cart->getPrice()->getPositionPrice());
        static::assertSame(70.0, $cart->getPrice()->getTotalPrice());
        static::assertSame(58.82, $cart->getPrice()->getNetPrice());
    }

    public function testNetCustomerAbsoluteDiscountHigherThanCartTotal(): void
    {
        $productId = Uuid::randomHex();
        $promotionId = Uuid::randomHex();
        $code = 'BF' . Random::getAlphanumericString(5);

        $context = static::getContainer()
            ->get(SalesChannelContextFactory::class)
            ->create(
                Uuid::randomHex(),
                TestDefaults::SALES_CHANNEL,
                [SalesChannelContextService::CUSTOMER_ID => $this->createNetCustomer()]
            );

        $this->createTestFixtureProduct($productId, 100, 19, static::getContainer(), $context);

        $this->createAdvancedCurrencyPriceValuePromotion($promotionId, $code, 300, 600);

        $cart = $this->cartService->getCart($context->getToken(), $context);

        // create product and add to cart
        $cart = $this->addProduct($productId, 1, $cart, $this->cartService, $context);

        // create promotion and add to cart
        $cart = $this->addPromotionCode($code, $cart, $this->cartService, $context);

        static::assertSame(0.0, $cart->getPrice()->getPositionPrice());
        static::assertSame(0.0, $cart->getPrice()->getTotalPrice());
        static::assertSame(0.0, $cart->getPrice()->getNetPrice());
    }

    /**
     * create a promotion with a currency based price value discount.
     */
    private function createAdvancedCurrencyPriceValuePromotion(string $promotionId, string $code, float $discountPrice, float $advancedPrice): void
    {
        $discountId = Uuid::randomHex();

        $this->promotionRepository->create(
            [
                [
                    'id' => $promotionId,
                    'name' => 'Black Friday',
                    'active' => true,
                    'code' => $code,
                    'useCodes' => true,
                    'salesChannels' => [
                        ['salesChannelId' => TestDefaults::SALES_CHANNEL, 'priority' => 1],
                    ],
                    'discounts' => [
                        [
                            'id' => $discountId,
                            'scope' => PromotionDiscountEntity::SCOPE_CART,
                            'type' => PromotionDiscountEntity::TYPE_ABSOLUTE,
                            'value' => $discountPrice,
                            'considerAdvancedRules' => false,
                            'promotionDiscountPrices' => [
                                [
                                    'currencyId' => Defaults::CURRENCY,
                                    'discountId' => $discountId,
                                    'price' => $advancedPrice,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            Context::createDefaultContext()
        );
    }

    private function createNetCustomer(): string
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
            'groupId' => $this->createNetCustomerGroup(),
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

    private function createNetCustomerGroup(): string
    {
        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'displayGross' => false,
            'translations' => [
                'en-GB' => [
                    'name' => 'Net price customer group',
                ],
                'de-DE' => [
                    'name' => 'Nettopreis-Kundengruppe',
                ],
            ],
        ];

        static::getContainer()->get('customer_group.repository')->create([$data], Context::createDefaultContext());

        return $id;
    }
}
