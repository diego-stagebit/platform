<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\SalesChannel\Review\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\Storer\ScalarValuesStorer;
use Shopware\Core\Content\Product\SalesChannel\Review\Event\ReviewFormEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Validation\DataBag\DataBag;

/**
 * @internal
 */
#[CoversClass(ReviewFormEvent::class)]
class ReviewFormEventTest extends TestCase
{
    public function testInstance(): void
    {
        $context = Context::createDefaultContext();
        $salesChannelId = 'foo';
        $mailRecipientStruct = new MailRecipientStruct(['foo' => 'bar']);
        $data = new DataBag(['baz']);
        $productId = 'bar';
        $customerId = 'bar';

        $event = new ReviewFormEvent($context, $salesChannelId, $mailRecipientStruct, $data, $productId, $customerId);

        static::assertSame($context, $event->getContext());
        static::assertSame($salesChannelId, $event->getSalesChannelId());
        static::assertSame($mailRecipientStruct, $event->getMailStruct());
        static::assertSame($data->all(), $event->getReviewFormData());
        static::assertSame($productId, $event->getProductId());
        static::assertSame($customerId, $event->getCustomerId());
    }

    public function testScalarValuesCorrectly(): void
    {
        $event = new ReviewFormEvent(
            Context::createDefaultContext(),
            'sales-channel-id',
            new MailRecipientStruct(['foo' => 'bar']),
            new DataBag(['foo' => 'bar', 'bar' => 'baz']),
            'product-id',
            'customer-id'
        );

        $storer = new ScalarValuesStorer();

        $stored = $storer->store($event, []);

        $flow = new StorableFlow('foo', Context::createDefaultContext(), $stored);

        $storer->restore($flow);

        static::assertArrayHasKey('reviewFormData', $flow->data());
        static::assertSame(['foo' => 'bar', 'bar' => 'baz'], $flow->data()['reviewFormData']);
    }
}
