<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Customer\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerRecovery\CustomerRecoveryEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerAccountRecoverRequestEvent;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\ScalarValuesStorer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * @internal
 */
#[CoversClass(CustomerAccountRecoverRequestEvent::class)]
#[Package('checkout')]
class CustomerAccountRecoverRequestEventTest extends TestCase
{
    public function testRestoreScalarValuesCorrectly(): void
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setTranslated(['name' => 'my-shop-name']);

        $context = $this->createMock(SalesChannelContext::class);
        $context->expects($this->any())->method('getSalesChannel')->willReturn($salesChannel);

        $event = new CustomerAccountRecoverRequestEvent(
            $context,
            new CustomerRecoveryEntity(),
            'my-reset-url'
        );

        $storer = new ScalarValuesStorer();

        $stored = $storer->store($event, []);

        $flow = new StorableFlow('foo', Context::createDefaultContext(), $stored);

        $storer->restore($flow);

        static::assertArrayHasKey('resetUrl', $flow->data());
        static::assertArrayHasKey('shopName', $flow->data());
        static::assertSame('my-reset-url', $flow->data()['resetUrl']);
        static::assertSame('my-shop-name', $flow->data()['shopName']);
    }
}
