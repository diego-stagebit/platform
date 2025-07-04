<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Document\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Document\Event\DeliveryNoteOrdersEvent;
use Shopware\Core\Checkout\Document\Renderer\DeliveryNoteRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Service\HtmlRenderer;
use Shopware\Core\Checkout\Document\Service\PdfRenderer;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\TestDefaults;
use Shopware\Tests\Integration\Core\Checkout\Document\DocumentTrait;

/**
 * @internal
 */
#[Package('after-sales')]
class DeliveryNoteRendererTest extends TestCase
{
    use DocumentTrait;

    private SalesChannelContext $salesChannelContext;

    private Context $context;

    private DeliveryNoteRenderer $deliveryNoteRenderer;

    private CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = Context::createDefaultContext();

        $priceRuleId = Uuid::randomHex();

        $this->salesChannelContext = static::getContainer()->get(SalesChannelContextFactory::class)->create(
            Uuid::randomHex(),
            TestDefaults::SALES_CHANNEL,
            [
                SalesChannelContextService::CUSTOMER_ID => $this->createCustomer(),
            ]
        );

        $this->salesChannelContext->setRuleIds([$priceRuleId]);
        $this->deliveryNoteRenderer = static::getContainer()->get(DeliveryNoteRenderer::class);
        $this->cartService = static::getContainer()->get(CartService::class);
    }

    #[DataProvider('deliveryNoteRendererDataProvider')]
    public function testRender(string $deliveryNoteNumber, \Closure $assertionCallback): void
    {
        $cart = $this->generateDemoCart(3);

        $orderId = $this->cartService->order($cart, $this->salesChannelContext, new RequestDataBag());

        $operation = new DocumentGenerateOperation($orderId, HtmlRenderer::FILE_EXTENSION, [
            'documentNumber' => $deliveryNoteNumber,
            'itemsPerPage' => 2,
            'fileTypes' => [PdfRenderer::FILE_EXTENSION, HtmlRenderer::FILE_EXTENSION],
        ]);

        $caughtEvent = null;

        static::getContainer()->get('event_dispatcher')
            ->addListener(DeliveryNoteOrdersEvent::class, function (DeliveryNoteOrdersEvent $event) use (&$caughtEvent): void {
                $caughtEvent = $event;
            });

        $processedTemplate = $this->deliveryNoteRenderer->render(
            [$orderId => $operation],
            $this->context,
            new DocumentRendererConfig()
        );

        static::assertInstanceOf(DeliveryNoteOrdersEvent::class, $caughtEvent);
        static::assertCount(1, $caughtEvent->getOperations());
        static::assertSame($operation, $caughtEvent->getOperations()[$orderId] ?? null);
        static::assertCount(1, $caughtEvent->getOrders());
        static::assertArrayHasKey($orderId, $processedTemplate->getSuccess());
        $rendered = $processedTemplate->getSuccess()[$orderId];
        $order = $caughtEvent->getOrders()->get($orderId);
        static::assertNotNull($order);

        static::assertInstanceOf(RenderedDocument::class, $rendered);
        static::assertCount(1, $caughtEvent->getOrders());
        static::assertStringContainsString('<html lang="en-GB">', $rendered->getContent());
        static::assertStringContainsString('</html>', $rendered->getContent());

        $assertionCallback($deliveryNoteNumber, $order->getOrderNumber(), $rendered);
    }

    public static function deliveryNoteRendererDataProvider(): \Generator
    {
        yield 'render delivery_note successfully' => [
            '2000',
            function (string $deliveryNoteNumber, string $orderNumber, RenderedDocument $rendered): void {
                $html = $rendered->getContent();
                static::assertStringContainsString('<html lang="en-GB">', $html);
                static::assertStringContainsString('</html>', $html);

                static::assertStringContainsString('Delivery note ' . $deliveryNoteNumber, $html);
                static::assertStringContainsString(\sprintf('Delivery note %s for Order %s ', $deliveryNoteNumber, $orderNumber), $html);
            },
        ];

        yield 'render delivery_note with document number' => [
            'DELIVERY_NOTE_9999',
            function (string $deliveryNoteNumber, string $orderNumber, RenderedDocument $rendered): void {
                static::assertSame('DELIVERY_NOTE_9999', $rendered->getNumber());
                static::assertSame('delivery_note_DELIVERY_NOTE_9999', $rendered->getName());

                static::assertStringContainsString("Delivery note $deliveryNoteNumber for Order $orderNumber", $rendered->getContent());
                static::assertStringContainsString("Delivery note $deliveryNoteNumber for Order $orderNumber", $rendered->getContent());
            },
        ];
    }

    public function testCreatingNewOrderVersionId(): void
    {
        $cart = $this->generateDemoCart(1);
        $orderId = $this->persistCart($cart);

        $operationDelivery = new DocumentGenerateOperation($orderId);

        static::assertSame($operationDelivery->getOrderVersionId(), Defaults::LIVE_VERSION);

        $this->deliveryNoteRenderer->render(
            [$orderId => $operationDelivery],
            $this->context,
            new DocumentRendererConfig()
        );

        static::assertNotSame($operationDelivery->getOrderVersionId(), Defaults::LIVE_VERSION);
    }
}
