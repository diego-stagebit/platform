<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\NumberRange\Aggregate\NumberRangeSalesChannel;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\Aggregate\NumberRangeSalesChannel\NumberRangeSalesChannelCollection;
use Shopware\Core\System\NumberRange\Aggregate\NumberRangeSalesChannel\NumberRangeSalesChannelEntity;
use Shopware\Core\System\NumberRange\NumberRangeCollection;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
class NumberRangeSalesChannelDefinitionTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntityRepository<NumberRangeCollection>
     */
    private EntityRepository $numberRangeRepository;

    /**
     * @var EntityRepository<SalesChannelCollection>
     */
    private EntityRepository $salesChannelRepository;

    protected function setUp(): void
    {
        $this->numberRangeRepository = static::getContainer()->get('number_range.repository');
        $this->salesChannelRepository = static::getContainer()->get('sales_channel.repository');
    }

    public function testNumberRangeSalesChannelCollectionFromNumberRange(): void
    {
        $numberRangeId = $this->createNumberRange();

        $criteria = new Criteria([$numberRangeId]);
        $criteria->addAssociation('numberRangeSalesChannels');

        $numberRange = $this->numberRangeRepository->search($criteria, Context::createDefaultContext())
            ->getEntities()
            ->first();

        static::assertNotNull($numberRange);
        $this->assertNumberRangeSalesChannel($numberRangeId, $numberRange->getNumberRangeSalesChannels());
    }

    public function testNumberRangeSalesChannelCollectionFromSalesChannel(): void
    {
        $numberRangeId = $this->createNumberRange();

        $criteria = new Criteria([TestDefaults::SALES_CHANNEL]);
        $criteria->addAssociation('numberRangeSalesChannels');

        $salesChannel = $this->salesChannelRepository->search($criteria, Context::createDefaultContext())
            ->getEntities()
            ->first();

        static::assertNotNull($salesChannel);
        $this->assertNumberRangeSalesChannel($numberRangeId, $salesChannel->getNumberRangeSalesChannels());
    }

    private function createNumberRange(): string
    {
        $numberRangeId = Uuid::randomHex();

        $this->numberRangeRepository->create([[
            'id' => $numberRangeId,
            'name' => 'numberRange',
            'pattern' => '{n}',
            'start' => 0,
            'global' => false,
            'type' => [
                'id' => $numberRangeId,
                'typeName' => 'number range type',
                'technicalName' => 'number_range_type',
                'global' => false,
            ],
            'numberRangeSalesChannels' => [
                [
                    'numberRangeId' => $numberRangeId,
                    'salesChannelId' => TestDefaults::SALES_CHANNEL,
                    'numberRangeTypeId' => $numberRangeId,
                ],
            ],
        ]], Context::createDefaultContext());

        return $numberRangeId;
    }

    private function assertNumberRangeSalesChannel(
        string $numberRangeId,
        ?NumberRangeSalesChannelCollection $getNumberRangeSalesChannels
    ): void {
        static::assertInstanceOf(NumberRangeSalesChannelCollection::class, $getNumberRangeSalesChannels);

        $numberRangeSalesChannel = $getNumberRangeSalesChannels->first();

        static::assertInstanceOf(NumberRangeSalesChannelEntity::class, $numberRangeSalesChannel);
        static::assertSame($numberRangeId, $numberRangeSalesChannel->getNumberRangeId());
        static::assertSame(TestDefaults::SALES_CHANNEL, $numberRangeSalesChannel->getSalesChannelId());
        static::assertSame($numberRangeId, $numberRangeSalesChannel->getNumberRangeTypeId());
    }
}
