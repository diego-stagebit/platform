<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Framework\Seo\SeoUrl;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\Seo\StorefrontSalesChannelTestHelper;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\QueueTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Framework\Seo\SeoUrlRoute\ProductPageSeoUrlRoute;

/**
 * @internal
 */
#[Package('inventory')]
#[Group('slow')]
class SeoUrlTest extends TestCase
{
    use IntegrationTestBehaviour;
    use QueueTestBehaviour;
    use SalesChannelApiTestBehaviour;
    use StorefrontSalesChannelTestHelper;

    /**
     * @var EntityRepository<ProductCollection>
     */
    private EntityRepository $productRepository;

    /**
     * @var EntityRepository<LandingPageCollection>
     */
    private EntityRepository $landingPageRepository;

    protected function setUp(): void
    {
        $this->productRepository = static::getContainer()->get('product.repository');
        $this->landingPageRepository = static::getContainer()->get('landing_page.repository');
    }

    public function testSearchLandingPage(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext($salesChannelId, 'test');

        $id = $this->createTestLandingPage(['salesChannels' => [
            [
                'id' => $salesChannelContext->getSalesChannelId(),
            ],
        ]]);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('seoUrls');

        $landingPage = $this->landingPageRepository->search($criteria, $salesChannelContext->getContext())->getEntities()->first();
        static::assertNotNull($landingPage);

        $seoUrls = $landingPage->getSeoUrls();
        static::assertNotNull($seoUrls);

        $seoUrl = $seoUrls->first();
        static::assertInstanceOf(SeoUrlEntity::class, $seoUrl);
        static::assertSame('coolUrl', $seoUrl->getSeoPathInfo());
    }

    public function testLandingPageUpdate(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext($salesChannelId, 'test');

        $id = $this->createTestLandingPage(['salesChannels' => [
            [
                'id' => $salesChannelContext->getSalesChannelId(),
            ],
        ]]);

        $this->landingPageRepository->update(
            [
                [
                    'id' => $id,
                    'url' => 'newUrl',
                ],
            ],
            $salesChannelContext->getContext()
        );

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('seoUrls');

        $first = $this->landingPageRepository->search($criteria, Context::createDefaultContext())->getEntities()->first();
        static::assertNotNull($first);

        $urls = $first->getSeoUrls();
        static::assertNotNull($urls);

        // Old seo url
        $seoUrl = $urls->filterByProperty('seoPathInfo', 'coolUrl')->first();
        static::assertNotNull($seoUrl);

        static::assertNull($seoUrl->getIsCanonical());
        static::assertFalse($seoUrl->getIsDeleted());

        static::assertSame('/landingPage/' . $id, $seoUrl->getPathInfo());
        static::assertSame($id, $seoUrl->getForeignKey());

        /** @var SeoUrlCollection $urls */
        $urls = $first->getSeoUrls();

        // New seo url
        $seoUrl = $urls->filterByProperty('seoPathInfo', 'newUrl')->first();
        static::assertNotNull($seoUrl);

        static::assertTrue($seoUrl->getIsCanonical());
        static::assertFalse($seoUrl->getIsDeleted());

        static::assertSame('/landingPage/' . $id, $seoUrl->getPathInfo());
        static::assertSame($id, $seoUrl->getForeignKey());
    }

    public function testSearchProduct(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext($salesChannelId, 'test');

        $id = $this->createTestProduct(salesChannelId: $salesChannelId);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('seoUrls');

        /** @var ProductEntity $product */
        $product = $this->productRepository->search($criteria, $salesChannelContext->getContext())->first();

        static::assertInstanceOf(SeoUrlCollection::class, $product->getSeoUrls());

        /** @var SeoUrlCollection $seoUrls */
        $seoUrls = $product->getSeoUrls();
        $seoUrl = $seoUrls->first();
        static::assertInstanceOf(SeoUrlEntity::class, $seoUrl);
        static::assertSame('foo-bar/P1234', $seoUrl->getSeoPathInfo());
    }

