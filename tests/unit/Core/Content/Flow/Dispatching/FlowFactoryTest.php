<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Flow\Dispatching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\FlowFactory;
use Shopware\Core\Content\Flow\Dispatching\Storer\OrderStorer;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('after-sales')]
#[CoversClass(FlowFactory::class)]
class FlowFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $ids = new IdsCollection();
        $order = new OrderEntity();
        $order->setId($ids->get('orderId'));

        $context = Generator::generateSalesChannelContext();

        $awareEvent = new CheckoutOrderPlacedEvent($context, $order);

        $orderStorer = new OrderStorer($this->createMock(EntityRepository::class), $this->createMock(EventDispatcherInterface::class));
        $flowFactory = new FlowFactory([$orderStorer]);
        $flow = $flowFactory->create($awareEvent);

        static::assertSame($ids->get('orderId'), $flow->getStore('orderId'));
        static::assertInstanceOf(SystemSource::class, $flow->getContext()->getSource());
        static::assertSame(Context::SYSTEM_SCOPE, $flow->getContext()->getScope());
    }

    public function testRestore(): void
    {
        $ids = new IdsCollection();
        $order = new OrderEntity();
        $order->setId($ids->get('orderId'));

        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->expects($this->once())
            ->method('get')
            ->willReturn($order);

        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects($this->once())
            ->method('search')
            ->willReturn($entitySearchResult);

        $context = Generator::generateSalesChannelContext();

        $awareEvent = new CheckoutOrderPlacedEvent($context, $order);

        $orderStorer = new OrderStorer($orderRepo, $this->createMock(EventDispatcherInterface::class));
        $flowFactory = new FlowFactory([$orderStorer]);

        $storedData = [
            'orderId' => $ids->get('orderId'),
            'additional_keys' => ['order'],
        ];
        $flow = $flowFactory->restore('checkout.order.placed', $awareEvent->getContext(), $storedData);

        static::assertInstanceOf(OrderEntity::class, $flow->getData('order'));
        static::assertSame($ids->get('orderId'), $flow->getData('order')->getId());

        static::assertInstanceOf(SystemSource::class, $flow->getContext()->getSource());
        static::assertSame(Context::SYSTEM_SCOPE, $flow->getContext()->getScope());
    }
}
