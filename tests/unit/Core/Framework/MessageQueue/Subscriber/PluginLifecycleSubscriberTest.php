<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\MessageQueue\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\Registry\TaskRegistry;
use Shopware\Core\Framework\MessageQueue\Subscriber\PluginLifecycleSubscriber;
use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(PluginLifecycleSubscriber::class)]
class PluginLifecycleSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = PluginLifecycleSubscriber::getSubscribedEvents();

        static::assertCount(3, $events);
        static::assertArrayHasKey(PluginPostActivateEvent::class, $events);
        static::assertSame('afterPluginStateChange', $events[PluginPostActivateEvent::class]);
        static::assertArrayHasKey(PluginPostDeactivateEvent::class, $events);
        static::assertSame('afterPluginStateChange', $events[PluginPostDeactivateEvent::class]);
        static::assertArrayHasKey(PluginPostUpdateEvent::class, $events);
        static::assertSame('afterPluginStateChange', $events[PluginPostUpdateEvent::class]);
    }

    public function testRegisterScheduledTasks(): void
    {
        $taskRegistry = $this->createMock(TaskRegistry::class);
        $taskRegistry->expects($this->once())->method('registerTasks');

        $signalCachePool = new ArrayAdapter();
        $subscriber = new PluginLifecycleSubscriber($taskRegistry, $signalCachePool);
        $subscriber->afterPluginStateChange();

        static::assertTrue($signalCachePool->hasItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY));
    }
}
