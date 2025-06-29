<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Cart\Facade;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Facade\CartFacade;
use Shopware\Core\Checkout\Cart\Facade\CartFacadeHookFactory;
use Shopware\Core\Checkout\Cart\Facade\ContainerFacade;
use Shopware\Core\Checkout\Cart\Facade\ItemFacade;
use Shopware\Core\Checkout\Cart\Facade\ItemsFacade;
use Shopware\Core\Checkout\Cart\Facade\PriceFacade;
use Shopware\Core\Checkout\Cart\Hook\CartHook;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Script\Execution\Script;
use Shopware\Core\Framework\Script\Execution\ScriptExecutor;
use Shopware\Core\Framework\Test\Script\Execution\TestHook;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\Test\AppSystemTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('checkout')]
class CartFacadeTest extends TestCase
{
    use AppSystemTestBehaviour;
    use IntegrationTestBehaviour;

    private IdsCollection $ids;

    private Script $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->init();
        $this->script = new Script('test', '', new \DateTimeImmutable());
    }

    #[DataProvider('addProductProvider')]
    public function testAddProduct(string $input, ?string $expected): void
    {
        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL, []);

        $hook = new CartHook($this->createCart(), $context);

        $service = static::getContainer()->get(CartFacadeHookFactory::class)
            ->factory($hook, $this->script);

        $service->products()->add($this->ids->get($input));
        $service->calculate();

        $item = $service->products()->get($this->ids->get($input));

        if ($expected === null) {
            static::assertNull($item);

            return;
        }

        static::assertInstanceOf(ItemFacade::class, $item);
        static::assertSame($this->ids->get($expected), $item->getReferencedId());
        static::assertSame(LineItem::PRODUCT_LINE_ITEM_TYPE, $item->getType());
    }

    public function testContainer(): void
    {
        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL, []);

        $hook = new CartHook($this->createCart(), $context);

        $service = static::getContainer()->get(CartFacadeHookFactory::class)
            ->factory($hook, $this->script);

        $id = $this->ids->get('p1');

        $product = $service->products()->add($id, 10);

        $container = $service->container('my-container');

        static::assertInstanceOf(ItemFacade::class, $product);
        static::assertInstanceOf(ContainerFacade::class, $container);

        $split = $product->take(1);
        static::assertInstanceOf(ItemFacade::class, $split);
        $container->add($split);

        $split = $product->take(1);
        static::assertInstanceOf(ItemFacade::class, $split);
        $container->add($split);
        $container->discount('my-discount', 'percentage', -10, 'Fancy discount');

        $surcharge = new PriceCollection([new Price(Defaults::CURRENCY, 2, 2, false)]);
        $container->surcharge('my-surcharge', 'absolute', $surcharge, 'unit test');

        $service->items()->add($container);
        $service->calculate();

        static::assertTrue($service->has('my-container'));
        $container = $service->get('my-container');

        static::assertInstanceOf(ItemFacade::class, $container);
        static::assertInstanceOf(PriceFacade::class, $container->getPrice());
        static::assertSame(182.0, $container->getPrice()->getTotal());
    }

    public function testRemove(): void
    {
        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL, []);

        $hook = new CartHook($this->createCart(), $context);
        $cart = static::getContainer()->get(CartFacadeHookFactory::class)->factory($hook, $this->script);

        $item = $cart->products()->add($this->ids->get('p1'));

        static::assertInstanceOf(ItemFacade::class, $item);
        static::assertSame($this->ids->get('p1'), $item->getReferencedId());
        static::assertSame(LineItem::PRODUCT_LINE_ITEM_TYPE, $item->getType());

        $cart->remove($item->getId());

        static::assertNull($cart->get($this->ids->get('p1')));
        $cart->remove('not-existing');
    }

    /**
     * @param array<string, ExpectedPrice|null> $expectations
     */
    #[DataProvider('scriptProvider')]
    public function testScripts(string $hook, array $expectations, ?\Closure $closure = null): void
    {
        $this->loadAppsFromDir(__DIR__ . '/_fixtures');

        $hook = $this->createTestHook($hook);

        $service = static::getContainer()
            ->get(CartFacadeHookFactory::class)
            ->factory($hook, $this->script);

        static::getContainer()->get(ScriptExecutor::class)->execute($hook);

        // add {% do debug.dump('foo') %} to debug scripts
        //         dump(static::getContainer()->get(ScriptTraces::class)->getTraces());

        $this->assertItems($service, $expectations);

        if ($closure instanceof \Closure) {
            $closure($service, $this->ids);
        }
    }

    public function testDependency(): void
    {
        $this->expectException(CartException::class);

        $service = static::getContainer()->get(CartFacadeHookFactory::class);
        $service->factory(new TestHook('test', Context::createDefaultContext()), $this->script);
    }

    public static function addProductProvider(): \Generator
    {
        yield 'Test with simple product' => ['p1', 'p1'];
        yield 'Test variant support' => ['v2.1', 'v2.1'];
        yield 'Test parents will not be added' => ['p2', null];
    }

    public static function scriptProvider(): \Generator
    {
        yield 'Test add product case' => [
            'add-product-cases',
            [
                'p1' => new ExpectedPrice(100),
                'v2.1' => new ExpectedPrice(100),
                'p2' => null,
            ],
        ];

        yield 'Test remove product' => [
            'remove-product-cases',
            [
                'p1' => null,
                'v2.1' => new ExpectedPrice(100),
            ],
        ];

        yield 'Add simple discount' => [
            'add-simple-discount',
            [
                'p1' => new ExpectedPrice(100),
                'my-discount' => new ExpectedPrice(-10),
            ],
        ];

        yield 'Add simple surcharge' => [
            'add-simple-surcharge',
            [
                'p1' => new ExpectedPrice(100),
                'my-surcharge' => new ExpectedPrice(10),
            ],
        ];

        yield 'Add discount for stacked items' => [
            'add-discount-for-stacked-items',
            [
                'p1' => new ExpectedPrice(100, 300),
                'my-discount' => new ExpectedPrice(-30),
            ],
        ];

        yield 'Add surcharge for stacked items' => [
            'add-surcharge-for-stacked-items',
            [
                'p1' => new ExpectedPrice(100, 300),
                'my-surcharge' => new ExpectedPrice(30),
            ],
        ];

        yield 'Add discount for multiple items' => [
            'add-discount-for-multiple-items',
            [
                'p1' => new ExpectedPrice(100),
                'v2.1' => new ExpectedPrice(100),
                'my-discount' => new ExpectedPrice(-20),
            ],
        ];

        yield 'Add surcharge for multiple items' => [
            'add-surcharge-for-multiple-items',
            [
                'p1' => new ExpectedPrice(100),
                'v2.1' => new ExpectedPrice(100),
                'my-surcharge' => new ExpectedPrice(20),
            ],
        ];

        yield 'Add absolute discount' => [
            'add-absolute-discount',
            [
                'p1' => new ExpectedPrice(100),
                'my-discount' => new ExpectedPrice(-19.99),
            ],
        ];

        yield 'Add absolute surcharge' => [
            'add-absolute-surcharge',
            [
                'p1' => new ExpectedPrice(100),
                'my-surcharge' => new ExpectedPrice(19.99),
            ],
        ];

        yield 'Test split product' => [
            'split-product',
            [
                'p1' => new ExpectedPrice(100, 300),
                'new-key' => new ExpectedPrice(100, 200),
            ],
        ];

        yield 'Test add container' => [
            'add-container',
            [
                'p1' => new ExpectedPrice(100, 300),
                'my-container' => [
                    'price' => new ExpectedPrice(180),
                    'children' => [
                        'first' => new ExpectedPrice(100),
                        'second' => new ExpectedPrice(100),
                        'discount' => new ExpectedPrice(-20),
                    ],
                ],
            ],
        ];

        yield 'Test nested containers' => [
            'add-nested-container',
            [
                'p1' => new ExpectedPrice(100),
                'my-container' => [
                    'price' => new ExpectedPrice(315),
                    'children' => [
                        'first' => new ExpectedPrice(100),
                        'second' => new ExpectedPrice(100),
                        'discount' => new ExpectedPrice(-35),
                        'nested' => [
                            'price' => new ExpectedPrice(150),
                            'children' => [
                                'third' => new ExpectedPrice(100),
                                'fourth' => new ExpectedPrice(100),
                                'absolute' => new ExpectedPrice(-50),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        yield 'Test payload' => [
            'payload-cases',
            [],
            function (CartFacade $service, IdsCollection $ids): void {
                $item = $service->get($ids->get('p1'));
                static::assertInstanceOf(ItemFacade::class, $item);

                $expected = ['test' => 1, 'foo' => 'bar', 'bar' => 'baz', 'baz' => true];
                foreach ($expected as $key => $value) {
                    static::assertArrayHasKey($key, $item->getItem()->getPayload());
                    $actual = $item->getItem()->getPayload()[$key];
                    static::assertSame($value, $actual, \sprintf('Payload value %s does not match', $key));
                }
            },
        ];

        yield 'Test add errors' => [
            'add-errors',
            [],
            function (CartFacade $cart): void {
                static::assertTrue($cart->errors()->has('NO_PRODUCTS_IN_CART'));
                static::assertTrue($cart->errors()->has('YOU_SHOULD_REALLY_ADD_PRODUCTS'));
                static::assertTrue($cart->errors()->has('ADD_PRODUCTS_OR_GO_AWAY'));
                static::assertTrue($cart->errors()->has('add-same-message'));
                static::assertTrue($cart->errors()->has('MESSAGE_WITH_PARAMETERS'));
            },
        ];

        yield 'Test cart states' => [
            'cart-state',
            [],
            function (CartFacade $cart): void {
                static::assertTrue($cart->states()->has('my-custom-state'));
                static::assertFalse($cart->states()->has('default-state'));
            },
        ];

        yield 'Discount product price' => [
            'discount-product-price',
            [
                'p1' => new ExpectedPrice(90, 90),
            ],
        ];
    }

    private function createCart(): Cart
    {
        $cart = new Cart('test');
        $cart->setBehavior(new CartBehavior());
        $cart->addState('default-state');

        return $cart;
    }

    /**
     * @param array<string, ExpectedPrice|null> $expectations
     */
    private function assertItems(ItemsFacade|CartFacade|LineItemCollection $scope, array $expectations): void
    {
        foreach ($expectations as $key => $expected) {
            if ($expected === null) {
                static::assertFalse($scope->has($key));

                continue;
            }

            if ($this->ids->has($key)) {
                $key = $this->ids->get($key);
            }
            static::assertTrue($scope->has($key), \sprintf('Can not find item %s', $key));
            $item = $scope->get($key);

            if ($expected instanceof CalculatedPrice) {
                static::assertInstanceOf(ItemFacade::class, $item);
                static::assertInstanceOf(PriceFacade::class, $item->getPrice());
                static::assertSame($expected->getUnitPrice(), $item->getPrice()->getUnit());
                static::assertSame($expected->getTotalPrice(), $item->getPrice()->getTotal());

                continue;
            }

            $price = $expected['price'];
            static::assertInstanceOf(ItemFacade::class, $item);
            static::assertInstanceOf(PriceFacade::class, $item->getPrice());
            static::assertSame($price->getUnitPrice(), $item->getPrice()->getUnit(), print_r($item->getItem(), true));
            static::assertSame($price->getTotalPrice(), $item->getPrice()->getTotal());

            $this->assertItems($item->getChildren(), $expected['children']);
        }
    }

    private function createTestHook(string $case): CartTestHook
    {
        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), TestDefaults::SALES_CHANNEL, []);

        $cart = $this->createCart();

        return new CartTestHook($case, $cart, $context, $this->ids, [CartFacadeHookFactory::class]);
    }

    private function init(): IdsCollection
    {
        $this->ids = new IdsCollection();
        $products = [
            (new ProductBuilder($this->ids, 'p1'))
                ->price(100)
                ->visibility()
                ->build(),
            (new ProductBuilder($this->ids, 'p2'))
                ->price(100)
                ->variant(
                    (new ProductBuilder($this->ids, 'v2.1'))
                        ->option('red', 'color')
                        ->build()
                )
                ->visibility()
                ->build(),
        ];

        static::getContainer()->get('product.repository')
            ->create($products, Context::createDefaultContext());

        return $this->ids;
    }
}

/**
 * @internal
 */
#[Package('checkout')]
class ExpectedPrice extends CalculatedPrice
{
    public function __construct(
        float $unitPrice,
        ?float $totalPrice = null,
        ?CalculatedTaxCollection $calculatedTaxes = null,
        ?TaxRuleCollection $taxRules = null,
        int $quantity = 1
    ) {
        $totalPrice ??= $unitPrice;
        $calculatedTaxes ??= new CalculatedTaxCollection([]);
        $taxRules ??= new TaxRuleCollection([]);

        parent::__construct($unitPrice, $totalPrice, $calculatedTaxes, $taxRules, $quantity);
    }
}
