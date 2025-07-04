<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\System\SalesChannel\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\MediaUrlPlaceholderHandlerInterface;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Test\TestCaseHelper\CallableClass;
use Shopware\Core\System\SalesChannel\Api\StoreApiResponseListener;
use Shopware\Core\System\SalesChannel\Api\StructEncoder;
use Shopware\Core\System\SalesChannel\GenericStoreApiResponse;
use Shopware\Core\System\SalesChannel\StoreApiResponse;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(StoreApiResponseListener::class)]
class StoreApiResponseListenerTest extends TestCase
{
    private StructEncoder&MockObject $encoder;

    private MediaUrlPlaceholderHandlerInterface&MockObject $mediaUrlPlaceholderHandler;

    private SeoUrlPlaceholderHandlerInterface&MockObject $seoUrlPlaceholderHandler;

    private StoreApiResponseListener $listener;

    protected function setUp(): void
    {
        $this->encoder = $this->createMock(StructEncoder::class);
        $this->mediaUrlPlaceholderHandler = $this->createMock(MediaUrlPlaceholderHandlerInterface::class);
        $this->mediaUrlPlaceholderHandler->method('replace')->willReturnArgument(0);
        $this->seoUrlPlaceholderHandler = $this->createMock(SeoUrlPlaceholderHandlerInterface::class);
        $this->seoUrlPlaceholderHandler->method('replace')->willReturnArgument(0);
        $this->listener = new StoreApiResponseListener($this->encoder, new EventDispatcher(), $this->seoUrlPlaceholderHandler, $this->mediaUrlPlaceholderHandler);
    }

    public function testEncodeEvent(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'store-api.my-route');

        $listener = $this->createMock(CallableClass::class);
        $listener->expects($this->exactly(1))->method('__invoke');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('store-api.my-route.encode', $listener);

        $instance = new StoreApiResponseListener(
            $this->createMock(StructEncoder::class),
            $dispatcher,
            $this->seoUrlPlaceholderHandler,
            $this->mediaUrlPlaceholderHandler
        );

        $instance->encodeResponse(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new GenericStoreApiResponse(200, new ArrayStruct())
        ));
    }

    public function testEncodeResponseWithIncludesSpecialCharacters(): void
    {
        $this->encoder->expects($this->once())
            ->method('encode')
            ->willReturn(['encoded' => 'data']);

        $responseObject = new class extends Struct {};

        $response = $this->createMock(StoreApiResponse::class);
        $response->method('getObject')
            ->willReturn($responseObject);
        $response->method('getStatusCode')
            ->willReturn(200);
        $response->headers = new ResponseHeaderBag();

        $request = new Request();
        $request->query->set('includes', 'field1!@#$%^&*(),field2');

        $kernel = $this->createMock(HttpKernelInterface::class);

        $event = new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $this->listener->encodeResponse($event);

        $response = $event->getResponse();
        static::assertInstanceOf(JsonResponse::class, $response);
        $content = $response->getContent();
        static::assertIsString($content, 'Response content is not a string.');
        $decoded = json_decode($content, true);
        static::assertIsArray($decoded, 'Decoded JSON is not an array.');
        static::assertSame(['encoded' => 'data'], $decoded);
    }

    public function testEncodeResponseWithDifferentStatusCode(): void
    {
        $this->encoder->expects($this->once())
            ->method('encode')
            ->willReturn(['encoded' => 'data']);

        $responseObject = new class extends Struct {};

        $response = $this->createMock(StoreApiResponse::class);
        $response->method('getObject')
            ->willReturn($responseObject);
        $response->method('getStatusCode')
            ->willReturn(404);
        $response->headers = new ResponseHeaderBag();

        $request = new Request();
        $request->query->set('includes', []);

        $kernel = $this->createMock(HttpKernelInterface::class);

        $event = new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $this->listener->encodeResponse($event);

        $response = $event->getResponse();
        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame(404, $response->getStatusCode());
        $content = $response->getContent();
        static::assertIsString($content, 'Response content is not a string.');
        $decoded = json_decode($content, true);
        static::assertIsArray($decoded, 'Decoded JSON is not an array.');
        static::assertSame(['encoded' => 'data'], $decoded);
    }

    public function testEncodeResponsePreservesHeaders(): void
    {
        $this->encoder->expects($this->once())
            ->method('encode')
            ->willReturn(['encoded' => 'data']);

        $responseObject = new class extends Struct {};

        $response = $this->createMock(StoreApiResponse::class);
        $response->method('getObject')
            ->willReturn($responseObject);
        $response->method('getStatusCode')
            ->willReturn(200);
        $response->headers = new ResponseHeaderBag();
        $response->headers->set('X-Custom-Header', 'value');

        $request = new Request();
        $request->query->set('includes', []);

        $kernel = $this->createMock(HttpKernelInterface::class);

        $event = new ResponseEvent(
            $kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );

        $this->listener->encodeResponse($event);

        $response = $event->getResponse();
        static::assertInstanceOf(JsonResponse::class, $response);
        static::assertSame('value', $response->headers->get('X-Custom-Header'));
        $content = $response->getContent();
        static::assertIsString($content, 'Response content is not a string.');
        $decoded = json_decode($content, true);
        static::assertIsArray($decoded, 'Decoded JSON is not an array.');
        static::assertSame(['encoded' => 'data'], $decoded);
    }
}
