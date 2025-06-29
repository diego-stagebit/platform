<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\MessageQueue\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\Registry\TaskRegistry;
use Shopware\Core\Framework\MessageQueue\Subscriber\UpdatePostFinishSubscriber;
use Shopware\Core\Framework\Update\Event\UpdatePostFinishEvent;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(UpdatePostFinishSubscriber::class)]
class UpdatePostFinishSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = UpdatePostFinishSubscriber::getSubscribedEvents();

        static::assertCount(1, $events);
        static::assertArrayHasKey(UpdatePostFinishEvent::class, $events);
        static::assertSame('updatePostFinishEvent', $events[UpdatePostFinishEvent::class]);
    }

    public function testUpdatePostFinishEvent(): void
    {
        $registry = $this->createMock(TaskRegistry::class);
        $registry->expects($this->once())->method('registerTasks');

        (new UpdatePostFinishSubscriber($registry))->updatePostFinishEvent();
    }
}
