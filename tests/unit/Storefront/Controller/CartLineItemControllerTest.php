<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Storefront\Controller;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\ProductLineItemFactory;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Promotion\Cart\PromotionCartAddedInformationError;
use Shopware\Core\Checkout\Promotion\Cart\PromotionItemBuilder;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\ProductListResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Util\HtmlSanitizer;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\CartLineItemController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
#[CoversClass(CartLineItemController::class)]
class CartLineItemControllerTest extends TestCase
{
    private CartLineItemController $controller;

    private LineItemFactoryRegistry&MockObject $lineItemRegistryMock;

    private CartService&MockObject $cartService;

    private ContainerInterface&MockObject $container;

    private PromotionItemBuilder&MockObject $promotionItemBuilderMock;

    private AbstractProductListRoute&MockObject $productListRouteMock;

    private ProductLineItemFactory&MockObject $productLineItemFactoryMock;

    protected function setUp(): void
    {
        $this->lineItemRegistryMock = $this->createMock(LineItemFactoryRegistry::class);
        $this->cartService = $this->createMock(CartService::class);
        $this->promotionItemBuilderMock = $this->createMock(PromotionItemBuilder::class);
        $this->productListRouteMock = $this->createMock(AbstractProductListRoute::class);
        $this->productLineItemFactoryMock = $this->createMock(ProductLineItemFactory::class);

        $this->controller = new CartLineItemController(
            $this->cartService,
            $this->promotionItemBuilderMock,
            $this->productLineItemFactoryMock,
            $this->createMock(HtmlSanitizer::class),
            $this->productListRouteMock,
            $this->lineItemRegistryMock,
        );

        $this->container = $this->createMock(ContainerInterface::class);

        $this->controller->setContainer($this->container);
    }

    public function testAddLineItemsCallsLineItemWithPayload(): void
    {
        $productId = Uuid::randomHex();
        $lineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'product',
            'stackable' => 1,
            'removable' => 1,
            'quantity' => 1,
            'payload' => '{"some": "value"}',
        ];

