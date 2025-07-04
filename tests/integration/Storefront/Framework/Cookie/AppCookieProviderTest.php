<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Framework\Cookie;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\AppSystemTestBehaviour;
use Shopware\Storefront\Framework\Cookie\AppCookieProvider;
use Shopware\Storefront\Framework\Cookie\CookieProviderInterface;

/**
 * @internal
 */
class AppCookieProviderTest extends TestCase
{
    use AppSystemTestBehaviour;
    use IntegrationTestBehaviour;

    private MockObject&CookieProviderInterface $baseProvider;

    private AppCookieProvider $appCookieProvider;

    protected function setUp(): void
    {
        $this->baseProvider = $this->createMock(CookieProviderInterface::class);
        $this->appCookieProvider = new AppCookieProvider(
            $this->baseProvider,
            static::getContainer()->get('app.repository')
        );
    }

    public function testItReturnsDefaultCookiesIfNoAppIsInstalled(): void
    {
        $this->baseProvider->expects($this->once())
            ->method('getCookieGroups')
            ->willReturn(['test']);

        $result = $this->appCookieProvider->getCookieGroups();

        static::assertSame(['test'], $result);
    }

    public function testItAddsSingleCookieFromApp(): void
    {
        $this->baseProvider->expects($this->once())
            ->method('getCookieGroups')
            ->willReturn([]);

        $this->loadAppsFromDir(__DIR__ . '/_fixtures/singleCookie');

        $result = $this->appCookieProvider->getCookieGroups();
        static::assertCount(1, $result);
        static::assertEquals([
            'snippet_name' => 'Swoogle Analytics',
            'cookie' => 'swag.analytics',
            'value' => '',
            'expiration' => '30',
        ], $result[0]);
    }

    public function testItAddsCookieGroupFromApp(): void
    {
        $this->baseProvider->expects($this->once())
            ->method('getCookieGroups')
            ->willReturn([]);

        $this->loadAppsFromDir(__DIR__ . '/_fixtures/cookieGroup');

        $result = $this->appCookieProvider->getCookieGroups();
        static::assertCount(1, $result);
        static::assertEquals([
            'snippet_name' => 'App Cookies',
            'snippet_description' => 'Cookies required for this app to work',
            'entries' => [
                [
                    'snippet_name' => 'Something',
                    'cookie' => 'swag.app.something',
                ],
                [
                    'snippet_name' => 'Lorem ipsum',
                    'cookie' => 'swag.app.lorem-ipsum',
                ],
            ],
        ], $result[0]);
    }

    public function testItMergesCookiesFromAppWithCoreGroup(): void
    {
        $this->baseProvider->expects($this->once())
            ->method('getCookieGroups')
            ->willReturn([[
                'snippet_name' => 'cookie.groupRequired',
                'entries' => [
                    [
                        'snippet_name' => 'cookie.core',
                        'cookie' => 'core.something',
                    ],
                ],
            ]]);

        $this->loadAppsFromDir(__DIR__ . '/_fixtures/coreGroup');

        $result = $this->appCookieProvider->getCookieGroups();
        static::assertCount(1, $result);
        static::assertSame('cookie.groupRequired', $result[0]['snippet_name']);
        static::assertCount(3, $result[0]['entries']);
        usort($result[0]['entries'], fn (array $a, array $b): int => $a['snippet_name'] <=> $b['snippet_name']);

        static::assertEquals([
            [
                'snippet_name' => 'Lorem ipsum',
                'cookie' => 'swag.app.lorem-ipsum',
            ],
            [
                'snippet_name' => 'Something',
                'cookie' => 'swag.app.something',
            ],
            [
                'snippet_name' => 'cookie.core',
                'cookie' => 'core.something',
            ],
        ], $result[0]['entries']);
    }

    public function testItMergesCookiesFromMultipleApps(): void
    {
        $this->baseProvider->expects($this->once())
            ->method('getCookieGroups')
            ->willReturn([]);

        $this->loadAppsFromDir(__DIR__ . '/_fixtures/mergeAppGroups');

        $result = $this->appCookieProvider->getCookieGroups();
        static::assertCount(1, $result);
        static::assertSame('App Cookies', $result[0]['snippet_name']);
        static::assertCount(3, $result[0]['entries']);
        usort($result[0]['entries'], fn (array $a, array $b): int => $a['snippet_name'] <=> $b['snippet_name']);

        static::assertEquals([
            [
                'snippet_name' => 'Foobar',
                'cookie' => 'swag.app.foobar',
            ],
            [
                'snippet_name' => 'Lorem ipsum',
                'cookie' => 'swag.app.lorem-ipsum',
            ],
            [
                'snippet_name' => 'Something',
                'cookie' => 'swag.app.something',
            ],
        ], $result[0]['entries']);
    }

    public function testItIgnoresDeactivatedApps(): void
    {
        $this->baseProvider->expects($this->once())
            ->method('getCookieGroups')
            ->willReturn([]);

        $this->loadAppsFromDir(__DIR__ . '/_fixtures/singleCookie', false);

        $result = $this->appCookieProvider->getCookieGroups();
        static::assertEmpty($result);
    }
}
