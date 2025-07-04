<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Webhook\Service;

use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestInterface;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\App\AppLocaleProvider;
use Shopware\Core\Framework\App\Event\AppFlowActionEvent;
use Shopware\Core\Framework\App\Hmac\RequestSigner;
use Shopware\Core\Framework\App\Payload\AppPayloadServiceHelper;
use Shopware\Core\Framework\App\Payload\Source;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\AclPrivilegeCollection;
use Shopware\Core\Framework\Webhook\Hookable\HookableEntityWrittenEvent;
use Shopware\Core\Framework\Webhook\Hookable\HookableEventFactory;
use Shopware\Core\Framework\Webhook\Message\WebhookEventMessage;
use Shopware\Core\Framework\Webhook\Service\WebhookLoader;
use Shopware\Core\Framework\Webhook\Service\WebhookManager;
use Shopware\Core\Framework\Webhook\Webhook;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\MessageBus\CollectingMessageBus;
use Symfony\Component\Messenger\Envelope;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @internal
 */
#[CoversClass(WebhookManager::class)]
class WebhookManagerTest extends TestCase
{
    private WebhookLoader&MockObject $webhookLoader;

    private EventDispatcherInterface&MockObject $eventDispatcher;

    private Connection&MockObject $connection;

    private MockHandler $clientMock;

    private Client $client;

    private HookableEventFactory&MockObject $eventFactory;

    private CollectingMessageBus $bus;

    protected function setUp(): void
    {
        $this->webhookLoader = $this->createMock(WebhookLoader::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->clientMock = new MockHandler([new Response(200)]);
        $this->client = new Client(['handler' => HandlerStack::create($this->clientMock)]);
        $this->eventFactory = $this->createMock(HookableEventFactory::class);
        $this->bus = new CollectingMessageBus();
    }

    public function testDispatchesTwoConsecutiveEventsCorrectly(): void
    {
        $event1 = new AppFlowActionEvent('foobar', ['foo' => 'bar'], ['foo' => 'bar']);
        $event2 = new class('foobar.event', ['foo' => 'bar'], ['foo' => 'bar']) extends AppFlowActionEvent {};

        $this->eventFactory
            ->expects($this->exactly(2))
            ->method('createHookablesFor')
            ->willReturn([$event1], [$event2]);

        $webhookManager = $this->getWebhookManager(true);
        $webhookManager->dispatch($event1);
        $request = $this->clientMock->getLastRequest();
        static::assertNull($request);

        $webhook = $this->prepareWebhook($event2->getName());
        $this->assertSyncWebhookIsSent($webhook, $event2, $webhookManager);
    }

    public function testDispatchWithWebhooksSync(): void
    {
        $event = $this->prepareEvent();
        $webhook = $this->prepareWebhook($event->getName());

        $this->assertSyncWebhookIsSent($webhook, $event);
    }

    public function testDispatchWithWebhooksAsync(): void
    {
        $event = $this->prepareEvent();
        $webhook = $this->prepareWebhook($event->getName());

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);

        $payload = $message->getPayload();
        static::assertArrayHasKey('source', $payload);
        static::assertArrayHasKey('eventId', $payload['source']);
        unset($payload['source']['eventId']);
        static::assertEquals([
            'foo' => 'bar',
            'source' => [
                'url' => 'https://example.com',
                'appVersion' => $webhook->appVersion,
                'shopId' => 'foobar',
                'action' => $event->getName(),
                'inAppPurchases' => null,
            ],
        ], $payload);

        static::assertSame($message->getLanguageId(), Defaults::LANGUAGE_SYSTEM);
        static::assertSame($message->getAppId(), $webhook->appId);
        static::assertSame($message->getSecret(), $webhook->appSecret);
        static::assertSame($message->getShopwareVersion(), '0.0.0');
        static::assertSame($message->getUrl(), 'https://foo.bar');
        static::assertSame($message->getWebhookId(), $webhook->id);
    }

