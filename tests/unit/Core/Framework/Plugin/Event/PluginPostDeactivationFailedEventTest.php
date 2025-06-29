<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Plugin\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivationFailedEvent;
use Shopware\Core\Framework\Plugin\PluginEntity;

/**
 * @internal
 */
#[CoversClass(PluginPostDeactivationFailedEvent::class)]
class PluginPostDeactivationFailedEventTest extends TestCase
{
    public function testEvent(): void
    {
        $activateContext = $this->createMock(ActivateContext::class);
        $exception = new \Exception('failed');
        $event = new PluginPostDeactivationFailedEvent(
            new PluginEntity(),
            $activateContext,
            $exception
        );
        static::assertSame($activateContext, $event->getContext());
        static::assertSame($exception, $event->getException());
    }
}