    public function testSearchProductForHeadlessSalesChannelHasCorrectUrl(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createSalesChannelContext(
            [
                'id' => $salesChannelId,
                'name' => 'test',
                'typeId' => Defaults::SALES_CHANNEL_TYPE_API,
            ]
        );

        $id = $this->createTestProduct(salesChannelId: $salesChannelId);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('seoUrls');

        /** @var ProductEntity $product */
        $product = $this->productRepository->search($criteria, $salesChannelContext->getContext())->first();

        static::assertInstanceOf(SeoUrlCollection::class, $product->getSeoUrls());

        /** @var SeoUrlCollection $seoUrls */
        $seoUrls = $product->getSeoUrls();
        static::assertCount(0, $seoUrls);
    }

    public function testSearchCategory(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext($salesChannelId, 'test');

        $categoryRepository = static::getContainer()->get('category.repository');

        $rootId = Uuid::randomHex();
        $childAId = Uuid::randomHex();
        $childA1Id = Uuid::randomHex();

        $categoryRepository->create([[
            'id' => $rootId,
            'name' => 'root',
            'children' => [
                [
                    'id' => $childAId,
                    'name' => 'a',
                    'children' => [
                        [
                            'id' => $childA1Id,
                            'name' => '1',
                        ],
                    ],
                ],
            ],
        ]], Context::createDefaultContext());
        $this->runWorker();

        $context = $salesChannelContext->getContext();

        $cases = [
            ['expected' => null, 'categoryId' => $childAId],
            ['expected' => null, 'categoryId' => $childA1Id],
        ];

        $this->runChecks($cases, $categoryRepository, $context, $salesChannelId);
    }

    public function testSearchCategoryWithLink(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext($salesChannelId, 'test');

        $categoryRepository = static::getContainer()->get('category.repository');

        $categoryPageId = Uuid::randomHex();
        $categoryPage = [
            [
                'id' => $categoryPageId,
                'name' => 'page',
                'type' => 'page',
            ],
        ];

        $categoryLinkId = Uuid::randomHex();
        $categoryLink = [
            [
                'id' => $categoryLinkId,
                'name' => 'link',
                'type' => 'link',
            ],
        ];

        $categories = [...$categoryLink, ...$categoryPage];
        $categoryRepository->create($categories, Context::createDefaultContext());
        $this->runWorker();

        $context = $salesChannelContext->getContext();

        $cases = [
            ['expected' => null, 'categoryId' => $categoryPageId],
            ['expected' => null, 'categoryId' => $categoryLinkId],
        ];

        $this->runChecks($cases, $categoryRepository, $context, $salesChannelId);
    }