    public function testWebhookSettingForLiveVersionOnlyIsIgnoredIfEventTypeDoesNotMatch(): void
    {
        $event = $this->prepareEvent();
        $this->prepareWebhook($event->getName(), true);

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);
    }

    public function testWebhooksForLiveVersionOnlyAreCalledIfPayloadHasLiveVersion(): void
    {
        $event = $this->prepareHookableEvent();
        $this->prepareWebhook('product.written', true);

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();

        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);
    }

    public function testWebhooksAreNotDispatchedIfPrivilegesAreMissing(): void
    {
        $event = $this->prepareHookableEvent();
        $this->prepareWebhook('product.written', true, []);

        $this->getWebhookManager(false)->dispatch($event);
        $messages = $this->bus->getMessages();
        static::assertEmpty($messages);
    }

    public function testWebhooksForLiveVersionOnlyAreIgnoredIfPayloadHasDifferentVersion(): void
    {
        $event = $this->prepareHookableEvent([
            [
                'id' => Uuid::randomHex(),
                'versionId' => Uuid::randomHex(),
            ],
        ]);

        $this->prepareWebhook('product.written', true);

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertEmpty($messages);
    }

    public function testWebhooksForLiveVersionOnlyAreSentIfPayloadDoesNotHaveAnyVersionId(): void
    {
        $entityRepository = new StaticEntityRepository([], new CustomerDefinition());

        $event = $entityRepository->create([
            [
                'id' => Uuid::randomHex(),
            ],
        ], Context::createDefaultContext());

        $eventByEntityName = $event->getEventByEntityName('customer');
        static::assertInstanceOf(EntityWrittenEvent::class, $eventByEntityName);
        $hookableEvent = HookableEntityWrittenEvent::fromWrittenEvent($eventByEntityName);

        $this->eventFactory->expects($this->once())->method('createHookablesFor')->with($event)->willReturn([$hookableEvent]);

        $this->prepareWebhook('customer.written', true, ['customer:read']);

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);
    }

    public function testWebhooksAreCalledForNonLiveVersionConfig(): void
    {
        $event = $this->prepareHookableEvent();
        $this->prepareWebhook('product.written');

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);
    }

    public function testPayloadOfWebhookForLiveVersionOnlyIsFiltered(): void
    {
        $firstId = Uuid::randomHex();
        $secondId = Uuid::randomHex();
        $payloads = [
            [
                'id' => $firstId,
                'versionId' => Defaults::LIVE_VERSION,
            ],
            [
                'id' => $secondId,
                'versionId' => Uuid::randomHex(),
            ],
        ];

        $event = $this->prepareHookableEvent($payloads);
        $this->prepareWebhook('product.written', true);

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);

        $payload = $message->getPayload();
        static::assertCount(1, $payload['data']['payload']);
        static::assertNotFalse(json_encode($payload));
        static::assertStringContainsString($firstId, json_encode($payload));
        static::assertStringNotContainsString($secondId, json_encode($payload));
    }

    public function testPayloadIsLeftUnchangedForNonLiveVersionConfig(): void
    {
        $firstId = Uuid::randomHex();
        $secondId = Uuid::randomHex();
        $payloads = [
            [
                'id' => $firstId,
                'versionId' => Defaults::LIVE_VERSION,
            ],
            [
                'id' => $secondId,
                'versionId' => Uuid::randomHex(),
            ],
        ];

        $event = $this->prepareHookableEvent($payloads);
        $this->prepareWebhook('product.written');

        $this->getWebhookManager(false)->dispatch($event);

        $messages = $this->bus->getMessages();
        static::assertCount(1, $messages);

        $envelop = $messages[0];
        static::assertInstanceOf(Envelope::class, $envelop);
        $message = $envelop->getMessage();
        static::assertInstanceOf(WebhookEventMessage::class, $message);

        $payload = $message->getPayload();
        static::assertCount(2, $payload['data']['payload']);
        static::assertNotFalse(json_encode($payload));
        static::assertStringContainsString($firstId, json_encode($payload));
        static::assertStringContainsString($secondId, json_encode($payload));
    }

    private function assertSyncWebhookIsSent(Webhook $webhook, AppFlowActionEvent $event, ?WebhookManager $webhookManager = null): void
    {
        $expectedRequest = new Request(
            'POST',
            $webhook->url,
            [
                'foo' => 'bar',
                'Content-Type' => 'application/json',
                'sw-version' => '0.0.0',
                'sw-context-language' => [Defaults::LANGUAGE_SYSTEM],
                'sw-user-language' => [''],
            ],
            json_encode([
                'foo' => 'bar',
                'source' => [
                    'url' => 'https://example.com',
                    'appVersion' => $webhook->appVersion,
                    'shopId' => 'foobar',
                    'action' => $event->getName(),
                    'inAppPurchases' => null,
                ],
            ], \JSON_THROW_ON_ERROR)
        );

        $webhookManager = $webhookManager ?? $this->getWebhookManager(true);
        $webhookManager->dispatch($event);

        $request = $this->clientMock->getLastRequest();

        static::assertInstanceOf(RequestInterface::class, $request);
        static::assertSame('foo.bar', $request->getUri()->getHost());

        $headers = $request->getHeaders();
        static::assertArrayHasKey(RequestSigner::SHOPWARE_SHOP_SIGNATURE, $headers);
        unset($headers[RequestSigner::SHOPWARE_SHOP_SIGNATURE], $headers['Content-Length'], $headers['User-Agent']);
        static::assertEquals($expectedRequest->getHeaders(), $headers);

        $expectedContents = json_decode($expectedRequest->getBody()->getContents(), true);
        $contents = json_decode($request->getBody()->getContents(), true);
        static::assertIsArray($contents);
        static::assertArrayHasKey('timestamp', $contents);
        static::assertArrayHasKey('source', $contents);
        static::assertArrayHasKey('eventId', $contents['source']);
        unset($contents['timestamp'], $contents['source']['eventId']);
        static::assertEquals($expectedContents, $contents);
    }

    private function prepareEvent(): AppFlowActionEvent
    {
        $event = new AppFlowActionEvent('foobar', ['foo' => 'bar'], ['foo' => 'bar']);

        $this->eventFactory
            ->expects($this->once())
            ->method('createHookablesFor')
            ->with($event)
            ->willReturn([$event]);

        return $event;
    }

    /**
     * @param list<array{id: string, versionId: string}>|null $payloads
     */
    private function prepareHookableEvent(?array $payloads = null): Event
    {
        $entityRepository = new StaticEntityRepository([], new ProductDefinition());

        $event = $entityRepository->create($payloads ?? [
            [
                'id' => Uuid::randomHex(),
                'versionId' => Defaults::LIVE_VERSION,
            ],
        ], Context::createDefaultContext());

        $eventByEntityName = $event->getEventByEntityName('product');
        static::assertInstanceOf(EntityWrittenEvent::class, $eventByEntityName);
        $hookableEvent = HookableEntityWrittenEvent::fromWrittenEvent($eventByEntityName);

        $this->eventFactory->expects($this->once())->method('createHookablesFor')->with($event)->willReturn([$hookableEvent]);

        return $event;
    }

    /**
     * @param list<string> $acl
     */
    private function prepareWebhook(string $eventName, bool $onlyLiveVersion = false, array $acl = ['product:read']): Webhook
    {
        $webhook = $this->getWebhook($eventName, $onlyLiveVersion);

        $this->webhookLoader->expects($this->once())
            ->method('getWebhooks')
            ->willReturn([$webhook]);

        $this->webhookLoader
            ->method('getPrivilegesForRoles')
            ->with([$webhook->appAclRoleId])
            ->willReturn([$webhook->appAclRoleId => new AclPrivilegeCollection($acl)]);

        return $webhook;
    }

    private function getWebhookManager(bool $isAdminWorkerEnabled): WebhookManager
    {
        $appPayloadServiceHelper = $this->createMock(AppPayloadServiceHelper::class);
        $appPayloadServiceHelper->expects($this->any())->method('buildSource')->willReturn(new Source('https://example.com', 'foobar', '0.0.0'));

        return new WebhookManager(
            $this->webhookLoader,
            $this->eventDispatcher,
            $this->connection,
            $this->eventFactory,
            $this->createMock(AppLocaleProvider::class),
            $appPayloadServiceHelper,
            $this->client,
            $this->bus,
            'https://example.com',
            '0.0.0',
            $isAdminWorkerEnabled
        );
    }

    private function getWebhook(string $eventName, bool $onlyLiveVersion = false): Webhook
    {
        return new Webhook(
            Uuid::randomHex(),
            'Cool Webhook',
            $eventName,
            'https://foo.bar',
            $onlyLiveVersion,
            Uuid::randomHex(),
            'Cool App',
            'local',
            true,
            '0.0.0',
            'verysecret',
            Uuid::randomHex()
        );
    }
}
