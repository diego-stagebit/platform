<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Framework\Seo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Seo\SeoUrlTemplate\SeoUrlTemplateCollection;
use Shopware\Core\Content\Seo\SeoUrlTemplate\SeoUrlTemplateDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Framework\Seo\SeoUrlRoute\ProductPageSeoUrlRoute;

/**
 * @internal
 */
#[Package('inventory')]
class SeoUrlTemplateRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testCreate(): void
    {
        $id = Uuid::randomHex();
        $template = [
            'id' => $id,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
            'entityName' => static::getContainer()->get(ProductDefinition::class)->getEntityName(),
            'template' => ProductPageSeoUrlRoute::DEFAULT_TEMPLATE,
        ];

        $context = Context::createDefaultContext();
        /** @var EntityRepository<SeoUrlTemplateCollection> $repo */
        $repo = static::getContainer()->get('seo_url_template.repository');
        $events = $repo->create([$template], $context);
        static::assertNotNull($events->getEvents());
        static::assertCount(1, $events->getEvents());

        $event = $events->getEventByEntityName(SeoUrlTemplateDefinition::ENTITY_NAME);
        static::assertNotNull($event);
        static::assertCount(1, $event->getPayloads());
    }

    /**
     * @param array<string, string> $template
     */
    #[DataProvider('templateUpdateDataProvider')]
    public function testUpdate(string $id, array $template): void
    {
        $context = Context::createDefaultContext();
        /** @var EntityRepository<SeoUrlTemplateCollection> $repo */
        $repo = static::getContainer()->get('seo_url_template.repository');
        $repo->create([$template], $context);

        $update = [
            'id' => $id,
            'routeName' => 'foo_bar',
        ];
        $events = $repo->update([$update], $context);
        $event = $events->getEventByEntityName(SeoUrlTemplateDefinition::ENTITY_NAME);
        static::assertNotNull($event);
        static::assertCount(1, $event->getPayloads());

        $first = $repo->search(new Criteria([$id]), $context)->getEntities()->first();
        static::assertNotNull($first);
        static::assertSame($update['id'], $first->getId());
        static::assertSame($update['routeName'], $first->getRouteName());
    }

    public function testDelete(): void
    {
        $id = Uuid::randomHex();
        $template = [
            'id' => $id,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
            'entityName' => static::getContainer()->get(ProductDefinition::class)->getEntityName(),
            'template' => ProductPageSeoUrlRoute::DEFAULT_TEMPLATE,
        ];

        $context = Context::createDefaultContext();
        /** @var EntityRepository<SeoUrlTemplateCollection> $repo */
        $repo = static::getContainer()->get('seo_url_template.repository');
        $repo->create([$template], $context);

        $result = $repo->delete([['id' => $id]], $context);
        $event = $result->getEventByEntityName(SeoUrlTemplateDefinition::ENTITY_NAME);
        static::assertNotNull($event);
        static::assertSame([$id], $event->getIds());

        $first = $repo->search(new Criteria([$id]), $context)->getEntities()->first();
        static::assertNull($first);
    }

    public static function templateUpdateDataProvider(): \Generator
    {
        $templates = [
            [
                'id' => null,
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
                'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
                'entityName' => ProductDefinition::ENTITY_NAME,
                'template' => ProductPageSeoUrlRoute::DEFAULT_TEMPLATE,
            ],
            [
                'id' => null,
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
                'routeName' => ProductPageSeoUrlRoute::ROUTE_NAME,
                'entityName' => ProductDefinition::ENTITY_NAME,
                'template' => '',
            ],
        ];

        foreach ($templates as $template) {
            $id = Uuid::randomHex();
            $template['id'] = $id;

            yield [
                'id' => $id,
                'template' => $template,
            ];
        }
    }
}