        $expectedLineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'product',
            'stackable' => 1,
            'removable' => 1,
            'quantity' => 1,
            'payload' => ['some' => 'value'],
        ];

        $request = new Request([], ['lineItems' => [$productId => $lineItemData]]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $expectedLineItem = new LineItem($productId, 'product');

        $this->lineItemRegistryMock->expects($this->once())
            ->method('create')
            ->with($expectedLineItemData, $this->createMock(SalesChannelContext::class))
            ->willReturn($expectedLineItem);

        $this->translatorCallback();

        $this->controller->addLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);
    }

    public function testAddLineItemsCallsLineItemSetDefaultValues(): void
    {
        $productId = Uuid::randomHex();
        $productId2 = Uuid::randomHex();
        $lineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'product',
            'priceDefinition' => [
                'quantity' => 5,
                'isCalculated' => 1,
            ],
        ];
        $lineItemData2 = [
            'id' => $productId2,
            'referencedId' => $productId2,
            'type' => 'product',
        ];

        $expectedLineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'product',
            'stackable' => true,
            'removable' => true,
            'quantity' => 1,
            'priceDefinition' => [
                'quantity' => 5,
                'isCalculated' => 1,
            ],
        ];

        $expectedLineItemData2 = [
            'id' => $productId2,
            'referencedId' => $productId2,
            'type' => 'product',
            'stackable' => true,
            'removable' => true,
            'quantity' => 1,
        ];

        $request = new Request(
            [],
            [
                'lineItems' => [
                    $productId => $lineItemData,
                    $productId2 => $lineItemData2,
                ],
            ]
        );

        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $matcher = $this->exactly(2);
        $this->lineItemRegistryMock->expects($matcher)->method('create')
            ->willReturnCallback(
                function (array $lineItemDataPar, SalesChannelContext $contextPar) use (
                    $matcher,
                    $expectedLineItemData,
                    $expectedLineItemData2
                ) {
                    match ($matcher->numberOfInvocations()) {
                        default => static::fail('to many calls of create'),
                        2 => static::assertEquals($expectedLineItemData2, $lineItemDataPar),
                        1 => static::assertEquals($expectedLineItemData, $lineItemDataPar),
                    };

                    return new LineItem($lineItemDataPar['id'], 'product');
                }
            );

        $this->translatorCallback();

        $this->controller->addLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);
    }

    public function testAddLineItemsCallsLineItemWithTooBigPayload(): void
    {
        $productId = Uuid::randomHex();
        $bigVal = '';

        for ($x = 0; $x < 9999; ++$x) {
            $bigVal .= 'dsadasdasdasfweat34wt4etgea';
        }

        $lineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'product',
            'stackable' => 1,
            'removable' => 1,
            'quantity' => 1,
            'payload' => $bigVal,
        ];

        $request = new Request([], ['lineItems' => [$productId => $lineItemData]]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->controller->addLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);

        static::assertCount(1, $session->getFlashBag()->all());
    }

    public function testAddLineItemsCallsLineItemFactory(): void
    {
        $productId = Uuid::randomHex();
        $lineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'product',
            'stackable' => 1,
            'removable' => 1,
            'quantity' => 1,
        ];

        $request = new Request([], ['lineItems' => [$productId => $lineItemData]]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $expectedLineItem = new LineItem($productId, 'product');

        $this->lineItemRegistryMock->expects($this->once())
            ->method('create')
            ->with($lineItemData, $this->createMock(SalesChannelContext::class))
            ->willReturn($expectedLineItem);

        $this->cartService->expects($this->once())
            ->method('add')
            ->with($cart, [$expectedLineItem], $context)
            ->willReturn($cart);

        $this->translatorCallback();

        $this->controller->addLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);
    }

    public function testAddLineItemsCartExceptionWillBeThrown(): void
    {
        $productId = Uuid::randomHex();
        $lineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'nonexistenttype',
            'stackable' => 1,
            'removable' => 1,
            'quantity' => 1,
        ];

        $request = new Request([], ['lineItems' => [$productId => $lineItemData]]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $exception = CartException::invalidPriceDefinition();
        $this->lineItemRegistryMock->expects($this->once())
            ->method('create')
            ->with($lineItemData, $this->createMock(SalesChannelContext::class))
            ->willThrowException($exception);

        $this->cartService->expects($this->never())->method('add');

        $this->expectExceptionObject($exception);
        $this->controller->addLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);
    }

    public function testAddLineItemsCartExceptionWillBeThrownQuantity(): void
    {
        $productId = Uuid::randomHex();
        $lineItemData = [
            'id' => $productId,
            'referencedId' => $productId,
            'type' => 'nonexistenttype',
            'stackable' => 1,
            'removable' => 1,
            'quantity' => 1,
        ];

        $request = new Request([], ['lineItems' => [$productId => $lineItemData]]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $exception = CartException::invalidQuantity(1);
        $this->lineItemRegistryMock->expects($this->once())
            ->method('create')
            ->with($lineItemData, $this->createMock(SalesChannelContext::class))
            ->willThrowException($exception);

        $this->cartService->expects($this->never())->method('add');

        $this->translatorCallback();

        $this->controller->addLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);
    }

    public function testAddByProductNumber(): void
    {
        $productNumber = Uuid::randomHex();
        $id = Uuid::randomHex();
        $request = new Request([], ['number' => $productNumber]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $product = new ProductEntity();
        $product->setUniqueIdentifier($id);
        $product->setId($id);
        $item = new LineItem($id, PromotionProcessor::LINE_ITEM_TYPE);

        $cart->add($item);
        $this->productListRouteMock->expects($this->once())
            ->method('load')
            ->willReturn(
                new ProductListResponse(
                    new EntitySearchResult(
                        ProductDefinition::ENTITY_NAME,
                        1,
                        new ProductCollection([$product]),
                        null,
                        new Criteria(),
                        Context::createDefaultContext()
                    )
                )
            );

        $this->productLineItemFactoryMock->expects($this->once())->method('create')->willReturn($item);

        $this->cartService->expects($this->once())
            ->method('getCart')->willReturn($cart);

        $this->cartService->expects($this->once())
            ->method('add')
            ->with($cart, $item, $context)
            ->willReturn($cart);

        $this->translatorCallback();

        $this->controller->addProductByNumber($request, $context);
    }

    public function testAddByProductNumberNotFound(): void
    {
        $productNumber = Uuid::randomHex();
        $id = Uuid::randomHex();
        $request = new Request([], ['number' => $productNumber]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $product = new ProductEntity();
        $product->setUniqueIdentifier($id);
        $product->setId($id);
        $item = new LineItem($id, PromotionProcessor::LINE_ITEM_TYPE);

        $cart->add($item);
        $this->productListRouteMock->expects($this->once())
            ->method('load')
            ->willReturn(
                new ProductListResponse(
                    new EntitySearchResult(
                        ProductDefinition::ENTITY_NAME,
                        0,
                        new ProductCollection([]),
                        null,
                        new Criteria(),
                        Context::createDefaultContext()
                    )
                )
            );

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $response = $this->controller->addProductByNumber($request, $context);

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    public function testAddPromotion(): void
    {
        $code = Uuid::randomHex();

        $request = new Request([], ['code' => $code]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $uniqueKey = PromotionItemBuilder::PLACEHOLDER_PREFIX . $code;
        $item = new LineItem($uniqueKey, PromotionProcessor::LINE_ITEM_TYPE);
        $item->setLabel($code);
        $cart->addErrors(new PromotionCartAddedInformationError($item));

        $this->promotionItemBuilderMock->method('buildPlaceholderItem')->willReturn($item);

        $this->cartService->expects($this->once())
            ->method('add')
            ->with($cart, $item, $context)
            ->willReturn($cart);

        $this->translatorCallback();

        $this->controller->addPromotion($cart, $request, $context);
    }

    public function testAddPromotionOtherExceptions(): void
    {
        $code = Uuid::randomHex();

        $request = new Request([], ['code' => $code]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $uniqueKey = PromotionItemBuilder::PLACEHOLDER_PREFIX . $code;
        $item = new LineItem($uniqueKey, PromotionProcessor::LINE_ITEM_TYPE);
        $item->setLabel($code);
        $cart->addErrors(new GenericCartError('d', 's', [], 0, false, true, false));

        $this->promotionItemBuilderMock->method('buildPlaceholderItem')->willReturn($item);

        $this->cartService->expects($this->once())
            ->method('add')
            ->with($cart, $item, $context)
            ->willReturn($cart);

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->controller->addPromotion($cart, $request, $context);
    }

    public function testAddPromotionNoCode(): void
    {
        $code = '';

        $request = new Request([], ['code' => $code]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);
        $uniqueKey = PromotionItemBuilder::PLACEHOLDER_PREFIX . $code;
        $item = new LineItem($uniqueKey, PromotionProcessor::LINE_ITEM_TYPE);

        $this->promotionItemBuilderMock->method('buildPlaceholderItem')->willReturn($item);

        $this->cartService->expects($this->never())
            ->method('add');

        $this->translatorCallback();

        $this->controller->addPromotion($cart, $request, $context);
    }

    public function testChangeQuantity(): void
    {
        $id = Uuid::randomHex();

        $request = new Request(['quantity' => 3]);
        $cart = new Cart(Uuid::randomHex());
        $cart->addLineItems(new LineItemCollection([new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE)]));
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->once())
            ->method('changeQuantity')
            ->with($cart, $id, 3, $context)
            ->willReturn($cart);

        $this->translatorCallback();

        $this->controller->changeQuantity($cart, $id, $request, $context);
    }

    public function testChangeQuantityNoParam(): void
    {
        $id = Uuid::randomHex();

        $request = new Request([]);
        $cart = new Cart(Uuid::randomHex());
        $cart->addLineItems(new LineItemCollection([new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE)]));
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->never())
            ->method('changeQuantity');

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->controller->changeQuantity($cart, $id, $request, $context);

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    public function testChangeQuantityNoLineItem(): void
    {
        $id = Uuid::randomHex();

        $request = new Request(['quantity' => 3]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->never())
            ->method('changeQuantity');

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->controller->changeQuantity($cart, $id, $request, $context);

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    public function testDeleteLineItem(): void
    {
        $id = Uuid::randomHex();

        $request = new Request([]);
        $cart = new Cart(Uuid::randomHex());
        $cart->addLineItems(new LineItemCollection([new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE)]));
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->once())
            ->method('remove')
            ->with($cart, $id, $context)
            ->willReturn($cart);

        $this->translatorCallback();

        $this->controller->deleteLineItem($cart, $id, $request, $context);
    }

    public function testDeleteLineItemNotInCart(): void
    {
        $id = Uuid::randomHex();

        $request = new Request([]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->never())
            ->method('remove');

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->controller->deleteLineItem($cart, $id, $request, $context);

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    public function testDeleteLineItems(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $ids = [$id1, $id2];

        $request = new Request([], ['ids' => $ids]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->once())
            ->method('removeItems')
            ->with($cart, $ids, $context)
            ->willReturn($cart);

        $this->translatorCallback();

        $this->controller->deleteLineItems($cart, $request, $context);
    }

    public function testDeleteLineItemsMissingIdsParameter(): void
    {
        $request = new Request();
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->never())->method('remove');

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->controller->deleteLineItems($cart, $request, $context);

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    public function testDeleteLineItemsWrongParameter(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = 123;
        $ids = [$id1, $id2];

        $request = new Request([], ['ids' => $ids]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->never())->method('remove');

        $stack = $this->createMock(RequestStack::class);
        $session = new Session(new MockArraySessionStorage());
        $stack->method('getSession')->willReturn($session);
        $this->container->method('get')
            ->willReturnCallback(function ($id) use ($stack) {
                if ($id === 'translator') {
                    return $this->createMock(TranslatorInterface::class);
                }

                if ($id === 'request_stack') {
                    return $stack;
                }

                return null;
            });

        $this->controller->deleteLineItems($cart, $request, $context);

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    public function testUpdateLineItems(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $lineItems = [
            [
                'id' => $id1,
                'quantity' => 5,
                'stackable' => false,
                'priceDefinition' => [
                    'quantity' => 5,
                    'isCalculated' => 1,
                ],
            ],
            [
                'id' => $id2,
                'removable' => false,
            ],
        ];

        $request = new Request([], ['lineItems' => $lineItems]);
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->once())
            ->method('update')
            ->with($cart, $lineItems, $context)
            ->willReturnCallback(function ($cart, $lineItems, $context) use ($id1, $id2) {
                $expectedLineitem = new LineItem($id1, LineItem::PRODUCT_LINE_ITEM_TYPE);
                $expectedLineitem2 = new LineItem($id2, LineItem::PRODUCT_LINE_ITEM_TYPE);
                $expectedLineitems = [$expectedLineitem, $expectedLineitem2];
                static::assertSame($expectedLineitems, $lineItems);

                return $cart;
            });

        $this->translatorCallback();

        $this->controller->updateLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);
    }

    public function testDeleteLineItemsMissingParameter(): void
    {
        $request = new Request();
        $cart = new Cart(Uuid::randomHex());
        $context = $this->createMock(SalesChannelContext::class);

        $this->cartService->expects($this->never())->method('update');

        $stack = $this->createMock(RequestStack::class);
        $session = new Session(new MockArraySessionStorage());
        $stack->method('getSession')->willReturn($session);
        $this->container->method('get')
            ->willReturnCallback(function ($id) use ($stack) {
                if ($id === 'translator') {
                    return $this->createMock(TranslatorInterface::class);
                }

                if ($id === 'request_stack') {
                    return $stack;
                }

                return null;
            });

        $this->controller->updateLineItems($cart, new RequestDataBag($request->request->all()), $request, $context);

        static::assertArrayHasKey('danger', $session->getFlashBag()->peekAll());
    }

    /**
     * @param class-string<Error> $class
     */
    #[DataProvider('errorProvider')]
    public function testFilterErrorSuccessMessages(string $class, bool $filtered): void
    {
        $id = Uuid::randomHex();

        $request = new Request(['quantity' => 1]);
        $cart = new Cart(Uuid::randomHex());
        $cart->addLineItems(new LineItemCollection([new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE)]));

        $session = new Session(new MockArraySessionStorage());
        $this->translatorCallback($session);

        $this->cartService
            ->expects($this->once())
            ->method('changeQuantity')
            ->willReturn($cart);

        /** @var Error&MockObject $error */
        $error = $this->createMock($class);
        $cart->addErrors($error);

        $this->controller->changeQuantity($cart, $id, $request, $this->createMock(SalesChannelContext::class));

        if ($filtered) {
            static::assertCount(0, $cart->getErrors());

            static::assertArrayHasKey('success', $session->getFlashBag()->peekAll());
        } else {
            static::assertCount(1, $cart->getErrors());
        }
    }

    /**
     * @return list<array{class-string<Error>, bool}>
     */
    public static function errorProvider(): array
    {
        $filtered = [
            PromotionCartAddedInformationError::class,
        ];

        $classLoader = require __DIR__ . '/../../../../vendor/autoload.php';
        static::assertInstanceOf(ClassLoader::class, $classLoader);

        $errors = [];
        foreach ($classLoader->getClassMap() as $class => $_) {
            if (!str_starts_with($class, 'Shopware\\')) {
                continue;
            }

            if ($class !== Error::class && !\is_subclass_of($class, Error::class)) {
                continue;
            }

            $refClass = new \ReflectionClass($class);
            if ($refClass->isAbstract()) {
                continue;
            }

            $errors[] = [$class, \in_array($class, $filtered, true)];
        }

        return $errors;
    }

    private function translatorCallback(?Session $session = null): void
    {
        if (!$session instanceof Session) {
            $session = new Session(new MockArraySessionStorage());
        }
        $stack = $this->createMock(RequestStack::class);
        $stack->method('getSession')->willReturn($session);
        $this->container->method('get')
            ->willReturnCallback(function ($id) use ($stack) {
                if ($id === 'translator') {
                    return $this->createMock(TranslatorInterface::class);
                }

                if ($id === 'request_stack') {
                    return $stack;
                }

                return null;
            });
    }
}
