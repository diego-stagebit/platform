<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Script\Debugging\ScriptTraces;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedHook;
use Shopware\Storefront\Pagelet\Menu\Offcanvas\MenuOffcanvasPageletLoadedHook;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;

/**
 * @internal
 */
class NavigationControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StorefrontControllerTestBehaviour;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();

        $this->createData();
    }

    public function testNavigationPageLoadedHookScriptsAreExecuted(): void
    {
        $response = $this->request('GET', '/', []);
        static::assertSame(200, $response->getStatusCode());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(NavigationPageLoadedHook::HOOK_NAME, $traces);
    }

    public function testNavigationPageLoadedHookScriptsAreExecutedForCategory(): void
    {
        $response = $this->request('GET', '/my-navigation/', []);

        static::assertSame(200, $response->getStatusCode(), print_r($response->getContent(), true));

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(NavigationPageLoadedHook::HOOK_NAME, $traces);
    }

    public function testMenuOffcanvasPageletLoadedHookScriptsAreExecuted(): void
    {
        $response = $this->request('GET', '/widgets/menu/offcanvas', []);
        static::assertSame(200, $response->getStatusCode());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(MenuOffcanvasPageletLoadedHook::HOOK_NAME, $traces);
    }

    private function createData(): void
    {
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = static::getContainer()->get('sales_channel.repository')->search(
            (new Criteria())->addFilter(
                new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT),
                new EqualsFilter('domains.url', $_SERVER['APP_URL'])
            ),
            Context::createDefaultContext()
        )->first();

        $category = [
            'id' => $this->ids->create('category'),
            'name' => 'my-navigation',
            'type' => 'landing_page',
            'parentId' => $salesChannel->getNavigationCategoryId(),
        ];

        static::getContainer()->get('category.repository')->create([$category], Context::createDefaultContext());
    }
}
