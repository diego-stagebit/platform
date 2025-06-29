<?php

declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Adapter\Twig\Extension;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Category\Service\CategoryBreadcrumbBuilder;
use Shopware\Core\Framework\Adapter\Twig\Extension\BuildBreadcrumbExtension;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticSalesChannelRepository;

/**
 * @internal
 */
#[CoversClass(BuildBreadcrumbExtension::class)]
class BuildBreadcrumbExtensionTest extends TestCase
{
    public function testGetFunctions(): void
    {
        $functions = $this->getBuildBreadcrumbExtension()->getFunctions();

        static::assertSame('sw_breadcrumb_full', $functions[0]->getName());
        static::assertSame('sw_breadcrumb_full_by_id', $functions[1]->getName());
    }

    public function testGetFullBreadcrumbNoSeoBreadCrumb(): void
    {
        $salesChannelContext = Generator::generateSalesChannelContext();

        $breadCrumb = $this->getBuildBreadcrumbExtension()
            ->getFullBreadcrumb([], new CategoryEntity(), $salesChannelContext);

        static::assertSame([], $breadCrumb);
    }

    public function testGetFullBreadcrumbWithEmptySeoBreadCrumb(): void
    {
        $salesChannelContext = Generator::generateSalesChannelContext();

        $categoryBreadcrumbBuilder = $this->createMock(CategoryBreadcrumbBuilder::class);
        $categoryBreadcrumbBuilder->method('build')->willReturn([]);

        $breadCrumb = $this->getBuildBreadcrumbExtension($categoryBreadcrumbBuilder)
            ->getFullBreadcrumb([], new CategoryEntity(), $salesChannelContext);

        static::assertSame([], $breadCrumb);
    }

    public function testGetFullBreadcrumbWithSeoBreadCrumb(): void
    {
        $salesChannelContext = Generator::generateSalesChannelContext();
        $categoryId = Uuid::randomHex();
        $notConsideredCategoryId = Uuid::randomHex();

        $categoryBreadcrumbBuilder = $this->createMock(CategoryBreadcrumbBuilder::class);
        $categoryBreadcrumbBuilder->method('build')->willReturn([$categoryId => 'Home', $notConsideredCategoryId => 'Not considered']);

        $breadCrumb = $this->getBuildBreadcrumbExtension($categoryBreadcrumbBuilder, $categoryId)
            ->getFullBreadcrumb([], new CategoryEntity(), $salesChannelContext);

        static::assertArrayHasKey($categoryId, $breadCrumb);
        static::assertInstanceOf(SalesChannelCategoryEntity::class, $breadCrumb[$categoryId]);
        static::assertArrayNotHasKey($notConsideredCategoryId, $breadCrumb);
    }

    public function testGetFullBreadcrumbByIdWithNonExistingCategoryId(): void
    {
        $salesChannelContext = Generator::generateSalesChannelContext();

        $breadCrumb = $this->getBuildBreadcrumbExtension()
            ->getFullBreadcrumbById([], Uuid::randomHex(), $salesChannelContext);

        static::assertSame([], $breadCrumb);
    }

    public function testGetFullBreadcrumbById(): void
    {
        $salesChannelContext = Generator::generateSalesChannelContext();
        $categoryId = Uuid::randomHex();
        $notConsideredCategoryId = Uuid::randomHex();

        $categoryBreadcrumbBuilder = $this->createMock(CategoryBreadcrumbBuilder::class);
        $categoryBreadcrumbBuilder->method('build')->willReturn([$categoryId => 'Home', $notConsideredCategoryId => 'Not considered']);

        $breadCrumb = $this->getBuildBreadcrumbExtension($categoryBreadcrumbBuilder, $categoryId)
            ->getFullBreadcrumbById([], $categoryId, $salesChannelContext);

        static::assertArrayHasKey($categoryId, $breadCrumb);
        static::assertInstanceOf(SalesChannelCategoryEntity::class, $breadCrumb[$categoryId]);
        static::assertArrayNotHasKey($notConsideredCategoryId, $breadCrumb);
    }

    private function getBuildBreadcrumbExtension(?CategoryBreadcrumbBuilder $categoryBreadcrumbBuilder = null, ?string $categoryId = null): BuildBreadcrumbExtension
    {
        $categoryBreadcrumbBuilder ??= $this->createMock(CategoryBreadcrumbBuilder::class);

        $categories = new CategoryCollection();
        if ($categoryId !== null) {
            $category = new SalesChannelCategoryEntity();
            $category->setUniqueIdentifier($categoryId);
            $categories->add($category);
        }

        $entitySearchResult = new EntitySearchResult(
            CategoryDefinition::ENTITY_NAME,
            1,
            $categories,
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );

        /** @var StaticSalesChannelRepository<EntityCollection<SalesChannelCategoryEntity>> $salesChannelCategoryRepository */
        $salesChannelCategoryRepository = new StaticSalesChannelRepository([
            $entitySearchResult, clone $entitySearchResult,
        ]);

        /** @var StaticEntityRepository<CategoryCollection> $categoryRepository */
        $categoryRepository = new StaticEntityRepository([]);

        return new BuildBreadcrumbExtension($categoryBreadcrumbBuilder, $salesChannelCategoryRepository, $categoryRepository);
    }
}