    public function testSearchCategoryWithSalesChannelEntryPoint(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext(
            $salesChannelId,
            'test'
        );

        $categoryRepository = static::getContainer()->get('category.repository');

        $rootId = Uuid::randomHex();
        $childAId = Uuid::randomHex();
        $childA1Id = Uuid::randomHex();
        $childA1ZId = Uuid::randomHex();

        $categoryRepository->create([[
            'id' => $rootId,
            'name' => 'root',
            'children' => [
                [
                    'id' => $childAId,
                    'name' => 'a',
                    'children' => [
                        [
                            'id' => $childA1Id,
                            'name' => '1',
                            'children' => [
                                [
                                    'id' => $childA1ZId,
                                    'name' => 'z',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]], Context::createDefaultContext());

        $this->updateSalesChannelNavigationEntryPoint($salesChannelId, $childAId);
        $this->runWorker();

        $context = $salesChannelContext->getContext();

        $cases = [
            ['expected' => '1/', 'categoryId' => $childA1Id],
            ['expected' => '1/z/', 'categoryId' => $childA1ZId],
        ];

        $this->runChecks($cases, $categoryRepository, $context, $salesChannelId);
    }

    public function testSearchCategoryWithComplexHierarchy(): void
    {
        $salesChannelId = Uuid::randomHex();
        $salesChannelContext = $this->createStorefrontSalesChannelContext(
            $salesChannelId,
            'test'
        );

        $categoryRepository = static::getContainer()->get('category.repository');

        $rootId = Uuid::randomHex();
        $childAId = Uuid::randomHex();
        $childA1Id = Uuid::randomHex();
        $childA1ZId = Uuid::randomHex();
        $childBId = Uuid::randomHex();
        $childB1Id = Uuid::randomHex();
        $childB1ZId = Uuid::randomHex();

        $categoryRepository->create([[
            'id' => $rootId,
            'name' => 'root',
            'children' => [
                [
                    'id' => $childAId,
                    'name' => 'a',
                    'children' => [
                        [
                            'id' => $childA1Id,
                            'name' => '1',
                            'children' => [
                                [
                                    'id' => $childA1ZId,
                                    'name' => 'z',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => $childBId,
                    'name' => 'b',
                    'children' => [
                        [
                            'id' => $childB1Id,
                            'name' => '2',
                            'children' => [
                                [
                                    'id' => $childB1ZId,
                                    'name' => 'y',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]], Context::createDefaultContext());

        $context = $salesChannelContext->getContext();

        // We are updating the sales channel entry point without running a worker task. We expect the root category url
        // to change, while all other urls will be recreated in an asynch worker task.
        $this->updateSalesChannelNavigationEntryPoint($salesChannelId, $rootId);
        $this->runChecks([], $categoryRepository, $context, $salesChannelId);

        $this->runWorker();
        $casesRoot = [
            ['expected' => null, 'categoryId' => $rootId],
            ['expected' => 'b/', 'categoryId' => $childBId],
            ['expected' => 'b/2/y/', 'categoryId' => $childB1ZId],
            ['expected' => 'a/', 'categoryId' => $childAId],
            ['expected' => 'a/1/z/', 'categoryId' => $childA1ZId],
        ];
        $this->runChecks($casesRoot, $categoryRepository, $context, $salesChannelId);

        $this->updateSalesChannelNavigationEntryPoint($salesChannelId, $childAId);
        $this->runWorker();
        $casesA = [
            ['expected' => null, 'categoryId' => $rootId],
            ['expected' => '1/', 'categoryId' => $childA1Id],
            ['expected' => '1/z/', 'categoryId' => $childA1ZId],
        ];
        $this->runChecks($casesA, $categoryRepository, $context, $salesChannelId);

        $this->updateSalesChannelNavigationEntryPoint($salesChannelId, $rootId);
        $this->runWorker();
        $this->runChecks($casesRoot, $categoryRepository, $context, $salesChannelId);
    }

    public function testSearchWithLimit(): void
    {
        /** @var EntityRepository<ProductCollection> $productRepo */
        $productRepo = static::getContainer()->get('product.repository');

        $productRepo->create([[
            'id' => Uuid::randomHex(),
            'name' => 'foo bar',
            'manufacturer' => [
                'id' => Uuid::randomHex(),
                'name' => 'amazing brand',
            ],
            'productNumber' => Uuid::randomHex(),
            'tax' => ['id' => Uuid::randomHex(), 'taxRate' => 19, 'name' => 'tax'],
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 12, 'linked' => false],
            ],
            'stock' => 0,
        ]], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->setLimit(10);
        $criteria->getAssociation('seoUrls')->setLimit(10);

        /** @var ProductEntity $product */
        $product = $productRepo->search($criteria, Context::createDefaultContext())->first();

        static::assertInstanceOf(SeoUrlCollection::class, $product->getSeoUrls());
    }

    public function testSearchWithFilter(): void
    {
        /** @var EntityRepository<ProductCollection> $productRepo */
        $productRepo = static::getContainer()->get('product.repository');

        $productRepo->create([[
            'id' => Uuid::randomHex(),
            'name' => 'foo bar',
            'manufacturer' => [
                'id' => Uuid::randomHex(),
                'name' => 'amazing brand',
            ],
            'productNumber' => Uuid::randomHex(),
            'tax' => ['id' => Uuid::randomHex(), 'taxRate' => 19, 'name' => 'tax'],
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 12, 'linked' => false],
            ],
            'stock' => 0,
            'seoUrls' => [
                [
                    'id' => Uuid::randomHex(),
                    'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
                    'pathInfo' => 'foo',
                    'seoPathInfo' => 'asdf',
                ],
            ],
        ]], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->setLimit(10);
        $criteria->addFilter(new EqualsFilter('product.seoUrls.isCanonical', null));

        $criteria->getAssociation('seoUrls')
            ->setLimit(10)
            ->addFilter(new EqualsFilter('isCanonical', null));

        $products = $productRepo->search($criteria, Context::createDefaultContext());
        static::assertNotEmpty($products);

        /** @var ProductEntity $product */
        $product = $products->first();
        static::assertInstanceOf(SeoUrlCollection::class, $product->getSeoUrls());
    }

    public function testInsert(): void
    {
        $seoUrlId1 = Uuid::randomHex();
        $seoUrlId2 = Uuid::randomHex();

        $id = Uuid::randomHex();
        $this->upsertProduct([
            'id' => $id,
            'name' => 'awesome product',
            'seoUrls' => [
                [
                    'id' => $seoUrlId1,
                    'salesChannelId' => TestDefaults::SALES_CHANNEL,
                    'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
                    'pathInfo' => '/detail/' . $id,
                    'seoPathInfo' => 'awesome v2',
                    'isCanonical' => true,
                    'isModified' => true,
                ],
                [
                    'id' => $seoUrlId2,
                    'salesChannelId' => TestDefaults::SALES_CHANNEL,
                    'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
                    'pathInfo' => '/detail/' . $id,
                    'seoPathInfo' => 'awesome',
                    'isCanonical' => null,
                    'isModified' => true,
                ],
            ],
            'visibilities' => [
                [
                    'salesChannelId' => TestDefaults::SALES_CHANNEL,
                    'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                ],
            ],
        ]);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('seoUrls');

        $first = $this->productRepository->search($criteria, Context::createDefaultContext())->getEntities()->first();
        static::assertNotNull($first);

        $seoUrls = $first->getSeoUrls();
        static::assertNotNull($seoUrls);

        $seoUrl = $seoUrls->filterByProperty('id', $seoUrlId1)->first();
        static::assertNotNull($seoUrl);

        static::assertTrue($seoUrl->getIsCanonical());
        static::assertFalse($seoUrl->getIsDeleted());

        static::assertSame('awesome v2', $seoUrl->getSeoPathInfo());
    }

    public function testUpdate(): void
    {
        $seoUrlId = Uuid::randomHex();
        $id = Uuid::randomHex();
        $this->upsertProduct(['id' => $id, 'name' => 'awesome product']);

        $router = static::getContainer()->get('router');
        $pathInfo = $router->generate(ProductPageSeoUrlRoute::ROUTE_NAME, ['productId' => $id]);

        $this->upsertProduct([
            'id' => $id,
            'seoUrls' => [
                [
                    'id' => $seoUrlId,
                    'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
                    'pathInfo' => $pathInfo,
                    'seoPathInfo' => 'awesome',
                    'isCanonical' => true,
                ],
            ],
        ]);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('seoUrls');

        $first = $this->productRepository->search($criteria, Context::createDefaultContext())->getEntities()->first();
        static::assertNotNull($first);

        $urls = $first->getSeoUrls();
        static::assertNotNull($urls);

        $seoUrl = $urls->filterByProperty('id', $seoUrlId)->first();
        static::assertNotNull($seoUrl);

        static::assertTrue($seoUrl->getIsCanonical());
        static::assertFalse($seoUrl->getIsDeleted());

        static::assertSame('/detail/' . $id, $seoUrl->getPathInfo());
        static::assertSame($id, $seoUrl->getForeignKey());
    }

    /**
     * @param array<array{expected: string|null, categoryId: string}> $cases
     * @param EntityRepository<CategoryCollection> $categoryRepository
     */
    private function runChecks(array $cases, EntityRepository $categoryRepository, Context $context, string $salesChannelId): void
    {
        foreach ($cases as $case) {
            $criteria = new Criteria([$case['categoryId']]);
            $criteria->addAssociation('seoUrls');

            /** @var CategoryEntity $category */
            $category = $categoryRepository->search($criteria, $context)->first();
            static::assertSame($case['categoryId'], $category->getId());

            /** @var SeoUrlCollection $seoUrls */
            $seoUrls = $category->getSeoUrls();
            static::assertInstanceOf(SeoUrlCollection::class, $seoUrls);

            if ($category->getType() === CategoryDefinition::TYPE_LINK) {
                /** @var SeoUrlCollection $urls */
                $urls = $category->getSeoUrls();
                static::assertCount(0, $urls);

                continue;
            }

            $seoUrls = $seoUrls->filterByProperty('salesChannelId', $salesChannelId);
            $expectedCount = $case['expected'] === null ? 0 : 1;
            static::assertCount($expectedCount, $seoUrls->filterByProperty('isCanonical', true));

            if ($expectedCount === 0) {
                continue;
            }

            /** @var SeoUrlEntity $canonicalUrl */
            $canonicalUrl = $seoUrls
                ->filterByProperty('isCanonical', true)
                ->filterByProperty('salesChannelId', $salesChannelId)
                ->first();
            static::assertInstanceOf(SeoUrlEntity::class, $canonicalUrl);
            static::assertSame($case['expected'], $canonicalUrl->getSeoPathInfo());
        }
    }

    /**
     * @param array<string, array<int, array<string, bool|int|string|null>>|string> $data
     */
    private function upsertProduct(array $data): EntityWrittenContainerEvent
    {
        $defaults = [
            'productNumber' => Uuid::randomHex(),
            'manufacturer' => [
                'id' => Uuid::randomHex(),
                'name' => 'amazing brand',
            ],
            'tax' => ['id' => Uuid::randomHex(), 'taxRate' => 19, 'name' => 'tax'],
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 12, 'linked' => false]],
            'stock' => 0,
        ];
        $data = array_merge($defaults, $data);

        return $this->productRepository->upsert([$data], Context::createDefaultContext());
    }

    /**
     * @param array<string, string|array<string, mixed>> $overrides
     */
    private function createTestProduct(array $overrides = [], string $salesChannelId = TestDefaults::SALES_CHANNEL): string
    {
        $id = Uuid::randomHex();
        $insert = [
            'id' => $id,
            'name' => 'foo bar',
            'manufacturer' => [
                'id' => Uuid::randomHex(),
                'name' => 'amazing brand',
            ],
            'productNumber' => 'P1234',
            'tax' => ['id' => Uuid::randomHex(), 'taxRate' => 19, 'name' => 'tax'],
            'price' => [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => 10,
                    'net' => 12,
                    'linked' => false,
                ],
            ],
            'stock' => 0,
            'visibilities' => [
                [
                    'salesChannelId' => $salesChannelId,
                    'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                ],
            ],
        ];

        $insert = array_merge($insert, $overrides);

        $this->productRepository->create([$insert], Context::createDefaultContext());

        return $id;
    }

    /**
     * @param array<string, array<int, array<string, string>>> $overrides
     */
    private function createTestLandingPage(array $overrides = []): string
    {
        $id = Uuid::randomHex();
        $insert = [
            'id' => $id,
            'name' => 'foo bar',
            'url' => 'coolUrl',
        ];

        $insert = array_merge($insert, $overrides);

        $this->landingPageRepository->create([$insert], Context::createDefaultContext());

        return $id;
    }
}
