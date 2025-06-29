<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Customer\Subscriber;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Subscriber\CustomerSalutationSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[CoversClass(CustomerSalutationSubscriber::class)]
#[Package('checkout')]
class CustomerSalutationSubscriberTest extends TestCase
{
    private MockObject&Connection $connection;

    private CustomerSalutationSubscriber $salutationSubscriber;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $this->salutationSubscriber = new CustomerSalutationSubscriber($this->connection);
    }

    public function testGetSubscribedEvents(): void
    {
        static::assertSame([
            CustomerEvents::CUSTOMER_WRITTEN_EVENT => 'setDefaultSalutation',
            CustomerEvents::CUSTOMER_ADDRESS_WRITTEN_EVENT => 'setDefaultSalutation',
        ], $this->salutationSubscriber->getSubscribedEvents());
    }

    public function testSkip(): void
    {
        $writeResults = [
            new EntityWriteResult(
                'created-id',
                ['id' => Uuid::randomHex(), 'salutationId' => Uuid::randomHex()],
                'customer',
                EntityWriteResult::OPERATION_INSERT
            ),
        ];

        $event = new EntityWrittenEvent(
            'customer',
            $writeResults,
            Context::createDefaultContext(),
            [],
        );

        $this->connection->expects($this->never())->method('executeStatement');

        $this->salutationSubscriber->setDefaultSalutation($event);
    }

    public function testDefaultSalutation(): void
    {
        $customerId = Uuid::randomHex();

        $writeResults = [new EntityWriteResult('created-id', ['id' => $customerId], 'customer', EntityWriteResult::OPERATION_INSERT)];

        $event = new EntityWrittenEvent(
            'customer',
            $writeResults,
            Context::createDefaultContext(),
            [],
        );

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use ($customerId): int {
                static::assertSame($params, [
                    'id' => Uuid::fromHexToBytes($customerId),
                    'notSpecified' => 'not_specified',
                ]);

                static::assertSame('
                UPDATE `customer`
                SET `salutation_id` = (
                    SELECT `id`
                    FROM `salutation`
                    WHERE `salutation_key` = :notSpecified
                    LIMIT 1
                )
                WHERE `id` = :id AND `salutation_id` is NULL
            ', $sql);

                return 1;
            });

        $this->salutationSubscriber->setDefaultSalutation($event);
    }
}
