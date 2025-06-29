<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Reader;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationCollection;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationEntity;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Exception\ParentAssociationCanNotBeFetched;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Extension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\PartialEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\DataAbstractionLayerFieldTestBehaviour;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ConsistsOfManyToManyDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\NonIdFieldNamePrimaryKeyTestDefinition;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\Test\Integration\Builder\Customer\CustomerBuilder;
use Shopware\Core\Test\Integration\Builder\Order\OrderBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
class EntityReaderTest extends TestCase
{
    use DataAbstractionLayerFieldTestBehaviour {
        tearDown as protected tearDownDefinitions;
    }
    use IntegrationTestBehaviour;

    private Connection $connection;

    /**
     * @var EntityRepository<ProductCollection>
     */
    private EntityRepository $productRepository;

    /**
     * @var EntityRepository<OrderCollection>
     */
    private EntityRepository $orderRepository;

    /**
     * @var EntityRepository<CategoryCollection>
     */
    private EntityRepository $categoryRepository;

    /**
     * @var EntityRepository<CustomerCollection>
     */
    private EntityRepository $customerRepository;

    /**
     * @var EntityRepository<LanguageCollection>
     */
    private EntityRepository $languageRepository;

    private string $deLanguageId;

    protected function setUp(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);
        $this->productRepository = static::getContainer()->get('product.repository');
        $this->orderRepository = static::getContainer()->get('order.repository');
        $this->categoryRepository = static::getContainer()->get('category.repository');
        $this->languageRepository = static::getContainer()->get('language.repository');
        $this->customerRepository = static::getContainer()->get('customer.repository');
        $this->deLanguageId = $this->getDeDeLanguageId();

        $this->registerDefinition(NonIdFieldNamePrimaryKeyTestDefinition::class);
        $this->registerDefinition(ConsistsOfManyToManyDefinition::class);

        $this->connection->rollBack();

        $this->connection->executeStatement('
            DROP TABLE IF EXISTS `non_id_primary_key_test`;
            CREATE TABLE `non_id_primary_key_test` (
                `test_field` BINARY(16) NOT NULL,
                `non_pk` BINARY(16) NULL,
                `name` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`test_field`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            DROP TABLE IF EXISTS `acme_consists_of_mapping`;
            CREATE TABLE acme_consists_of_mapping (
                product_id         binary(16) not null,
                product_version_id binary(16) not null,
                product_id_to      binary(16) not null,
                PRIMARY KEY (product_id, product_version_id, product_id_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->tearDownDefinitions();
        $this->connection->rollBack();

        $this->connection->executeStatement('DROP TABLE `non_id_primary_key_test`');
        $this->connection->executeStatement('DROP TABLE `acme_consists_of_mapping`');
        $this->connection->beginTransaction();

        parent::tearDown();
    }

    public function testPartialLoadingAddsImplicitAssociationToRequestedFields(): void
    {
        $ids = new IdsCollection();

        $product = (new ProductBuilder($ids, 'p1'))
            ->price(100)
            ->categories(['c1', 'c2'])
            ->visibility()
            ->manufacturer('m1');

        $this->productRepository
            ->create([$product->build()], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('p1')]);
        $criteria->addFields(['productNumber', 'name', 'categories.name']);

        $values = $this->productRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        $entity = $values->first();

        static::assertInstanceOf(PartialEntity::class, $entity);
        static::assertSame('p1', $entity->get('productNumber'));
        static::assertSame('p1', $entity->get('name'));
        static::assertNull($entity->get('active'));

        /** @var EntityCollection<PartialEntity> $collection */
        $collection = $entity->get('categories');

        static::assertInstanceOf(PartialEntity::class, $collection->first());
        $collection->sortByIdArray([$ids->get('c1'), $ids->get('c2')]);

        static::assertSame('c1', $collection->first()?->get('name'));
    }

    public function testPartialLoadingManyToOne(): void
    {
        $ids = new IdsCollection();

        $product = (new ProductBuilder($ids, 'p1'))
            ->price(100)
            ->categories(['c1', 'c2'])
            ->visibility()
            ->manufacturer('m1');

        $this->productRepository
            ->create([$product->build()], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('p1')]);
        $criteria->addFields(['id', 'productNumber', 'name', 'manufacturer.id', 'manufacturer.name']);

        $values = $this->productRepository->search($criteria, Context::createDefaultContext());

        $entity = $values->first();

        static::assertInstanceOf(PartialEntity::class, $entity);
        static::assertSame('p1', $entity->get('productNumber'));
        static::assertSame('p1', $entity->get('name'));
        static::assertNull($entity->get('active'));

        static::assertInstanceOf(PartialEntity::class, $entity->get('manufacturer'));
        static::assertSame($ids->get('m1'), $entity->get('manufacturer')->get('id'));
        static::assertSame('m1', $entity->get('manufacturer')->get('name'));
    }

    public function testPartialLoadingOneToOneWithReferenceFieldInOtherTable(): void
    {
        $ids = new IdsCollection();

        $customer = (new CustomerBuilder($ids, 'customer1'));
        $order = (new OrderBuilder($ids, 'order1'))->orderCustomer('First Name Test', 'customer1');

        $this->customerRepository->create([$customer->build()], Context::createDefaultContext());
        $this->orderRepository->create([$order->build()], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('order1')]);
        $criteria->addFields(['id', 'orderNumber', 'orderCustomer.firstName']);

        $partialOrder = $this->orderRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertInstanceOf(PartialEntity::class, $partialOrder);
        static::assertSame('order1', $partialOrder->get('orderNumber'));

        static::assertInstanceOf(PartialEntity::class, $partialOrder->get('orderCustomer'));
        static::assertSame('First Name Test', $partialOrder->get('orderCustomer')->get('firstName'));
    }

    public function testPartialLoadingOneToMany(): void
    {
        $ids = new IdsCollection();

        $this->categoryRepository->upsert([
            [
                'id' => $ids->get('c1'),
                'name' => 'test',
                'seoUrls' => [
                    [
                        'id' => $ids->get('c1-url'),
                        'routeName' => 'frontend.category.page',
                        'seoPathInfo' => '/test',
                        'pathInfo' => '/test',
                        'languageId' => $this->deLanguageId,
                    ],
                ],
            ],
        ], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('c1')]);
        $criteria->addAssociation('seoUrls');
        $criteria->addFields(['name', 'seoUrls.routeName']);

        $values = static::getContainer()
            ->get('category.repository')
            ->search($criteria, Context::createDefaultContext());

        $entity = $values->first();

        static::assertInstanceOf(PartialEntity::class, $entity);
        static::assertSame('test', $entity->get('name'));
        static::assertNull($entity->get('type'));
        static::assertInstanceOf(EntityCollection::class, $entity->get('seoUrls'));
        static::assertInstanceOf(PartialEntity::class, $entity->get('seoUrls')->first());
        static::assertSame('frontend.category.page', $entity->get('seoUrls')->first()->get('routeName'));
        static::assertNull($entity->get('seoUrls')->first()->get('pathInfo'));

        // Test same with pagination

        $criteria->setLimit(50);
        $criteria->getAssociation('seoUrls')->setLimit(50);
        $values = static::getContainer()
            ->get('category.repository')
            ->search($criteria, Context::createDefaultContext());

        $entity = $values->first();

