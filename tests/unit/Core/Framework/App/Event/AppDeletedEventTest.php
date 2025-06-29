<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\App\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\Event\AppDeletedEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\AclPrivilegeCollection;

/**
 * @internal
 */
#[CoversClass(AppDeletedEvent::class)]
class AppDeletedEventTest extends TestCase
{
    public function testGetter(): void
    {
        $appId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        $event = new AppDeletedEvent(
            $appId,
            $context
        );

        static::assertSame($appId, $event->getAppId());
        static::assertSame($context, $event->getContext());
        static::assertSame(AppDeletedEvent::NAME, $event->getName());
        static::assertSame(['keepUserData' => false], $event->getWebhookPayload());
    }

    public function testIsAllowed(): void
    {
        $appId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        $event = new AppDeletedEvent(
            $appId,
            $context
        );

        static::assertTrue($event->isAllowed($appId, new AclPrivilegeCollection([])));
        static::assertFalse($event->isAllowed(Uuid::randomHex(), new AclPrivilegeCollection([])));
    }
}
