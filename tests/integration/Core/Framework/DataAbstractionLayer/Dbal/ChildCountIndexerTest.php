<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\ChildCountUpdater;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class ChildCountIndexerTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntityRepository<CategoryCollection>
     */
    private EntityRepository $categoryRepository;

    private Context $context;

    private ChildCountUpdater $childCountIndexer;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->categoryRepository = static::getContainer()->get('category.repository');
        $this->context = Context::createDefaultContext();
        $this->childCountIndexer = static::getContainer()->get(ChildCountUpdater::class);
        $this->connection = static::getContainer()->get(Connection::class);
    }

    public function testCreateChildCategory(): void
    {
        /*
        Category A
        ├── Category B
        ├── Category C
        │  └── Category D
        */
        $categoryA = $this->createCategory();
        $categoryB = $this->createCategory($categoryA);
        $categoryC = $this->createCategory($categoryA);
        $categoryD = $this->createCategory($categoryC);

        $categories = $this->categoryRepository->search(new Criteria([$categoryA, $categoryB, $categoryC, $categoryD]), $this->context)->getEntities();

        static::assertNotNull($categories->get($categoryA));
        static::assertNotNull($categories->get($categoryB));
        static::assertNotNull($categories->get($categoryC));
        static::assertNotNull($categories->get($categoryD));

        static::assertSame(2, $categories->get($categoryA)->getChildCount());
        static::assertSame(0, $categories->get($categoryB)->getChildCount());
        static::assertSame(1, $categories->get($categoryC)->getChildCount());
        static::assertSame(0, $categories->get($categoryD)->getChildCount());

        $this->categoryRepository->update([[
            'id' => $categoryD,
            'parentId' => $categoryA,
        ]], $this->context);

        /*
        Category A
        ├── Category B
        ├── Category C
        ├── Category D
        */
        $categories = $this->categoryRepository->search(new Criteria([$categoryA, $categoryB, $categoryC, $categoryD]), $this->context)->getEntities();

        static::assertNotNull($categories->get($categoryA));
        static::assertNotNull($categories->get($categoryB));
        static::assertNotNull($categories->get($categoryC));
        static::assertNotNull($categories->get($categoryD));

        static::assertSame(3, $categories->get($categoryA)->getChildCount());
        static::assertSame(0, $categories->get($categoryB)->getChildCount());
        static::assertSame(0, $categories->get($categoryC)->getChildCount());
        static::assertSame(0, $categories->get($categoryD)->getChildCount());
    }

    public function testChildCountCategoryMovingMultipleCategories(): void
    {
        /*
        Category A
        ├── Category B
        │  └── Category C
        ├── Category D
        │  └── Category E
        */
        $categoryA = $this->createCategory();
        $categoryB = $this->createCategory($categoryA);
        $categoryC = $this->createCategory($categoryB);

        $categoryD = $this->createCategory($categoryA);
        $categoryE = $this->createCategory($categoryD);

        $categories = $this->categoryRepository->search(
            new Criteria([$categoryA, $categoryB, $categoryC, $categoryD, $categoryE]),
            $this->context
        )->getEntities();

        static::assertNotNull($categories->get($categoryA));
        static::assertNotNull($categories->get($categoryB));
        static::assertNotNull($categories->get($categoryC));
        static::assertNotNull($categories->get($categoryD));
        static::assertNotNull($categories->get($categoryE));

        static::assertSame(2, $categories->get($categoryA)->getChildCount());
        static::assertSame(1, $categories->get($categoryB)->getChildCount());
        static::assertSame(0, $categories->get($categoryC)->getChildCount());
        static::assertSame(1, $categories->get($categoryD)->getChildCount());
        static::assertSame(0, $categories->get($categoryE)->getChildCount());

        $this->categoryRepository->update([
            [
                'id' => $categoryC,
                'parentId' => $categoryA,
            ],
            [
                'id' => $categoryD,
                'parentId' => $categoryC,
            ],
            [
                'id' => $categoryE,
                'parentId' => $categoryC,
            ],
        ], $this->context);

        /**
         * Category A
         * ├── Category B
         * ├── Category C
         * │  └── Category D
         * │  └── Category E
         */
        $categories = $this->categoryRepository->search(
            new Criteria([$categoryA, $categoryB, $categoryC, $categoryD, $categoryE]),
            $this->context
        )->getEntities();

        static::assertNotNull($categories->get($categoryA));
        static::assertNotNull($categories->get($categoryB));
        static::assertNotNull($categories->get($categoryC));
        static::assertNotNull($categories->get($categoryD));
        static::assertNotNull($categories->get($categoryE));

        static::assertSame(2, $categories->get($categoryA)->getChildCount());
        static::assertSame(0, $categories->get($categoryB)->getChildCount());
        static::assertSame(2, $categories->get($categoryC)->getChildCount());
        static::assertSame(0, $categories->get($categoryD)->getChildCount());
        static::assertSame(0, $categories->get($categoryE)->getChildCount());
    }

    public function testChildCountIndexer(): void
    {
        /*
        Category A
        ├── Category B
        ├── Category C
        │  └── Category D
        */
        $categoryA = $this->createCategory();

        $categoryB = $this->createCategory($categoryA);
        $categoryC = $this->createCategory($categoryA);

        $categoryD = $this->createCategory($categoryC);

        $this->connection->executeStatement(
            'UPDATE category SET child_count = 0 WHERE id IN (:ids)',
            [
                'ids' => Uuid::fromHexToBytesList([
                    $categoryA,
                    $categoryB,
                    $categoryC,
                    $categoryD,
                ]),
            ],
            ['ids' => ArrayParameterType::BINARY]
        );

        $categories = $this->categoryRepository->search(new Criteria([$categoryA, $categoryB, $categoryC, $categoryD]), $this->context)->getEntities();

        foreach ($categories as $category) {
            static::assertSame(0, $category->getChildCount());
        }

        $this->childCountIndexer->update(CategoryDefinition::ENTITY_NAME, [$categoryA, $categoryB, $categoryC, $categoryD], $this->context);

        $categories = $this->categoryRepository->search(new Criteria([$categoryA, $categoryB, $categoryC, $categoryD]), $this->context)->getEntities();

        static::assertNotNull($categories->get($categoryA));
        static::assertNotNull($categories->get($categoryB));
        static::assertNotNull($categories->get($categoryC));
        static::assertNotNull($categories->get($categoryD));

        static::assertSame(2, $categories->get($categoryA)->getChildCount());
        static::assertSame(0, $categories->get($categoryB)->getChildCount());
        static::assertSame(1, $categories->get($categoryC)->getChildCount());
        static::assertSame(0, $categories->get($categoryD)->getChildCount());
    }

    public function testDeleteProductWithRecalculatedChildCount(): void
    {
        $ids = new IdsCollection();

        $products = [
            (new ProductBuilder($ids, 'parent'))
                ->price(100)
                ->variant((new ProductBuilder($ids, 'variant-1'))->price(200)->build())
                ->variant((new ProductBuilder($ids, 'variant-2'))->price(200)->build())
                ->build(),
        ];

        static::getContainer()->get('product.repository')->create($products, Context::createDefaultContext());

        $count = (int) $this->connection->fetchOne('SELECT child_count FROM product WHERE id = :id', ['id' => $ids->getBytes('parent')]);
        static::assertSame(2, $count);

        static::getContainer()->get('product.repository')->delete([['id' => $ids->get('variant-1')]], Context::createDefaultContext());
        $count = (int) $this->connection->fetchOne('SELECT child_count FROM product WHERE id = :id', ['id' => $ids->getBytes('parent')]);
        static::assertSame(1, $count);

        static::getContainer()->get('product.repository')->delete([['id' => $ids->get('variant-2')]], Context::createDefaultContext());
        $count = (int) $this->connection->fetchOne('SELECT child_count FROM product WHERE id = :id', ['id' => $ids->getBytes('parent')]);
        static::assertSame(0, $count);
    }

    private function createCategory(?string $parentId = null): string
    {
        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => 'Category ',
        ];

        if ($parentId) {
            $data['parentId'] = $parentId;
        }
        $this->categoryRepository->upsert([$data], $this->context);

        return $id;
    }
}
