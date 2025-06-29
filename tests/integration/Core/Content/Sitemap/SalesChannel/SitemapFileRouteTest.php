<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Sitemap\SalesChannel;

use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Sitemap\Service\SitemapLister;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
#[Package('discovery')]
#[Group('store-api')]
class SitemapFileRouteTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    private KernelBrowser $browser;

    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();

        $this->browser = $this->createCustomSalesChannelBrowser([
            'id' => $this->ids->create('sales-channel'),
        ]);
    }

    public function testSitemapFiles(): void
    {
        $fileSystem = static::getContainer()->get('shopware.filesystem.sitemap');
        static::assertInstanceOf(FilesystemOperator::class, $fileSystem);

        $sitemapLister = static::getContainer()->get(SitemapLister::class);
        static::assertInstanceOf(SitemapLister::class, $sitemapLister);

        $context = static::getContainer()->get(SalesChannelContextFactory::class)->create('', $this->ids->get('sales-channel'));

        $sitemapPath = 'sitemap/salesChannel-' . $context->getSalesChannelId() . '-' . $context->getLanguageId();

        $fileSystem->write($sitemapPath . '/test.xml.gz', 'bar');

        $sitemaps = $sitemapLister->getSitemaps($context);

        $filePath = $this->getSitemapFilePathFromUrl($sitemaps[0]->getFilename());

        $this->browser->request('POST', '/store-api/sitemap/' . $filePath);

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());
        static::assertSame('bar', $this->browser->getInternalResponse()->getContent());
    }

    private function getSitemapFilePathFromUrl(string $url): string
    {
        $regex = '/sitemap\/([A-Za-z0-9-\/.]+)/';

        $matches = [];
        preg_match($regex, $url, $matches);

        $filePath = $matches[1] ?? null;
        static::assertIsString($filePath);

        return $filePath;
    }
}