        static::assertInstanceOf(PartialEntity::class, $entity);
        static::assertSame('test', $entity->get('name'));
        static::assertNull($entity->get('type'));
        static::assertInstanceOf(EntityCollection::class, $entity->get('seoUrls'));
        static::assertInstanceOf(PartialEntity::class, $entity->get('seoUrls')->first());
        static::assertSame('frontend.category.page', $entity->get('seoUrls')->first()->get('routeName'));
        static::assertNull($entity->get('seoUrls')->first()->get('pathInfo'));
    }

    public function testPartialLoadingManyToMany(): void
    {
        $ids = new IdsCollection();

        $products = [
            (new ProductBuilder($ids, 'p1'))
                ->price(100)
                ->categories(['c1', 'c2'])
                ->visibility()
                ->manufacturer('m1')
            ->build(),
        ];

        static::getContainer()->get('product.repository')
            ->create($products, Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('p1')]);
        $criteria->addAssociation('categories');
        $criteria->addAssociation('manufacturer');
        $criteria->getAssociation('categories')->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->addFields(['name', 'categories.name', 'manufacturer.name']);

        $values = static::getContainer()
            ->get('product.repository')
            ->search($criteria, Context::createDefaultContext());

        $entity = $values->first();

        static::assertInstanceOf(PartialEntity::class, $entity);
        static::assertSame('p1', $entity->get('name'));
        static::assertNull($entity->get('type'));
        static::assertInstanceOf(EntityCollection::class, $entity->get('categories'));
        static::assertInstanceOf(PartialEntity::class, $entity->get('categories')->first());
        static::assertSame('c1', $entity->get('categories')->first()->get('name'));
        static::assertInstanceOf(PartialEntity::class, $entity->get('manufacturer'));
        static::assertSame('m1', $entity->get('manufacturer')->get('name'));

        // With pagination
        $criteria->setLimit(50);
        $criteria->getAssociation('categories')->setLimit(50);
        $criteria->getAssociation('manufacturer')->setLimit(50);

        $values = static::getContainer()
            ->get('product.repository')
            ->search($criteria, Context::createDefaultContext());

        $entity = $values->first();

        static::assertInstanceOf(PartialEntity::class, $entity);
        static::assertSame('p1', $entity->get('name'));
        static::assertNull($entity->get('type'));
        static::assertInstanceOf(EntityCollection::class, $entity->get('categories'));
        static::assertInstanceOf(PartialEntity::class, $entity->get('categories')->first());
        static::assertSame('c1', $entity->get('categories')->first()->get('name'));
        static::assertInstanceOf(PartialEntity::class, $entity->get('manufacturer'));
        static::assertSame('m1', $entity->get('manufacturer')->get('name'));
    }

    public function testTranslated(): void
    {
        $id = Uuid::randomHex();
        $data = [
            ['id' => $id, 'name' => 'test'],
        ];

        $context = Context::createDefaultContext();
        $this->categoryRepository->create($data, $context);

        $context = new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [
                Defaults::LANGUAGE_SYSTEM,
                $this->deLanguageId,
            ]
        );

        $categories = $this->categoryRepository
            ->search(new Criteria([$id]), $context)
            ->getEntities();

        $category = $categories->first();

        static::assertInstanceOf(CategoryEntity::class, $category);
        static::assertSame('test', $category->getName());
        static::assertSame('test', $category->getTranslated()['name']);

        $context = new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [
                $this->deLanguageId,
                Defaults::LANGUAGE_SYSTEM,
            ]
        );

        $categories = $this->categoryRepository
            ->search(new Criteria([$id]), $context)
            ->getEntities();

        $category = $categories->first();

        static::assertInstanceOf(CategoryEntity::class, $category);
        static::assertNull($category->getName());
        static::assertSame('test', $category->getTranslated()['name']);

        $context = new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [
                $this->deLanguageId,
            ]
        );

        $categories = $this->categoryRepository
            ->search(new Criteria([$id]), $context)
            ->getEntities();

        $category = $categories->first();

        static::assertInstanceOf(CategoryEntity::class, $category);
        static::assertNull($category->getName());
        static::assertNull($category->getTranslated()['name']);
    }

    public function testTranslatedFieldsContainsNoInheritance(): void
    {
        $id = Uuid::randomHex();

        $subLanguageId = Uuid::randomHex();

        $context = Context::createDefaultContext();

        $this->languageRepository->create([
            [
                'id' => $subLanguageId,
                'name' => 'en_sub',
                'parentId' => Defaults::LANGUAGE_SYSTEM,
                'localeId' => $this->getLocaleIdOfSystemLanguage(),
            ],
        ], $context);

        $product = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['taxRate' => 13, 'name' => 'green'],
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => ['name' => 'EN'],
                $this->deLanguageId => ['name' => 'DE'],
                $subLanguageId => ['description' => 'test'],
            ],
        ];

        $this->productRepository->create([$product], $context);

        $context = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$subLanguageId, Defaults::LANGUAGE_SYSTEM]
        );

        $product = $this->productRepository
            ->search(new Criteria([$id]), $context)
            ->getEntities()
            ->first();

        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertNull($product->getName());
        static::assertSame('test', $product->getDescription());
    }

    public function testInheritedTranslationsInViewData(): void
    {
        $id = Uuid::randomHex();

        $subLanguageId = Uuid::randomHex();

        $context = Context::createDefaultContext();

        $this->languageRepository->create([
            [
                'id' => $subLanguageId,
                'name' => 'en_sub',
                'parentId' => Defaults::LANGUAGE_SYSTEM,
                'localeId' => $this->getLocaleIdOfSystemLanguage(),
            ],
        ], $context);

        $product = [
            'id' => $id,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'manufacturer' => ['name' => 'test'],
            'tax' => ['taxRate' => 13, 'name' => 'green'],
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => ['name' => 'EN'],
                $this->deLanguageId => ['name' => 'DE'],
                $subLanguageId => ['description' => 'test'],
            ],
        ];

        $this->productRepository->create([$product], $context);

        $context = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$subLanguageId, Defaults::LANGUAGE_SYSTEM]
        );

        $product = $this->productRepository->search(new Criteria([$id]), $context)
            ->getEntities()
            ->first();

        static::assertInstanceOf(ProductEntity::class, $product);
        static::assertSame('EN', $product->getTranslated()['name']);
        static::assertSame('test', $product->getTranslated()['description']);
    }

    public function testParentInheritanceInViewData(): void
    {
        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $parentTax = Uuid::randomHex();
        $greenTax = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'manufacturer' => ['name' => 'test'],
                'name' => 'parent',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 50, 'net' => 50, 'linked' => true]],
                'tax' => ['id' => $parentTax, 'taxRate' => 13, 'name' => 'parent tax'],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'red',
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'green',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 100, 'linked' => true]],
                'tax' => ['id' => $greenTax, 'taxRate' => 13, 'name' => 'green tax'],
            ],
        ];

        $this->productRepository->create($products, Context::createDefaultContext());

        $criteria = new Criteria([$parentId, $greenId, $redId]);
        $criteria->addAssociation('tax');
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(false);
        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(TaxEntity::class, $parent->getTax());
        static::assertInstanceOf(Price::class, $parent->getCurrencyPrice(Defaults::CURRENCY));
        static::assertSame(50.0, $parent->getCurrencyPrice(Defaults::CURRENCY)->getGross());

        $red = $products->get($redId);

        // check red product contains full inheritance of parent
        static::assertInstanceOf(ProductEntity::class, $red);

        // has no own tax
        static::assertNull($red->getTax());
        static::assertNull($red->getTaxId());
        static::assertNull($red->getCurrencyPrice(Defaults::CURRENCY));

        $green = $products->get($greenId);

        static::assertInstanceOf(ProductEntity::class, $green);
        static::assertInstanceOf(TaxEntity::class, $green->getTax());
        static::assertSame($greenTax, $green->getTaxId());
        static::assertInstanceOf(Price::class, $green->getCurrencyPrice(Defaults::CURRENCY));
        static::assertSame(100.0, $green->getCurrencyPrice(Defaults::CURRENCY)->getGross());

        $criteria = new Criteria([$parentId, $greenId, $redId]);
        $criteria->addAssociation('tax');
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);
        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(TaxEntity::class, $parent->getTax());
        static::assertInstanceOf(Price::class, $parent->getCurrencyPrice(Defaults::CURRENCY));
        static::assertSame(50.0, $parent->getCurrencyPrice(Defaults::CURRENCY)->getGross());

        $red = $products->get($redId);

        // check red product contains full inheritance of parent
        static::assertInstanceOf(ProductEntity::class, $red);

        // price and tax are inherited by parent
        static::assertInstanceOf(TaxEntity::class, $red->getTax());
        static::assertSame($parentTax, $red->getTaxId());
        static::assertInstanceOf(Price::class, $red->getCurrencyPrice(Defaults::CURRENCY));
        static::assertSame(50.0, $red->getCurrencyPrice(Defaults::CURRENCY)->getGross());

        $green = $products->get($greenId);
        static::assertInstanceOf(ProductEntity::class, $green);
        static::assertInstanceOf(TaxEntity::class, $green->getTax());
        static::assertSame($greenTax, $green->getTaxId());
        static::assertInstanceOf(Price::class, $green->getCurrencyPrice(Defaults::CURRENCY));
        static::assertSame(100.0, $green->getCurrencyPrice(Defaults::CURRENCY)->getGross());
    }

    public function testInheritanceWithOneToMany(): void
    {
        $ruleA = Uuid::randomHex();
        $ruleB = Uuid::randomHex();

        static::getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleB, 'name' => 'test', 'priority' => 2],
        ], Context::createDefaultContext());

        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'name' => 'parent',
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'quantityEnd' => 20,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 100, 'linked' => false]],
                    ],
                    [
                        'quantityStart' => 21,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 50, 'linked' => false]],
                    ],
                ],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'red',
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'green',
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 50, 'linked' => false]],
                    ],
                ],
            ],
        ];

        $context = Context::createDefaultContext();

        $this->productRepository->create($products, $context);

        $criteria = new Criteria([$greenId, $parentId, $redId]);
        $criteria->addAssociation('prices');
        $context->setConsiderInheritance(true);

        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(ProductPriceCollection::class, $parent->getPrices());

        $red = $products->get($redId);
        static::assertInstanceOf(ProductEntity::class, $red);
        $productPriceCollection = $red->getPrices();
        static::assertNotNull($productPriceCollection);

        static::assertCount(2, $productPriceCollection);

        $green = $products->get($greenId);
        static::assertInstanceOf(ProductEntity::class, $green);
        static::assertInstanceOf(ProductPriceCollection::class, $green->getPrices());
    }

    public function testInheritanceWithPaginatedOneToMany(): void
    {
        $ruleA = Uuid::randomHex();
        $ruleB = Uuid::randomHex();

        static::getContainer()->get('rule.repository')->create([
            ['id' => $ruleA, 'name' => 'test', 'priority' => 1],
            ['id' => $ruleB, 'name' => 'test', 'priority' => 2],
        ], Context::createDefaultContext());

        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'name' => 'parent',
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'quantityEnd' => 20,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 100, 'net' => 100, 'linked' => false]],
                    ],
                    [
                        'quantityStart' => 21,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 50, 'linked' => false]],
                    ],
                ],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'red',
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'green',
                'prices' => [
                    [
                        'quantityStart' => 1,
                        'ruleId' => $ruleA,
                        'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 50, 'linked' => false]],
                    ],
                ],
            ],
        ];

        $context = Context::createDefaultContext();

        $this->productRepository->create($products, $context);

        $criteria = new Criteria([$greenId, $parentId, $redId]);
        $criteria->getAssociation('prices')->setLimit(5);
        $context->setConsiderInheritance(true);

        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(ProductPriceCollection::class, $parent->getPrices());

        $red = $products->get($redId);
        static::assertInstanceOf(ProductEntity::class, $red);
        $productPriceCollection = $red->getPrices();
        static::assertNotNull($productPriceCollection);
        static::assertCount(2, $productPriceCollection);

        $green = $products->get($greenId);
        static::assertInstanceOf(ProductEntity::class, $green);
        static::assertInstanceOf(ProductPriceCollection::class, $green->getPrices());
    }

    public function testInheritanceWithManyToMany(): void
    {
        $category1 = Uuid::randomHex();
        $category2 = Uuid::randomHex();
        $category3 = Uuid::randomHex();

        $categories = [
            ['id' => $category1, 'name' => 'cat1'],
            ['id' => $category2, 'name' => 'cat2'],
            ['id' => $category3, 'name' => 'cat2'],
        ];

        $context = Context::createDefaultContext();

        $this->categoryRepository->create($categories, $context);

        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'name' => 'parent',
                'categories' => [
                    ['id' => $category1],
                    ['id' => $category3],
                ],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'red',
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'green',
                'categories' => [
                    ['id' => $category2],
                ],
            ],
        ];

        $this->productRepository->create($products, $context);

        $criteria = new Criteria([$greenId, $parentId, $redId]);
        $criteria->addAssociation('categories');
        $context->setConsiderInheritance(true);

        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        $red = $products->get($redId);
        $green = $products->get($greenId);

        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(ProductEntity::class, $red);
        static::assertInstanceOf(ProductEntity::class, $green);

        // validate parent view data contains same categories
        $categoryCollectionParent = $parent->getCategories();
        static::assertInstanceOf(CategoryCollection::class, $categoryCollectionParent);
        static::assertCount(2, $categoryCollectionParent);
        static::assertTrue($categoryCollectionParent->has($category1));
        static::assertTrue($categoryCollectionParent->has($category3));

        // validate red view data contains the categories of the parent
        $categoryCollection = $red->getCategories();
        static::assertInstanceOf(CategoryCollection::class, $categoryCollection);
        static::assertCount(2, $categoryCollection);
        static::assertTrue($categoryCollection->has($category1));
        static::assertTrue($categoryCollection->has($category3));

        // validate green view data contains same categories
        $categoryCollectionGreen = $green->getCategories();
        static::assertInstanceOf(CategoryCollection::class, $categoryCollectionGreen);
        static::assertCount(1, $categoryCollectionGreen);
        static::assertTrue($categoryCollectionGreen->has($category2));

        // ####
        $criteria = new Criteria([$greenId, $parentId, $redId]);
        $criteria->addAssociation('categories');
        $context->setConsiderInheritance(false);

        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        $red = $products->get($redId);
        $green = $products->get($greenId);

        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(ProductEntity::class, $red);
        static::assertInstanceOf(ProductEntity::class, $green);

        // validate parent contains own categories
        static::assertCount(2, $categoryCollectionParent);
        static::assertTrue($categoryCollectionParent->has($category1));
        static::assertTrue($categoryCollectionParent->has($category3));

        // validate red contains no own categories
        $redCategories = $red->getCategories();
        static::assertInstanceOf(CategoryCollection::class, $redCategories);
        static::assertCount(0, $redCategories);

        // validate green contains own categories
        static::assertCount(1, $categoryCollectionGreen);
        static::assertTrue($categoryCollectionGreen->has($category2));
    }

    public function testInheritanceWithPaginatedManyToMany(): void
    {
        $category1 = Uuid::randomHex();
        $category2 = Uuid::randomHex();
        $category3 = Uuid::randomHex();

        $categories = [
            ['id' => $category1, 'name' => 'cat1'],
            ['id' => $category2, 'name' => 'cat2'],
            ['id' => $category3, 'name' => 'cat2'],
        ];

        $context = Context::createDefaultContext();

        $this->categoryRepository->create($categories, $context);

        $redId = Uuid::randomHex();
        $greenId = Uuid::randomHex();
        $parentId = Uuid::randomHex();

        $products = [
            [
                'id' => $parentId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'name' => 'parent',
                'categories' => [
                    ['id' => $category1],
                    ['id' => $category3],
                ],
            ],
            [
                'id' => $redId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'red',
            ],
            [
                'id' => $greenId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'parentId' => $parentId,
                'name' => 'green',
                'categories' => [
                    ['id' => $category2],
                ],
            ],
        ];

        $this->productRepository->create($products, $context);

        $criteria = new Criteria([$greenId, $parentId, $redId]);
        $criteria->addAssociation('categories')->setLimit(3);
        $context->setConsiderInheritance(true);
        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        $red = $products->get($redId);
        $green = $products->get($greenId);

        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(ProductEntity::class, $red);
        static::assertInstanceOf(ProductEntity::class, $green);

        // validate parent view data contains same categories
        $parentCategories = $parent->getCategories();
        static::assertInstanceOf(CategoryCollection::class, $parentCategories);
        static::assertCount(2, $parentCategories);
        static::assertTrue($parentCategories->has($category1));
        static::assertTrue($parentCategories->has($category3));

        // validate red view data contains the categories of the parent
        $redCategories = $red->getCategories();
        static::assertInstanceOf(CategoryCollection::class, $redCategories);
        static::assertCount(2, $redCategories);
        static::assertTrue($redCategories->has($category1));
        static::assertTrue($redCategories->has($category3));

        // validate green view data contains same categories
        static::assertInstanceOf(CategoryCollection::class, $green->getCategories());
        static::assertCount(1, $green->getCategories());
        static::assertTrue($green->getCategories()->has($category2));

        $criteria = new Criteria([$greenId, $parentId, $redId]);
        $criteria->addAssociation('categories')->setLimit(3);
        $context->setConsiderInheritance(false);
        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $parent = $products->get($parentId);
        $red = $products->get($redId);
        $green = $products->get($greenId);

        static::assertInstanceOf(ProductEntity::class, $parent);
        static::assertInstanceOf(ProductEntity::class, $red);
        static::assertInstanceOf(ProductEntity::class, $green);

        // validate parent contains own categories
        $parentCategories = $parent->getCategories();
        static::assertNotNull($parentCategories);
        static::assertCount(2, $parentCategories);
        static::assertTrue($parentCategories->has($category1));
        static::assertTrue($parentCategories->has($category3));

        // validate green contains own categories
        $greenCategories = $green->getCategories();
        static::assertNotNull($greenCategories);
        static::assertCount(1, $greenCategories);
        static::assertTrue($greenCategories->has($category2));

        // validate red contains no own categories
        static::assertNotNull($red->getCategories());
        static::assertCount(0, $red->getCategories());
    }

    public function testLoadOneToManyNotLoadedAutomatically(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $defaultAddressId = Uuid::randomHex();

        $address = [
            'street' => 'A',
            'zipcode' => 'A',
            'city' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'A',
            'lastName' => 'a',
            'countryId' => $this->getValidCountryId(),
        ];

        $customer = [
            'id' => $id,
            'firstName' => 'Test',
            'lastName' => 'Test',
            'customerNumber' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'password' => TestDefaults::HASHED_PASSWORD,
            'email' => 'test@test.com' . $id,
            'defaultShippingAddressId' => $defaultAddressId,
            'defaultBillingAddressId' => $defaultAddressId,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'group' => ['name' => 'test'],
            'addresses' => [
                array_merge(['id' => $defaultAddressId], $address),
                $address,
                $address,
                $address,
                $address,
            ],
        ];

        $this->customerRepository->upsert([$customer], $context);

        $criteria = new Criteria([$id]);

        $customer = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities()
            ->get($id);
        static::assertInstanceOf(CustomerEntity::class, $customer);
        static::assertNull($customer->getAddresses());
    }

    public function testLoadOneToMany(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $defaultAddressId = Uuid::randomHex();

        $address = [
            'street' => 'A',
            'zipcode' => 'A',
            'city' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'A',
            'lastName' => 'a',
            'countryId' => $this->getValidCountryId(),
        ];

        $customer = [
            'id' => $id,
            'firstName' => 'Test',
            'lastName' => 'Test',
            'customerNumber' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'password' => TestDefaults::HASHED_PASSWORD,
            'email' => 'test@test.com' . $id,
            'defaultShippingAddressId' => $defaultAddressId,
            'defaultBillingAddressId' => $defaultAddressId,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'group' => ['name' => 'test'],
            'addresses' => [
                array_merge(['id' => $defaultAddressId], $address),
                $address,
                $address,
                $address,
                $address,
            ],
        ];

        $this->customerRepository->upsert([$customer], $context);

        $addresses = (int) $this->connection->fetchOne('SELECT COUNT(id) FROM customer_address WHERE customer_id = :id', ['id' => Uuid::fromHexToBytes($id)]);
        static::assertSame(5, $addresses);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('addresses');
        $customer = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities()
            ->get($id);

        static::assertInstanceOf(CustomerEntity::class, $customer);
        static::assertInstanceOf(CustomerAddressCollection::class, $customer->getAddresses());
        static::assertCount(5, $customer->getAddresses());
    }

    public function testLoadOneToManySupportsFilter(): void
    {
        $context = Context::createDefaultContext();

        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $defaultAddressId1 = Uuid::randomHex();
        $defaultAddressId2 = Uuid::randomHex();

        $address = [
            'street' => 'A',
            'zipcode' => 'A',
            'city' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'A',
            'lastName' => 'a',
            'countryId' => $this->getValidCountryId(),
        ];
        $customer = [
            'firstName' => 'Test',
            'lastName' => 'Test',
            'customerNumber' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'password' => TestDefaults::HASHED_PASSWORD,
            'email' => 'test@example.com',
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'group' => ['name' => 'test'],
        ];

        $this->customerRepository->upsert([
            array_merge(
                $customer,
                [
                    'id' => $id1,
                    'email' => Uuid::randomHex() . '@example.com',
                    'defaultShippingAddressId' => $defaultAddressId1,
                    'defaultBillingAddressId' => $defaultAddressId1,
                    'addresses' => [
                        array_merge(['id' => $defaultAddressId1], $address),
                        array_merge($address, ['zipcode' => 'B']),
                        array_merge($address, ['zipcode' => 'B']),
                        array_merge($address, ['zipcode' => 'X']),
                    ],
                ]
            ),
            array_merge(
                $customer,
                [
                    'id' => $id2,
                    'email' => Uuid::randomHex() . '@example.com',
                    'defaultShippingAddressId' => $defaultAddressId2,
                    'defaultBillingAddressId' => $defaultAddressId2,
                    'addresses' => [
                        array_merge(['id' => $defaultAddressId2], $address),
                        array_merge($address, ['zipcode' => 'B']),
                        array_merge($address, ['zipcode' => 'C']),
                        array_merge($address, ['zipcode' => 'X']),
                    ],
                ]
            ),
        ], $context);

        $bytes = [Uuid::fromHexToBytes($id1), Uuid::fromHexToBytes($id2)];

        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM customer WHERE id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(2, $mapping);

        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM customer_address WHERE customer_id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(8, $mapping);

        $criteria = new Criteria([$id1, $id2]);

        $criteria->getAssociation('addresses')
            ->addFilter(new EqualsFilter('customer_address.zipcode', 'B'));

        $customers = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities();

        $customer1 = $customers->get($id1);
        $customer2 = $customers->get($id2);

        static::assertInstanceOf(CustomerEntity::class, $customer1);
        static::assertInstanceOf(CustomerEntity::class, $customer2);

        $customer1Addresses = $customer1->getAddresses();
        static::assertInstanceOf(CustomerAddressCollection::class, $customer1Addresses);
        static::assertCount(2, $customer1Addresses);

        $customer2Addresses = $customer2->getAddresses();
        static::assertInstanceOf(CustomerAddressCollection::class, $customer2Addresses);
        static::assertCount(1, $customer2Addresses);
    }

    public function testLoadOneToManySupportsSorting(): void
    {
        $context = Context::createDefaultContext();

        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();

        $addressId1 = Uuid::randomHex();
        $addressId2 = Uuid::randomHex();
        $addressId3 = Uuid::randomHex();
        $addressId4 = Uuid::randomHex();
        $addressId5 = Uuid::randomHex();
        $addressId6 = Uuid::randomHex();

        $repository = $this->customerRepository;

        $address = [
            'street' => 'A',
            'zipcode' => 'A',
            'city' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'A',
            'lastName' => 'a',
            'countryId' => $this->getValidCountryId(),
        ];
        $customer = [
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Test',
            'lastName' => 'Test',
            'customerNumber' => 'A',
            'password' => TestDefaults::HASHED_PASSWORD,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'group' => ['name' => 'test'],
        ];

        $this->customerRepository->upsert([
            array_merge(
                $customer,
                [
                    'id' => $id1,
                    'email' => 'test@test.com' . Uuid::randomHex(),
                    'defaultShippingAddressId' => $addressId1,
                    'defaultBillingAddressId' => $addressId1,
                    'addresses' => [
                        array_merge($address, ['id' => $addressId1, 'zipcode' => 'C']),
                        array_merge($address, ['id' => $addressId2, 'zipcode' => 'B']),
                        array_merge($address, ['id' => $addressId3, 'zipcode' => 'X']),
                    ],
                ]
            ),
            array_merge(
                $customer,
                [
                    'id' => $id2,
                    'email' => 'test@test.com' . Uuid::randomHex(),
                    'defaultShippingAddressId' => $addressId4,
                    'defaultBillingAddressId' => $addressId4,
                    'addresses' => [
                        array_merge($address, ['id' => $addressId4, 'zipcode' => 'X']),
                        array_merge($address, ['id' => $addressId5, 'zipcode' => 'B']),
                        array_merge($address, ['id' => $addressId6, 'zipcode' => 'A']),
                    ],
                ]
            ),
        ], $context);

        $bytes = [Uuid::fromHexToBytes($id1), Uuid::fromHexToBytes($id2)];

        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM customer WHERE id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(2, $mapping);

        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM customer_address WHERE customer_id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(6, $mapping);

        $criteria = new Criteria([$id1, $id2]);

        $criteria->getAssociation('addresses')
            ->addSorting(new FieldSorting('customer_address.zipcode', FieldSorting::ASCENDING));

        $customers = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities();

        $customer1 = $customers->get($id1);
        $customer2 = $customers->get($id2);

        static::assertInstanceOf(CustomerEntity::class, $customer1);
        static::assertInstanceOf(CustomerEntity::class, $customer2);

        static::assertInstanceOf(CustomerAddressCollection::class, $customer1->getAddresses());
        static::assertCount(3, $customer1->getAddresses());
        static::assertSame(
            [$addressId2, $addressId1, $addressId3],
            array_values($customer1->getAddresses()->getIds())
        );

        $customerAddressCollection = $customer2->getAddresses();
        static::assertNotNull($customerAddressCollection);
        static::assertCount(3, $customerAddressCollection);
        static::assertSame(
            [$addressId6, $addressId5, $addressId4],
            array_values($customerAddressCollection->getIds())
        );

        $criteria = new Criteria([$id1, $id2]);
        $criteria->getAssociation('addresses')
            ->addSorting(new FieldSorting('customer_address.zipcode', FieldSorting::DESCENDING));

        $customers = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities();

        $customer1 = $customers->get($id1);
        $customer2 = $customers->get($id2);

        static::assertInstanceOf(CustomerEntity::class, $customer1);
        static::assertInstanceOf(CustomerEntity::class, $customer2);

        $customer1Addresses = $customer1->getAddresses();
        static::assertNotNull($customer1Addresses);
        static::assertSame(
            [$addressId3, $addressId1, $addressId2],
            array_values($customer1Addresses->getIds())
        );

        $customer2Addresses = $customer2->getAddresses();
        static::assertNotNull($customer2Addresses);
        static::assertSame(
            [$addressId4, $addressId5, $addressId6],
            array_values($customer2Addresses->getIds())
        );
    }

    public function testLoadOneToManySupportsSortingAndPagination(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $defaultAddressId = Uuid::randomHex();

        $address = [
            'street' => 'A',
            'zipcode' => 'A',
            'city' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'A',
            'lastName' => 'a',
            'countryId' => $this->getValidCountryId(),
        ];

        $customer = [
            'id' => $id,
            'firstName' => 'Test',
            'lastName' => 'Test',
            'customerNumber' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'password' => TestDefaults::HASHED_PASSWORD,
            'email' => 'test@test.com' . Uuid::randomHex(),
            'defaultShippingAddressId' => $defaultAddressId,
            'defaultBillingAddressId' => $defaultAddressId,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'group' => ['name' => 'test'],
            'addresses' => [
                array_merge(['id' => $defaultAddressId], $address),
                array_merge($address, ['street' => 'B']),
                array_merge($address, ['street' => 'X']),
                array_merge($address, ['street' => 'E']),
                array_merge($address, ['street' => 'D']),
            ],
        ];

        $this->customerRepository->upsert([$customer], $context);

        $criteria = new Criteria([$id]);
        $criteria->getAssociation('addresses')->setLimit(3);
        $criteria->getAssociation('addresses')->addSorting(new FieldSorting('street'));

        $customer = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities()
            ->get($id);
        static::assertInstanceOf(CustomerEntity::class, $customer);
        static::assertNotNull($customer->getAddresses());
        static::assertCount(3, $customer->getAddresses());

        $streets = $customer->getAddresses()->map(fn (CustomerAddressEntity $e) => $e->getStreet());
        static::assertSame(['A', 'B', 'D'], array_values($streets));

        $criteria = new Criteria([$id]);
        $criteria->getAssociation('addresses')->setLimit(3);
        $criteria->getAssociation('addresses')->addSorting(new FieldSorting('street', FieldSorting::DESCENDING));

        $customer = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities()
            ->get($id);
        static::assertInstanceOf(CustomerEntity::class, $customer);
        static::assertNotNull($customer->getAddresses());
        static::assertCount(3, $customer->getAddresses());

        $streets = $customer->getAddresses()->map(fn (CustomerAddressEntity $e) => $e->getStreet());
        static::assertSame(['X', 'E', 'D'], array_values($streets));
    }

    public function testLoadOneToManySupportsPagination(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $defaultAddressId = Uuid::randomHex();

        $address = [
            'street' => 'A',
            'zipcode' => 'A',
            'city' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'A',
            'lastName' => 'a',
            'countryId' => $this->getValidCountryId(),
        ];

        $customer = [
            'id' => $id,
            'firstName' => 'Test',
            'lastName' => 'Test',
            'customerNumber' => 'A',
            'salutationId' => $this->getValidSalutationId(),
            'password' => TestDefaults::HASHED_PASSWORD,
            'email' => 'test@test.com' . Uuid::randomHex(),
            'defaultShippingAddressId' => $defaultAddressId,
            'defaultBillingAddressId' => $defaultAddressId,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'group' => ['name' => 'test'],
            'addresses' => [
                array_merge(['id' => $defaultAddressId], $address),
                $address,
                $address,
                $address,
                $address,
            ],
        ];

        $this->customerRepository->upsert([$customer], $context);

        $criteria = new Criteria([$id]);
        $criteria->getAssociation('addresses')->setLimit(1);

        $customer = $this->customerRepository->search($criteria, $context)
            ->getEntities()
            ->get($id);
        static::assertInstanceOf(CustomerEntity::class, $customer);
        static::assertNotNull($customer->getAddresses());
        static::assertCount(1, $customer->getAddresses());

        $criteria = new Criteria([$id]);
        $criteria->getAssociation('addresses')->setLimit(3);
        $customer = $this->customerRepository
            ->search($criteria, $context)
            ->getEntities()
            ->get($id);
        static::assertInstanceOf(CustomerEntity::class, $customer);
        static::assertNotNull($customer->getAddresses());
        static::assertCount(3, $customer->getAddresses());
    }

    public function testLoadManyToManyNotLoadedAutomatically(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $id3 = Uuid::randomHex();

        $product1 = [
            'id' => $id1,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => true,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product2 = [
            'id' => $id2,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product3 = [
            'id' => $id3,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $repository = $this->categoryRepository;
        $context = Context::createDefaultContext();

        $repository->upsert(
            [
                ['id' => $id1, 'stock' => 1, 'name' => 'test', 'products' => [$product1, $product3]],
                ['id' => $id2, 'stock' => 1, 'name' => 'test', 'products' => [$product3, $product2]],
            ],
            $context
        );

        $bytes = [Uuid::fromHexToBytes($id1), Uuid::fromHexToBytes($id2)];
        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM product_category WHERE category_id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(4, $mapping);

        // test many to many not loaded automatically
        $categories = $repository
            ->search(new Criteria([$id1, $id2]), $context)
            ->getEntities();

        $category1 = $categories->get($id1);
        $category2 = $categories->get($id2);

        static::assertInstanceOf(CategoryEntity::class, $category1);
        static::assertNull($category1->getProducts());

        static::assertInstanceOf(CategoryEntity::class, $category2);
        static::assertNull($category2->getProducts());
    }

    public function testLoadNestedAssociation(): void
    {
        $manufacturerId = Uuid::randomHex();
        $productId = Uuid::randomHex();
        $categoryId = Uuid::randomHex();

        $manufacturer = [
            'id' => $manufacturerId,
            'name' => 'Test manufacturer',
            'products' => [
                [
                    'id' => $productId,
                    'productNumber' => Uuid::randomHex(),
                    'stock' => 1,
                    'name' => 'test media',
                    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                    'active' => true,
                    'tax' => ['taxRate' => 13, 'name' => 'green'],
                    'categories' => [
                        [
                            'id' => $categoryId,
                            'name' => 'foobar',
                        ],
                    ],
                ],
            ],
        ];

        $manufacturerRepo = static::getContainer()->get('product_manufacturer.repository');
        $context = Context::createDefaultContext();
        $manufacturerRepo->upsert([$manufacturer], $context);

        $manufacturerCriteria = new Criteria([$manufacturerId]);

        $manufacturerCriteria->getAssociation('products')
            ->setIds([$productId])
            ->addAssociation('categories');

        /** @var ProductManufacturerEntity $manufacturer */
        $manufacturer = $manufacturerRepo->search($manufacturerCriteria, $context)->get($manufacturerId);
        $products = $manufacturer->getProducts();
        static::assertNotNull($products);

        static::assertCount(1, $products);
        static::assertInstanceOf(ProductEntity::class, $products->first());

        $categories = $products->first()->getCategories();
        static::assertNotNull($categories);
        static::assertCount(1, $categories);
        static::assertInstanceOf(CategoryEntity::class, $categories->first());
    }

    public function testLoadManyToMany(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $id3 = Uuid::randomHex();

        $product1 = [
            'id' => $id1,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => true,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product2 = [
            'id' => $id2,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product3 = [
            'id' => $id3,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $context = Context::createDefaultContext();

        $this->categoryRepository->upsert(
            [
                ['id' => $id1, 'name' => 'test', 'products' => [$product1, $product3]],
                ['id' => $id2, 'name' => 'test', 'products' => [$product3, $product2]],
            ],
            $context
        );

        $bytes = [Uuid::fromHexToBytes($id1), Uuid::fromHexToBytes($id2)];
        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM product_category WHERE category_id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(4, $mapping);

        // test that we can add the association and all products are fetched
        $criteria = new Criteria([$id1, $id2]);

        $criteria->addAssociation('products');
        $categories = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities();

        $category1 = $categories->get($id1);
        $category2 = $categories->get($id2);

        static::assertInstanceOf(CategoryEntity::class, $category1);
        static::assertInstanceOf(ProductCollection::class, $category1->getProducts());
        static::assertCount(2, $category1->getProducts());

        static::assertContains($id1, $category1->getProducts()->getIds());
        static::assertContains($id3, $category1->getProducts()->getIds());

        static::assertInstanceOf(CategoryEntity::class, $category2);
        static::assertInstanceOf(ProductCollection::class, $category2->getProducts());
        static::assertCount(2, $category2->getProducts());

        static::assertContains($id2, $category2->getProducts()->getIds());
        static::assertContains($id3, $category2->getProducts()->getIds());
    }

    public function testLoadManyToManySupportsFilter(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $id3 = Uuid::randomHex();

        $product1 = [
            'id' => $id1,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => true,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product2 = [
            'id' => $id2,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product3 = [
            'id' => $id3,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $context = Context::createDefaultContext();

        $this->categoryRepository->upsert(
            [
                ['id' => $id1, 'name' => 'test', 'products' => [$product1, $product3]],
                ['id' => $id2, 'name' => 'test', 'products' => [$product3, $product2]],
            ],
            $context
        );

        $bytes = [Uuid::fromHexToBytes($id1), Uuid::fromHexToBytes($id2)];
        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM product_category WHERE category_id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(4, $mapping);

        $criteria = new Criteria([$id1, $id2]);

        $criteria->getAssociation('products')
            ->addFilter(new EqualsFilter('product.active', true));

        $categories = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities();

        $category1 = $categories->get($id1);
        $category2 = $categories->get($id2);

        static::assertInstanceOf(CategoryEntity::class, $category1);
        static::assertInstanceOf(ProductCollection::class, $category1->getProducts());
        static::assertCount(1, $category1->getProducts());

        static::assertInstanceOf(CategoryEntity::class, $category2);
        static::assertInstanceOf(ProductCollection::class, $category2->getProducts());
        static::assertCount(0, $category2->getProducts());
    }

    public function testLoadManyToManySupportsSorting(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $id3 = Uuid::randomHex();

        $product1 = [
            'id' => $id1,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => true,
            'manufacturer' => ['name' => 'test'],
            'name' => 'A',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product2 = [
            'id' => $id2,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'B',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $product3 = [
            'id' => $id3,
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => false,
            'manufacturer' => ['name' => 'test'],
            'name' => 'C',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
        ];

        $context = Context::createDefaultContext();

        $this->categoryRepository->upsert(
            [
                ['id' => $id1, 'name' => 'test', 'products' => [$product1, $product3]],
                ['id' => $id2, 'name' => 'test', 'products' => [$product3, $product2]],
            ],
            $context
        );

        $bytes = [Uuid::fromHexToBytes($id1), Uuid::fromHexToBytes($id2)];
        $mapping = $this->connection->fetchAllAssociative('SELECT * FROM product_category WHERE category_id IN (:ids)', ['ids' => $bytes], ['ids' => ArrayParameterType::BINARY]);
        static::assertCount(4, $mapping);

        $criteria = new Criteria([$id1, $id2]);

        $criteria->getAssociation('products')
            ->addSorting(new FieldSorting('product.name', FieldSorting::ASCENDING));

        $categories = $this->categoryRepository->search($criteria, $context);

        $category1 = $categories->get($id1);
        $category2 = $categories->get($id2);

        static::assertInstanceOf(CategoryEntity::class, $category1);
        static::assertInstanceOf(ProductCollection::class, $category1->getProducts());
        static::assertCount(2, $category1->getProducts());

        static::assertSame(
            [$id1, $id3],
            array_values($category1->getProducts()->getIds())
        );

        static::assertInstanceOf(CategoryEntity::class, $category2);
        static::assertInstanceOf(ProductCollection::class, $category2->getProducts());
        static::assertCount(2, $category2->getProducts());

        static::assertSame(
            [$id2, $id3],
            array_values($category2->getProducts()->getIds())
        );

        $criteria = new Criteria([$id1, $id2]);

        $criteria->getAssociation('products')
            ->addSorting(new FieldSorting('product.name', FieldSorting::DESCENDING));
        $categories = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities();

        $category1 = $categories->get($id1);
        $category2 = $categories->get($id2);

        static::assertInstanceOf(CategoryEntity::class, $category1);
        $category1Products = $category1->getProducts();
        static::assertNotNull($category1Products);
        static::assertSame(
            [$id3, $id1],
            array_values($category1Products->getIds())
        );

        static::assertInstanceOf(CategoryEntity::class, $category2);
        $category2Products = $category2->getProducts();
        static::assertNotNull($category2Products);
        static::assertSame(
            [$id3, $id2],
            array_values($category2Products->getIds())
        );
    }

    public function testLoadManyToManySupportsPagination(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();

        $context = Context::createDefaultContext();

        $products = [
            [
                'id' => $id1,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'active' => true,
                'manufacturer' => ['name' => 'test'],
                'name' => 'test',
                'tax' => ['taxRate' => 13, 'name' => 'green'],
                'categories' => [
                    ['name' => 'test1'],
                    ['name' => 'test2'],
                    ['name' => 'test3'],
                    ['name' => 'test4'],
                    ['name' => 'test5'],
                    ['name' => 'test6'],
                    ['name' => 'test7'],
                    ['name' => 'test8'],
                    ['name' => 'test9'],
                ],
            ],
            [
                'id' => $id2,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'active' => false,
                'manufacturer' => ['name' => 'test'],
                'name' => 'test',
                'tax' => ['taxRate' => 13, 'name' => 'green'],
                'categories' => [
                    ['name' => 'test10'],
                    ['name' => 'test11'],
                    ['name' => 'test12'],
                    ['name' => 'test13'],
                    ['name' => 'test14'],
                    ['name' => 'test15'],
                    ['name' => 'test16'],
                    ['name' => 'test17'],
                    ['name' => 'test18'],
                ],
            ],
        ];

        $this->productRepository->upsert($products, $context);

        $criteria = new Criteria([$id1, $id2]);
        $criteria->getAssociation('categories')->setLimit(3);

        $products = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        static::assertCount(2, $products);

        $product1 = $products->get($id1);
        $product2 = $products->get($id2);

        static::assertInstanceOf(ProductEntity::class, $product1);
        static::assertInstanceOf(ProductEntity::class, $product2);
        static::assertNotNull($product1->getCategories());
        static::assertNotNull($product2->getCategories());

        static::assertCount(3, $product1->getCategories());
        static::assertCount(3, $product2->getCategories());
    }

    public function testReadSupportsConditions(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();

        $products = [
            [
                'id' => $id1,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'active' => true,
                'manufacturer' => ['name' => 'test'],
                'name' => 'test',
                'tax' => ['taxRate' => 13, 'name' => 'green'],
            ],
            [
                'id' => $id2,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'active' => false,
                'manufacturer' => ['name' => 'test'],
                'name' => 'test',
                'tax' => ['taxRate' => 13, 'name' => 'green'],
            ],
        ];

        $context = Context::createDefaultContext();

        $this->productRepository->upsert($products, $context);

        $criteria = new Criteria([$id1, $id2]);

        $products = $this->productRepository->search($criteria, $context);
        static::assertCount(2, $products);

        $criteria->addFilter(new EqualsFilter('product.active', true));
        $products = $this->productRepository->search($criteria, $context);
        static::assertCount(1, $products);
    }

    public function testReadRelationWithNestedToManyRelations(): void
    {
        $context = Context::createDefaultContext();

        $data = [
            'id' => Uuid::randomHex(),
            'productNumber' => Uuid::randomHex(),
            'stock' => 1,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'active' => true,
            'manufacturer' => ['name' => 'test'],
            'name' => 'test',
            'tax' => ['taxRate' => 13, 'name' => 'green'],
            'cover' => [
                'position' => 1,
                'media' => [
                    'name' => 'test-image',
                    'thumbnails' => [
                        ['id' => Uuid::randomHex(), 'width' => 10, 'height' => 10, 'highDpi' => true],
                        ['id' => Uuid::randomHex(), 'width' => 20, 'height' => 20, 'highDpi' => true],
                        ['id' => Uuid::randomHex(), 'width' => 30, 'height' => 30, 'highDpi' => true],
                    ],
                ],
            ],
        ];

        $this->productRepository->create([$data], $context);
        $criteria = new Criteria([$data['id']]);
        $criteria->addAssociation('cover.media');
        $results = $this->productRepository
            ->search($criteria, $context)
            ->getEntities();

        $product = $results->first();

        static::assertNotNull($product, 'Product has not been created.');
        static::assertNotNull($product->getCover(), 'Cover was not fetched.');
        static::assertNotNull($product->getCover()->getMedia(), 'Media for cover was not fetched.');
        $mediaThumbnailCollection = $product->getCover()->getMedia()->getThumbnails();
        static::assertNotNull($mediaThumbnailCollection);
        static::assertCount(3, $mediaThumbnailCollection->getElements(), 'Thumbnails were not fetched or is incomplete.');
    }

    public function testAddTranslationsAssociation(): void
    {
        $id = Uuid::randomHex();

        $cats = [
            [
                'id' => $id,
                'name' => 'system',
                'translations' => [
                    'de-DE' => [
                        'name' => 'deutsch',
                    ],
                ],
            ],
        ];

        $this->categoryRepository->create($cats, Context::createDefaultContext());

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $cat = $this->categoryRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities()
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $cat);
        $catTranslations = $cat->getTranslations();
        static::assertNotNull($catTranslations);
        static::assertCount(2, $catTranslations);

        $transDe = $catTranslations->filterByLanguageId($this->deLanguageId)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $transDe);
        static::assertSame('deutsch', $transDe->getName());

        $transSystem = $catTranslations->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $transSystem);
        static::assertSame('system', $transSystem->getName());
    }

    public function testPricesAreConvertedWithCurrencyFactor(): void
    {
        $productId = Uuid::randomHex();

        $product = [
            [
                'id' => $productId,
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 7, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'name' => 'test',
            ],
            [
                'id' => Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 8, 'net' => 6, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 10],
                'name' => 'test',
            ],
        ];

        $context = Context::createDefaultContext();

        $this->productRepository->create($product, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('product.price', 8.0));

        $searchContext = new Context(
            new SystemSource(),
            $context->getRuleIds(),
            Uuid::randomHex(),
            $context->getLanguageIdChain(),
            $context->getVersionId(),
            0.8
        );

        $products = $this->productRepository->search($criteria, $searchContext);
        static::assertSame(1, $products->getTotal());

        $product = $products->getEntities()->get($productId);
        static::assertInstanceOf(ProductEntity::class, $product);
    }

    public function testParentCanNotBeFetchedException(): void
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addAssociation('parent');

        $data = [
            'id' => Uuid::randomHex(),
            'name' => 'test',
            'productNumber' => Uuid::randomHex(),
            'stock' => 10,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false],
            ],
            'tax' => ['name' => 'test', 'taxRate' => 15],
        ];

        static::getContainer()->get('product.repository')
            ->create([$data], Context::createDefaultContext());

        $exception = null;

        try {
            static::getContainer()->get('product.repository')
                ->search($criteria, Context::createDefaultContext());
        } catch (ParentAssociationCanNotBeFetched $e) {
            $exception = $e;
        }

        static::assertInstanceOf(ParentAssociationCanNotBeFetched::class, $exception);
    }

    public function testLoadToOneWithToMany(): void
    {
        $ids = new IdsCollection();

        $products = [
            (new ProductBuilder($ids, 'product-1'))
                ->price(100)
                ->category('test-1')
                ->build(),
            (new ProductBuilder($ids, 'product-2'))
                ->price(100)
                ->category('test-2')
                ->build(),
        ];

        $this->productRepository->create($products, Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('product-1'), $ids->get('product-2')]);
        $criteria->addAssociation('translations.language.categoryTranslations');

        $products = $this->productRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities();

        foreach ($products as $product) {
            static::assertInstanceOf(ProductEntity::class, $product);
            static::assertInstanceOf(EntityCollection::class, $product->getTranslations());
            static::assertTrue($product->getTranslations()->count() > 0);

            $first = $product->getTranslations()->first();

            static::assertInstanceOf(ProductTranslationEntity::class, $first);

            static::assertInstanceOf(LanguageEntity::class, $first->getLanguage());

            static::assertInstanceOf(CategoryTranslationCollection::class, $first->getLanguage()->getCategoryTranslations());

            $translations = $first->getLanguage()->getCategoryTranslations();

            static::assertTrue($translations->count() >= 2);
        }
    }

    public function testSearchWithNonIdPK(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();
        $id3 = Uuid::randomHex();

        $data = [
            [
                'testField' => $id1,
                'name' => 'test1',
            ],
            [
                'testField' => $id2,
                'nonPk' => null,
                'name' => 'test2',
            ],
            [
                'testField' => $id3,
                'nonPk' => $id3,
                'name' => 'test3',
            ],
        ];

        /** @var EntityRepository $repository */
        $repository = static::getContainer()->get('non_id_primary_key_test.repository');

        $repository->create($data, Context::createDefaultContext());

        $result = $repository->search(new Criteria(), Context::createDefaultContext());

        static::assertSame(3, $result->getTotal());
        static::assertCount(3, $result);

        $foundIds = [];
        foreach ($result as $entity) {
            $foundIds[] = $entity->getUniqueIdentifier();
            if ($entity->getUniqueIdentifier() === $id3) {
                static::assertSame($id3, $entity->get('nonPk'));
            } else {
                static::assertNull($entity->get('nonPk'));
            }
        }

        static::assertEqualsCanonicalizing([$id1, $id2, $id3], $foundIds);
    }

    public function testReadWithNonIdPKOverPropertyName(): void
    {
        $id1 = Uuid::randomHex();
        $id2 = Uuid::randomHex();

        $data = [
            [
                'testField' => $id1,
                'name' => 'test1',
            ],
            [
                'testField' => $id2,
                'name' => 'test2',
            ],
        ];

        /** @var EntityRepository $repository */
        $repository = static::getContainer()->get('non_id_primary_key_test.repository');

        $repository->create($data, Context::createDefaultContext());

        $result = $repository->search(new Criteria([['testField' => $id1]]), Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertCount(1, $result);
    }

    public function testDirectlyReadFromTranslationEntity(): void
    {
        $id = Uuid::randomHex();

        $cats = [
            [
                'id' => $id,
                'name' => 'system',
                'translations' => [
                    'de-DE' => [
                        'name' => 'deutsch',
                    ],
                ],
            ],
        ];

        $this->categoryRepository->create($cats, Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'system'));

        $result = static::getContainer()->get('category_translation.repository')->search($criteria, Context::createDefaultContext());

        static::assertSame(1, $result->getTotal());
        static::assertCount(1, $result);

        $translation = $result->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $translation);
        static::assertSame('system', $translation->getName());
        static::assertSame(Defaults::LANGUAGE_SYSTEM, $translation->getLanguageId());
        static::assertSame($id, $translation->getCategoryId());
    }

    /**
     * @param list<array<string, mixed>> $data
     * @param callable(Criteria): void $modifier
     * @param list<string> $expected
     */
    #[DataProvider('casesToManyPaginated')]
    public function testLoadToManyPaginated(array $data, callable $modifier, array $expected): void
    {
        $id = Uuid::randomHex();
        $this->categoryRepository->upsert([
            [
                'id' => $id,
                'name' => 'test',
                'seoUrls' => $data,
            ],
        ], Context::createDefaultContext());

        $criteria = new Criteria([$id]);
        $modifier($criteria);

        $result = $this->categoryRepository
            ->search($criteria, Context::createDefaultContext())
            ->getEntities()
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $result);
        $seoUrlCollection = $result->getSeoUrls();
        static::assertNotNull($seoUrlCollection);
        $urls = $seoUrlCollection->map(fn (SeoUrlEntity $e) => $e->getSeoPathInfo());

        static::assertSame($expected, array_values($urls));
    }

    public function testLoadOneToManyPaginatedWithNoParent(): void
    {
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance(true);

        $this->productRepository->create([
            [
                'id' => $id = Uuid::randomHex(),
                'productNumber' => Uuid::randomHex(),
                'stock' => 1,
                'name' => 'test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
                'manufacturer' => ['name' => 'test'],
                'tax' => ['name' => 'test', 'taxRate' => 15],
            ],
        ], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('media');
        $criteria->getAssociation('media')->setLimit(1);

        $product = $this->productRepository
                ->search($criteria, $context)
                ->getEntities()
                ->first();

        static::assertNotNull($product);
        $productMediaCollection = $product->getMedia();
        static::assertNotNull($productMediaCollection);
        static::assertCount(0, $productMediaCollection);
    }

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>, 1: callable(Criteria): void, 2: list<string>}>
     */
    public static function casesToManyPaginated(): iterable
    {
        yield 'Multi sort' => [
            [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test2',
                    'pathInfo' => 'test2',
                    'seoPathInfo' => 'active',
                    'isCanonical' => true,
                ],
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test',
                    'pathInfo' => 'test',
                    'seoPathInfo' => 'not-active',
                    'isCanonical' => false,
                ],
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test',
                    'pathInfo' => 'test',
                    'seoPathInfo' => 'not-active2',
                    'isCanonical' => false,
                    'isDeleted' => true,
                ],
            ],
            function (Criteria $criteria): void {
                $criteria->getAssociation('seoUrls')->addSorting(
                    new FieldSorting('isCanonical', FieldSorting::DESCENDING),
                    new FieldSorting('isDeleted', FieldSorting::ASCENDING),
                )->setLimit(20);
            },
            [
                'active',
                'not-active',
                'not-active2',
            ],
        ];

        yield 'Sorting join new table' => [
            [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test2',
                    'pathInfo' => 'test2',
                    'seoPathInfo' => 'active',
                    'isCanonical' => true,
                ],
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test',
                    'pathInfo' => 'test',
                    'seoPathInfo' => 'not-active',
                    'isCanonical' => false,
                ],
            ],
            function (Criteria $criteria): void {
                $criteria->getAssociation('seoUrls')->addSorting(
                    new FieldSorting('salesChannel.id', FieldSorting::DESCENDING),
                    new FieldSorting('isCanonical', FieldSorting::DESCENDING)
                )->setLimit(20);
            },
            [
                'active',
                'not-active',
            ],
        ];

        yield 'Sort and boost using query' => [
            [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test2',
                    'pathInfo' => 'test2',
                    'seoPathInfo' => 'active',
                    'isCanonical' => true,
                ],
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test-query',
                    'pathInfo' => 'test-query',
                    'seoPathInfo' => 'active-query',
                    'isCanonical' => false,
                ],
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'routeName' => 'test',
                    'pathInfo' => 'test',
                    'seoPathInfo' => 'not-active',
                    'isCanonical' => false,
                ],
            ],
            function (Criteria $criteria): void {
                $filter = new OrFilter([
                    new EqualsFilter('isCanonical', true),
                ]);
                $filter->addQuery(new EqualsFilter('seoPathInfo', 'active-query'));

                $criteria->getAssociation('seoUrls')->setLimit(20);
                $criteria->getAssociation('seoUrls')->addFilter($filter);
                $criteria->getAssociation('seoUrls')->addSorting(new FieldSorting('isCanonical', FieldSorting::ASCENDING));
            },
            [
                'active-query',
                'active',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $criteriaConfig
     * @param list<string> $expectedMedia
     */
    #[DataProvider('casesToManyReadPaginatedInherited')]
    public function testOneToManyReadingInherited(array $criteriaConfig, array $expectedMedia, string $type): void
    {
        $ids = new IdsCollection();

        $variant = new ProductBuilder($ids, 'p1.1');

        $product = (new ProductBuilder($ids, 'p1'))
            ->name('Test Product')
            ->price(50, 50);

        if (str_contains($type, 'main')) {
            $product
                ->media('m-1', 1)
                ->media('m-2', 2)
                ->media('m-3', 3);
        }

        if (str_contains($type, 'variant')) {
            $variant
                ->media('v-1', 1)
                ->media('v-2', 2)
                ->media('v-3', 3);
        }

        $product->variant($variant->build());

        $context = Context::createDefaultContext();

        $productRepository = static::getContainer()->get('product.repository');
        $productRepository->create([
            $product->build(),
        ], $context);

        $criteria = new Criteria([$ids->get('p1.1')]);
        $media = $criteria->getAssociation('media');
        $media->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));
        $media->assign($criteriaConfig);
        $media->addAssociation('media');

        $context->setConsiderInheritance(true);

        $product = $productRepository->search($criteria, $context)->first();
        static::assertInstanceOf(ProductEntity::class, $product);
        $media = $product->getMedia();
        static::assertNotNull($media);

        static::assertSame($expectedMedia, array_values($media->map(static function (ProductMediaEntity $m) {
            $mediaEntity = $m->getMedia();
            static::assertNotNull($mediaEntity);

            return $mediaEntity->getFileName();
        })));
    }

    public function testManyToManyJoinsIntoSameTableFiltered(): void
    {
        $field = (new ManyToManyAssociationField(
            'consistsOf',
            ProductDefinition::class,
            ConsistsOfManyToManyDefinition::class,
            'product_id',
            'product_id_to'
        ))
            ->addFlags(new ApiAware(), new Extension());

        $field->compile(static::getContainer()->get(DefinitionInstanceRegistry::class));

        static::getContainer()->get(ProductDefinition::class)->getFields()->add($field);

        $ids = new IdsCollection();

        $product = (new ProductBuilder($ids, 'p1'))
            ->name('Test Product')
            ->price(50, 50);

        $product2 = (new ProductBuilder($ids, 'p2'))
            ->name('Test Product2')
            ->price(50, 50);

        $product3 = (new ProductBuilder($ids, 'p3'))
            ->name('Test Product3')
            ->active(false)
            ->price(50, 50);

        $productRepository = static::getContainer()->get('product.repository');
        $productRepository->create([
            $product->build(),
            $product2->build(),
            $product3->build(),
        ], Context::createDefaultContext());

        $repository = static::getContainer()->get('acme_consists_of_mapping.repository');
        static::assertInstanceOf(EntityRepository::class, $repository);

        $repository
            ->create([
                [
                    'productId' => $ids->get('p1'),
                    'productIdTo' => $ids->get('p2'),
                ],
                [
                    'productId' => $ids->get('p1'),
                    'productIdTo' => $ids->get('p3'),
                ],
            ], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('p1')]);
        $criteria->addAssociation('consistsOf');

        $criteria->getAssociation('consistsOf')->addFilter(new EqualsFilter('active', true));

        $result = static::getContainer()->get('product.repository')
            ->search($criteria, Context::createDefaultContext());

        static::assertCount(1, $result->getEntities());

        $product = $result->getEntities()->first();

        static::assertInstanceOf(ProductEntity::class, $product);

        $consistsOf = $product->getExtension('consistsOf');

        static::assertInstanceOf(ProductCollection::class, $consistsOf);

        static::assertCount(1, $consistsOf);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>, 2: string}>
     */
    public static function casesToManyReadPaginatedInherited(): iterable
    {
        yield 'parent-data: with limit at 2 with 3 elements' => [
            ['limit' => 2], // Criteria
            ['m-1', 'm-2'], // expected media
            'main',
        ];

        yield 'parent-data: with limit at 2 and page 2 with 3 elements' => [
            ['limit' => 2, 'offset' => 2],
            ['m-3'],
            'main',
        ];

        yield 'child-data: with limit at 2 with 3 elements' => [
            ['limit' => 2],
            ['v-1', 'v-2'],
            'variant',
        ];

        yield 'child-data: with limit at 2 and page 2 with 3 elements' => [
            ['limit' => 2, 'offset' => 2],
            ['v-3'],
            'variant',
        ];

        yield 'child-and-main: with limit at 2 with 3 elements' => [
            ['limit' => 2],
            ['v-1', 'v-2'],
            'variant-main',
        ];

        yield 'child-and-main: with limit at 2 and page 2 with 3 elements' => [
            ['limit' => 2, 'offset' => 2],
            ['v-3'],
            'variant-main',
        ];
    }
}
