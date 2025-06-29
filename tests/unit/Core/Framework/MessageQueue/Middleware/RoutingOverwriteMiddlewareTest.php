<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\MessageQueue\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface;
use Shopware\Core\Framework\MessageQueue\Middleware\RoutingOverwriteMiddleware;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Test\Middleware\MiddlewareTestCase;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(RoutingOverwriteMiddleware::class)]
class RoutingOverwriteMiddlewareTest extends MiddlewareTestCase
{
    public function testMessageIsForwardedWhenItIsBeingHandledByWorked(): void
    {
        // message should get async stamp, but is skipped because it has received stamp
        $middleware = new RoutingOverwriteMiddleware([
            AsyncMessage::class => 'async',
            AsyncMessageInterface::class => 'async',
            LowPriorityMessageInterface::class => 'low_priority',
            SendEmailMessage::class => 'async',
        ]);

        $envelope = $middleware->handle(
            Envelope::wrap(new AsyncMessage(), [new ReceivedStamp('my-transports')]),
            $this->getStackMock()
        );

        static::assertNull($envelope->last(TransportNamesStamp::class));
    }

    /**
     * @param array<string, string|list<string>> $config
     * @param array<StampInterface> $providedStamps
     * @param array<StampInterface> $expectedStamps
     */
    #[DataProvider('dispatchProvider')]
    public function testDispatch(object $message, array $config, array $providedStamps, array $expectedStamps): void
    {
        $middleware = new RoutingOverwriteMiddleware($config);

        $message = Envelope::wrap($message, $providedStamps);
        $envelope = $middleware->handle($message, $this->getStackMock());

        static::assertEquals(
            $expectedStamps,
            array_merge(...array_values($envelope->all()))
        );
    }

    public static function dispatchProvider(): \Generator
    {
        yield 'No config, no stamps' => [
            'message' => new AsyncMessage(),
            'config' => [],
            'providedStamps' => [],
            'expectedStamps' => [],
        ];

        yield 'Default config, no stamps' => [
            'message' => new AsyncMessage(),
            'config' => [
                AsyncMessageInterface::class => 'async',
                LowPriorityMessageInterface::class => 'low_priority',
                SendEmailMessage::class => 'async',
            ],
            'providedStamps' => [],
            'expectedStamps' => [],
        ];

        yield 'Explicit config, single transport, get stamped' => [
            'message' => new AsyncMessage(),
            'config' => [
                AsyncMessage::class => 'async',
                AsyncMessageInterface::class => 'async',
                LowPriorityMessageInterface::class => 'low_priority',
                SendEmailMessage::class => 'async',
            ],
            'providedStamps' => [],
            'expectedStamps' => [
                new TransportNamesStamp(['async']),
            ],
        ];

        yield 'Explicit config, multiple transports, get stamped' => [
            'message' => new AsyncMessage(),
            'config' => [
                AsyncMessage::class => ['async', 'low_priority'],
                AsyncMessageInterface::class => 'async',
                LowPriorityMessageInterface::class => 'low_priority',
                SendEmailMessage::class => 'async',
            ],
            'providedStamps' => [],
            'expectedStamps' => [
                new TransportNamesStamp(['async', 'low_priority']),
            ],
        ];

        yield 'Pre-stamped message, no config, direct dispatch' => [
            'message' => new AsyncMessage(),
            'config' => [],
            'providedStamps' => [
                new TransportNamesStamp(['async', 'low_priority']),
            ],
            'expectedStamps' => [
                new TransportNamesStamp(['async', 'low_priority']),
            ],
        ];

        yield 'Default config, no stamps, message in envelope' => [
            'message' => new Envelope(new AsyncMessage()),
            'config' => [
                AsyncMessageInterface::class => 'async',
                LowPriorityMessageInterface::class => 'low_priority',
                SendEmailMessage::class => 'async',
            ],
            'providedStamps' => [],
            'expectedStamps' => [],
        ];

        yield 'Default config, no stamps, message in envelope, envelope with stamp' => [
            'message' => (new Envelope(new AsyncMessage()))->with(new BusNameStamp('test')),
            'config' => [
                AsyncMessageInterface::class => 'async',
                LowPriorityMessageInterface::class => 'low_priority',
                SendEmailMessage::class => 'async',
            ],
            'providedStamps' => [],
            'expectedStamps' => [
                new BusNameStamp('test'),
            ],
        ];

        yield 'Default config, no stamps, message in envelope, message implements interface' => [
            'message' => (new Envelope(new LowPriorityMessage())),
            'config' => [
                AsyncMessageInterface::class => 'async',
                LowPriorityMessageInterface::class => 'low_priority',
                SendEmailMessage::class => 'async',
            ],
            'providedStamps' => [],
            'expectedStamps' => [
                new TransportNamesStamp(['low_priority']),
            ],
        ];
    }
}

/**
 * @internal
 */
class AsyncMessage
{
}

/**
 * @internal
 */
class LowPriorityMessage implements LowPriorityMessageInterface
{
}
