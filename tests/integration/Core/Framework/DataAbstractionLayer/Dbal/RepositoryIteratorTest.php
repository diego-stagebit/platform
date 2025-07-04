<?php

declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Dbal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigCollection;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class RepositoryIteratorTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testIteratedSearch(): void
    {
        $context = Context::createDefaultContext();
        /** @var EntityRepository<SystemConfigCollection> $systemConfigRepository */
        $systemConfigRepository = static::getContainer()->get('system_config.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('configurationKey', 'core'));
        $criteria->setLimit(1);

        /** @var RepositoryIterator<SystemConfigCollection> $iterator */
        $iterator = new RepositoryIterator($systemConfigRepository, $context, $criteria);

        $offset = 1;
        while (($result = $iterator->fetch()) !== null) {
            static::assertNotEmpty($result->getEntities()->first()?->getId());
            static::assertEquals(
                [new ContainsFilter('configurationKey', 'core')],
                $criteria->getFilters()
            );
            static::assertCount(0, $criteria->getPostFilters());
            static::assertSame($offset, $criteria->getOffset());
            ++$offset;
        }
    }

    public function testFetchIdsIsNotRunningInfinitely(): void
    {
        $context = Context::createDefaultContext();
        /** @var EntityRepository<SystemConfigCollection> $systemConfigRepository */
        $systemConfigRepository = static::getContainer()->get('system_config.repository');

        $iterator = new RepositoryIterator($systemConfigRepository, $context, new Criteria());

        $iteration = 0;
        while ($iterator->fetchIds() !== null && $iteration < 100) {
            ++$iteration;
        }

        static::assertTrue($iteration < 100);
    }

    public function testFetchIdAutoIncrement(): void
    {
        /** @var EntityRepository<ProductCollection> $productRepository */
        $productRepository = static::getContainer()->get('product.repository');

        $context = Context::createDefaultContext();

        $ids = new IdsCollection();

        $builder = new ProductBuilder($ids, 'product1');
        $builder->price(1);
        $productRepository->create([$builder->build()], $context);

        $builder = new ProductBuilder($ids, 'product2');
        $builder->price(2);
        $productRepository->create([$builder->build()], $context);

        $builder = new ProductBuilder($ids, 'product3');
        $builder->price(3);
        $productRepository->create([$builder->build()], $context);

        $criteria = new Criteria([$ids->get('product1'), $ids->get('product2'), $ids->get('product3')]);
        $criteria->setLimit(1);
        $iterator = new RepositoryIterator($productRepository, $context, $criteria);

        $totalFetchedIds = 0;
        while ($iterator->fetchIds()) {
            ++$totalFetchedIds;
        }
        static::assertSame($totalFetchedIds, 3);
    }
}
