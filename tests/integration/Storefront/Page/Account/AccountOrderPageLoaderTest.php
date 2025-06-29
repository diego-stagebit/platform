<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Page\Account;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoader;
use Shopware\Storefront\Test\Page\StorefrontPageTestBehaviour;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
class AccountOrderPageLoaderTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontPageTestBehaviour;

    private SalesChannelContext $salesChannel;

    /**
     * @var EntityRepository<CustomerCollection>
     */
    private EntityRepository $customerRepository;

    /**
     * @var EntityRepository<OrderCollection>
     */
    private EntityRepository $orderRepository;

    protected function setUp(): void
    {
        $this->salesChannel = $this->createSalesChannelContext();
        $this->customerRepository = static::getContainer()->get('customer.repository');
        $this->orderRepository = static::getContainer()->get('order.repository');
    }

    public function testLogsInGuestById(): void
    {
        $context = Context::createDefaultContext();
        $unexpectedCustomer = $this->createCustomer();
        $expectedCustomer = $this->createCustomer();

        $unexpectedCustomer->setEmail('identical@shopware.com');
        $expectedCustomer->setEmail('identical@shopware.com');
        $expectedCustomer->setGuest(true);

        $this->customerRepository->update([
            [
                'id' => $unexpectedCustomer->getId(),
                'email' => $unexpectedCustomer->getEmail(),
            ],
            [
                'id' => $expectedCustomer->getId(),
                'email' => $expectedCustomer->getEmail(),
                'guest' => $expectedCustomer->getGuest(),
            ],
        ], $context);

        $salesChannel = static::getContainer()->get(SalesChannelContextFactory::class)->create(
            $this->salesChannel->getToken(),
            $this->salesChannel->getSalesChannelId(),
            [SalesChannelContextService::CUSTOMER_ID => $expectedCustomer->getId()],
        );
        $orderId = $this->placeRandomOrder($salesChannel);
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->getEntities()->first();
        static::assertNotNull($order);
        $this->orderRepository->update([
            [
                'id' => $order->getId(),
                'deepLinkCode' => $deepLinkCode = Random::getBase64UrlString(32),
                'orderCustomer.customerId' => $expectedCustomer->getId(),
            ],
        ], $context);

        $page = $this->getPageLoader()->load(
            new Request(
                [
                    'deepLinkCode' => $deepLinkCode,
                    'email' => $expectedCustomer->getEmail(),
                    'zipcode' => '12345',
                ],
            ),
            $this->salesChannel
        );

        static::assertSame(
            $expectedCustomer->getId(),
            $page->getOrders()->getEntities()->first()?->getOrderCustomer()?->getCustomerId(),
        );
    }

    public function testLoad(): void
    {
        $salesChannel = $this->createSalesChannelContextWithLoggedInCustomerAndWithNavigation();

        $orderId = $this->placeRandomOrder($salesChannel);
        $order = $this->orderRepository->search(new Criteria([$orderId]), $salesChannel->getContext())->getEntities()->first();
        static::assertInstanceOf(OrderEntity::class, $order);
        $deepLinkCode = $order->getDeepLinkCode();

        $page = $this->getPageLoader()->load(
            new Request(
                [
                    'deepLinkCode' => $deepLinkCode,
                    'email' => $salesChannel->getCustomer()?->getEmail(),
                    'zipcode' => '12345',
                ],
            ),
            $salesChannel
        );

        $order = $page->getOrders()->first();

        static::assertInstanceOf(OrderEntity::class, $order);
        static::assertNotNull($order->getPrimaryOrderDelivery());
        static::assertNotNull($order->getPrimaryOrderTransaction());
        static::assertNotNull($order->getPrimaryOrderTransactionId());
        static::assertNotNull($order->getPrimaryOrderDeliveryId());

        static::assertNotNull($page->getDeepLinkCode());
        static::assertSame($deepLinkCode, $order->getDeepLinkCode());
    }

    protected function getPageLoader(): AccountOrderPageLoader
    {
        return static::getContainer()->get(AccountOrderPageLoader::class);
    }
}
