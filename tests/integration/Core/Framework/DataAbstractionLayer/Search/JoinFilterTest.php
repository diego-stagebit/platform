<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Search;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Exception\UnmappedFieldException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NandFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NorFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\SuffixFilter;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class JoinFilterTest extends TestCase
{
    use KernelTestBehaviour;

    #[BeforeClass]
    public static function startTransactionBefore(): void
    {
        $connection = KernelLifecycleManager::getKernel()
            ->getContainer()
            ->get(Connection::class);

        $connection->beginTransaction();
    }

    #[AfterClass]
    public static function stopTransactionAfter(): void
    {
        $connection = KernelLifecycleManager::getKernel()
            ->getContainer()
            ->get(Connection::class);

        $connection->rollBack();
    }

    /**
     * @return IdsCollection
     */
    public function testIndexing()
    {
        $ids = new IdsCollection();

        $products = [
            (new ProductBuilder($ids, 'product-1', 10, 'tax'))
                ->price(15, 10)
                ->manufacturer('manufacturer-1')
                ->property('red', 'color')
                ->property('yellow', 'color')
                ->property('XL', 'size')
                ->property('L', 'size')
                ->category('category-1')
                ->category('category-2')
                ->prices('rule-1', 100)
                ->prices('rule-2', 150)
                ->build(),

            (new ProductBuilder($ids, 'product-1-variant', 10, 'tax'))
                ->parent('product-1')
                ->build(),

            (new ProductBuilder($ids, 'product-2', 3, 'tax'))
                ->price(15, 10)
                ->manufacturer('manufacturer-2')
                ->property('red', 'color')
                ->category('category-1')
                ->category('category-3')
                ->prices('rule-1', 150)
                ->build(),

            (new ProductBuilder($ids, 'product-3', 3, 'tax'))
                ->price(15, 10)
                ->build(),
        ];

        static::getContainer()->get('product.repository')
            ->create($products, Context::createDefaultContext());

        $userId = static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM `user`');

        $ids->set('user-id', $userId);

        $media = [
            ['id' => $ids->create('with-avatar')],
            ['id' => $ids->create('without-avatar')],
        ];

        static::getContainer()->get('media.repository')
            ->create($media, Context::createDefaultContext());

        $avatar = [
            'id' => $userId,
            'avatarId' => $ids->get('with-avatar'),
        ];

        static::getContainer()->get('user.repository')
            ->update([$avatar], Context::createDefaultContext());

        $result = static::getContainer()->get('product.repository')
            ->searchIds(new Criteria($ids->prefixed('product-')), Context::createDefaultContext());

        static::assertSame(\count($products), $result->getTotal());

        return $ids;
    }

    #[Depends('testIndexing')]
    public function testOneToOne(IdsCollection $ids): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new NandFilter([new EqualsFilter('avatarUsers.id', null)])
        );

        $media = static::getContainer()->get('media.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertCount(1, $media->getIds());
        static::assertContains($ids->get('with-avatar'), $media->getIds());
        static::assertNotContains($ids->get('without-avatar'), $media->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('avatarUsers.id', null));

        $media = static::getContainer()->get('media.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertTrue(\count($media->getIds()) > 0);
        static::assertContains($ids->get('without-avatar'), $media->getIds());
        static::assertNotContains($ids->get('with-avatar'), $media->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter('avatarUsers.id', null),
                new NandFilter([new EqualsFilter('avatarUsers.id', Uuid::randomHex())]),
            ])
        );

        $media = static::getContainer()->get('media.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertTrue(\count($media->getIds()) > 0);
        static::assertContains($ids->get('with-avatar'), $media->getIds());
        static::assertContains($ids->get('without-avatar'), $media->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(
            new NandFilter([new EqualsFilter('avatarUsers.id', Uuid::randomHex())])
        );

        $media = static::getContainer()->get('media.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertTrue(\count($media->getIds()) > 0);
        static::assertContains($ids->get('with-avatar'), $media->getIds());
        static::assertContains($ids->get('without-avatar'), $media->getIds());
    }

    #[Depends('testIndexing')]
    public function testAggregationWithFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsAnyFilter('properties.id', $ids->getList(['red']))
        );

        $criteria->addAggregation(
            new TermsAggregation('filters', 'properties.id')
        );

        $criteria->setLimit(0);

        $products = static::getContainer()->get('product.repository')
            ->search($criteria, Context::createDefaultContext());

        $aggregation = $products->getAggregations()->get('filters');

        static::assertInstanceOf(TermsResult::class, $aggregation);

        static::assertContains($ids->get('red'), $aggregation->getKeys());
        static::assertContains($ids->get('yellow'), $aggregation->getKeys());
        static::assertContains($ids->get('XL'), $aggregation->getKeys());
        static::assertContains($ids->get('L'), $aggregation->getKeys());
    }

    #[Depends('testIndexing')]
    public function testAggregationWithNegatedFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new NandFilter([
                new EqualsAnyFilter('properties.id', $ids->getList(['XL'])),
            ])
        );

        $criteria->addAggregation(
            new TermsAggregation('filters', 'properties.id')
        );

        $criteria->setLimit(0);

        $products = static::getContainer()->get('product.repository')
            ->search($criteria, Context::createDefaultContext());

        $aggregation = $products->getAggregations()->get('filters');

        static::assertInstanceOf(TermsResult::class, $aggregation);

        static::assertContains($ids->get('red'), $aggregation->getKeys());
        static::assertNotContains($ids->get('yellow'), $aggregation->getKeys());
        static::assertNotContains($ids->get('XL'), $aggregation->getKeys());
        static::assertNotContains($ids->get('L'), $aggregation->getKeys());
    }

    #[Depends('testIndexing')]
    public function testNestedManyToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('category-'));

        $criteria->addFilter(
            new EqualsAnyFilter('category.products.properties.id', [$ids->get('red'), $ids->get('yellow')])
        );
        $criteria->addFilter(
            new EqualsAnyFilter('category.products.properties.id', [$ids->get('XL'), $ids->get('L')])
        );

        $result = static::getContainer()->get('category.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('category-1')));
        static::assertTrue($result->has($ids->get('category-2')));
        static::assertFalse($result->has($ids->get('category-3')));
    }

    #[Depends('testIndexing')]
    public function testTranslatedFields(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.properties.name', 'red')
        );
        $criteria->addFilter(
            new EqualsFilter('product.properties.name', 'yellow')
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testContainsFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new ContainsFilter('product.properties.name', 're')
        );
        $criteria->addFilter(
            new ContainsFilter('product.properties.name', 'yell')
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertFalse($result->has($ids->get('product-2')));
    }

    #[Depends('testIndexing')]
    public function testPrefixFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        // "re" refers to the property "red" of "product-1" and "product-2"
        $criteria->addFilter(
            new PrefixFilter('product.properties.name', 're')
        );
        // "yell" refers to the property "yellow" of only "product-1"
        $criteria->addFilter(
            new PrefixFilter('product.properties.name', 'yell')
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertFalse($result->has($ids->get('product-2')));
    }

    #[Depends('testIndexing')]
    public function testSuffixFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        // "ed" refers to the property "red" of "product-1" and "product-2"
        $criteria->addFilter(
            new SuffixFilter('product.properties.name', 'ed')
        );
        // "low" refers to the property "yellow" of only "product-1"
        $criteria->addFilter(
            new SuffixFilter('product.properties.name', 'low')
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertFalse($result->has($ids->get('product-2')));
    }

    #[Depends('testIndexing')]
    public function testRangeFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('category-'));

        $criteria->addFilter(
            new RangeFilter('category.products.stock', [RangeFilter::GTE => 5])
        );

        $result = static::getContainer()->get('category.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('category-1')));
        static::assertTrue($result->has($ids->get('category-2')));
        static::assertFalse($result->has($ids->get('category-3')));
    }

    #[Depends('testIndexing')]
    public function testNegatedRangeFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('category-'));

        $criteria->addFilter(
            new NandFilter([new RangeFilter('category.products.stock', [RangeFilter::GTE => 5])])
        );

        $result = static::getContainer()->get('category.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertFalse($result->has($ids->get('category-1')));
        static::assertFalse($result->has($ids->get('category-2')));
        static::assertTrue($result->has($ids->get('category-3')));
    }

    #[Depends('testIndexing')]
    public function testOrFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new OrFilter([
                new EqualsFilter('product.properties.id', $ids->get('red')),
                new EqualsFilter('product.properties.id', $ids->get('yellow')),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testOneToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new AndFilter([
                new EqualsFilter('product.prices.ruleId', $ids->get('rule-1')),
                new RangeFilter('product.prices.price', [RangeFilter::GTE => 100]),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));

        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new AndFilter([
                new EqualsFilter('product.prices.ruleId', $ids->get('rule-1')),
                new RangeFilter('product.prices.price', [RangeFilter::LTE => 100]),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testOneToManyWithMultipleFilters(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.prices.ruleId', $ids->get('rule-1'))
        );
        $criteria->addFilter(
            new EqualsFilter('product.prices.ruleId', $ids->get('rule-2'))
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testManyToOne(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('category-'));

        $criteria->addFilter(
            new EqualsFilter('category.products.manufacturer.id', $ids->get('manufacturer-1'))
        );
        $criteria->addFilter(
            new EqualsFilter('category.products.manufacturer.name', 'manufacturer-1')
        );

        $result = static::getContainer()->get('category.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('category-1')));
        static::assertTrue($result->has($ids->get('category-2')));
        static::assertFalse($result->has($ids->get('category-3')));
    }

    #[Depends('testIndexing')]
    public function testManyToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.properties.id', $ids->get('red'))
        );
        $criteria->addFilter(
            new EqualsFilter('product.properties.id', $ids->get('yellow'))
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testManyToManyWithOneFilter(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new AndFilter([
                new EqualsFilter('product.properties.id', $ids->get('yellow')),
                new EqualsFilter('product.properties.name', 'yellow'),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testOneToManyTranslated(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('manufacturer-'));

        $criteria->addFilter(
            new EqualsFilter('product_manufacturer.products.name', 'product-1')
        );
        $criteria->addFilter(
            new EqualsFilter('product_manufacturer.products.stock', 10)
        );

        $result = static::getContainer()->get('product_manufacturer.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertTrue($result->has($ids->get('manufacturer-1')));
        static::assertFalse($result->has($ids->get('manufacturer-2')));

        $criteria = new Criteria($ids->prefixed('manufacturer-'));

        $criteria->addFilter(
            new ContainsFilter('product_manufacturer.products.name', 'product')
        );
        $criteria->addFilter(
            new RangeFilter('product_manufacturer.products.stock', [RangeFilter::GT => 1])
        );

        $result = static::getContainer()->get('product_manufacturer.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('manufacturer-1')));
        static::assertTrue($result->has($ids->get('manufacturer-2')));
    }

    #[Depends('testIndexing')]
    public function testManyToOneTranslated(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new NorFilter([
                new EqualsFilter('product.manufacturer.id', null),
                new EqualsFilter('product.manufacturer.name', 'test'),
            ]),
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));

        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new ContainsFilter('product.manufacturer.name', 'manufacturer')
        );
        $criteria->addFilter(
            new EqualsAnyFilter('product.manufacturer.id', $ids->getList(['manufacturer-1', 'manufacturer-2']))
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testManyToManyTranslated(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.properties.name', 'red')
        );
        $criteria->addFilter(
            new EqualsFilter('product.properties.name', 'yellow')
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertFalse($result->has($ids->get('product-2')));
    }

    #[Depends('testIndexing')]
    public function testOneToManyInherited(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new AndFilter([
                new EqualsFilter('product.prices.ruleId', $ids->get('rule-1')),
                new RangeFilter('product.prices.price', [RangeFilter::GTE => 100]),
            ])
        );

        $result = Context::createDefaultContext()->enableInheritance(fn (Context $context) => static::getContainer()->get('product.repository')
            ->searchIds($criteria, $context));

        static::assertSame(3, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertTrue($result->has($ids->get('product-1-variant')));
    }

    #[Depends('testIndexing')]
    public function testManyToOneInherited(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new NandFilter([
                new EqualsFilter('product.manufacturer.id', $ids->get('manufacturer-2')),
            ])
        );

        $result = Context::createDefaultContext()->enableInheritance(fn (Context $context) => static::getContainer()->get('product.repository')
            ->searchIds($criteria, $context));

        static::assertSame(3, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertTrue($result->has($ids->get('product-1-variant')));
        static::assertTrue($result->has($ids->get('product-3')));
    }

    #[Depends('testIndexing')]
    public function testManyToManyInherited(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.properties.id', $ids->get('red'))
        );
        $criteria->addFilter(
            new EqualsFilter('product.properties.id', $ids->get('yellow'))
        );

        $result = Context::createDefaultContext()->enableInheritance(fn (Context $context) => static::getContainer()->get('product.repository')
            ->searchIds($criteria, $context));

        static::assertSame(2, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertTrue($result->has($ids->get('product-1-variant')));
    }

    #[Depends('testIndexing')]
    public function testHasOneToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new NandFilter([
                new EqualsFilter('product.prices.id', null),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testHasManyToOne(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new NandFilter([
                new EqualsFilter('product.manufacturer.id', null),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testHasManyToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new NandFilter([
                new EqualsFilter('product.manufacturer.id', null),
            ])
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-2')));
        static::assertTrue($result->has($ids->get('product-1')));
        static::assertFalse($result->has($ids->get('product-3')));
    }

    #[Depends('testIndexing')]
    public function testHasNotOneToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.prices.id', null)
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-3')));
        static::assertTrue($result->has($ids->get('product-1-variant')));
        static::assertFalse($result->has($ids->get('product-1')));
        static::assertFalse($result->has($ids->get('product-2')));
    }

    #[Depends('testIndexing')]
    public function testHasNotManyToOne(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.manufacturer.id', null)
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertTrue($result->has($ids->get('product-3')));
        static::assertTrue($result->has($ids->get('product-1-variant')));
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertFalse($result->has($ids->get('product-1')));
    }

    #[Depends('testIndexing')]
    public function testHasNotManyToMany(IdsCollection $ids): void
    {
        $criteria = new Criteria($ids->prefixed('product-'));
        $criteria->addFilter(
            new EqualsFilter('product.properties.id', null)
        );

        $result = static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());

        static::assertSame(2, $result->getTotal());
        static::assertFalse($result->has($ids->get('product-2')));
        static::assertFalse($result->has($ids->get('product-1')));
        static::assertTrue($result->has($ids->get('product-3')));
        static::assertTrue($result->has($ids->get('product-1-variant')));
    }

    public function testEqualsNullWithUnmappedField(): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('unmappedField', null));

        static::expectException(UnmappedFieldException::class);
        static::getContainer()->get('product.repository')
            ->searchIds($criteria, Context::createDefaultContext());
    }
}
