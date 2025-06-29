<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Newsletter\Subscriber;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Newsletter\NewsletterEvents;
use Shopware\Core\Content\Newsletter\Subscriber\NewsletterRecipientSalutationSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[Package('after-sales')]
#[CoversClass(NewsletterRecipientSalutationSubscriber::class)]
class NewsletterRecipientSalutationSubscriberTest extends TestCase
{
    private MockObject&Connection $connection;

    private NewsletterRecipientSalutationSubscriber $salutationSubscriber;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);

        $this->salutationSubscriber = new NewsletterRecipientSalutationSubscriber($this->connection);
    }

    public function testGetSubscribedEvents(): void
    {
        static::assertSame([
            NewsletterEvents::NEWSLETTER_RECIPIENT_WRITTEN_EVENT => 'setDefaultSalutation',
        ], $this->salutationSubscriber->getSubscribedEvents());
    }

    public function testSkip(): void
    {
        $writeResults = [
            new EntityWriteResult(
                'created-id',
                ['id' => Uuid::randomHex(), 'salutationId' => Uuid::randomHex()],
                'newsletter_recipient',
                EntityWriteResult::OPERATION_INSERT
            ),
        ];

        $event = new EntityWrittenEvent(
            'newsletter_recipient',
            $writeResults,
            Context::createDefaultContext(),
            [],
        );

        $this->connection->expects($this->never())->method('executeStatement');

        $this->salutationSubscriber->setDefaultSalutation($event);
    }

    public function testDefaultSalutation(): void
    {
        $newsletterRecipientId = Uuid::randomHex();

        $writeResults = [new EntityWriteResult('created-id', ['id' => $newsletterRecipientId], 'newsletter_recipient', EntityWriteResult::OPERATION_INSERT)];

        $event = new EntityWrittenEvent(
            'newsletter_recipient',
            $writeResults,
            Context::createDefaultContext(),
            [],
        );

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use ($newsletterRecipientId): int {
                static::assertSame($params, [
                    'id' => Uuid::fromHexToBytes($newsletterRecipientId),
                    'notSpecified' => 'not_specified',
                ]);

                static::assertSame('
                UPDATE `newsletter_recipient`
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
