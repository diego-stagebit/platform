<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\MessageQueue\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Messenger\Stamp\SentAtStamp;
use Shopware\Core\Framework\Increment\AbstractIncrementer;
use Shopware\Core\Framework\Increment\IncrementGatewayRegistry;
use Shopware\Core\Framework\MessageQueue\Stats\StatsService;
use Shopware\Core\Framework\MessageQueue\Subscriber\MessageQueueStatsSubscriber;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * @internal
 */
#[CoversClass(MessageQueueStatsSubscriber::class)]
class MessageQueueStatsSubscriberTest extends TestCase
{
    private MessageQueueStatsSubscriber $subscriber;

    private MockObject&IncrementGatewayRegistry $gatewayRegistry;

    private MockObject&AbstractIncrementer $incrementer;

    private StatsService&MockObject $statsService;

    protected function setUp(): void
    {
        $this->gatewayRegistry = $this->createMock(IncrementGatewayRegistry::class);
        $this->statsService = $this->createMock(StatsService::class);
        $this->incrementer = $this->createMock(AbstractIncrementer::class);
        $this->subscriber = new MessageQueueStatsSubscriber(
            $this->gatewayRegistry,
            $this->statsService,
        );
    }

    public function testOnMessageFailed(): void
    {
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'receiver', new \Exception());

        $this->handleCommonExpectations($envelope, false);

        $this->subscriber->onMessageFailed($event);
    }

    public function testOnMessageHandled(): void
    {
        $envelope = new Envelope(new \stdClass(), [
            new SentAtStamp(new \DateTimeImmutable('@' . 1726567204)),
        ]);
        $event = new WorkerMessageHandledEvent($envelope, 'theReceiver');

        $this->handleCommonExpectations($envelope, false);

        $this->statsService->expects($this->once())
            ->method('registerMessage')
            ->with($envelope);

        $this->subscriber->onMessageHandled($event);
    }

    public function testOnMessageSent(): void
    {
        $envelope = new Envelope(new \stdClass());
        $event = new SendMessageToTransportsEvent($envelope, []);

        $this->handleCommonExpectations($envelope, true);

        $this->subscriber->onMessageSent($event);
    }

    protected function handleCommonExpectations(Envelope $envelope, bool $increment): void
    {
        $this->gatewayRegistry->expects($this->once())
            ->method('get')
            ->with(IncrementGatewayRegistry::MESSAGE_QUEUE_POOL)
            ->willReturn($this->incrementer);

        $method = $increment ? 'increment' : 'decrement';
        $this->incrementer->expects($this->once())
            ->method($method)
            ->with('message_queue_stats', $envelope->getMessage()::class);
    }
}
