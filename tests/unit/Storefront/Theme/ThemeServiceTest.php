<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Storefront\Theme;

use Doctrine\DBAL\Connection;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Annotation\DisabledFeatures;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Theme\ConfigLoader\DatabaseConfigLoader;
use Shopware\Storefront\Theme\ConfigLoader\StaticFileConfigLoader;
use Shopware\Storefront\Theme\Event\ThemeAssignedEvent;
use Shopware\Storefront\Theme\Event\ThemeConfigChangedEvent;
use Shopware\Storefront\Theme\Event\ThemeConfigResetEvent;
use Shopware\Storefront\Theme\Exception\ThemeException;
use Shopware\Storefront\Theme\Message\CompileThemeMessage;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Shopware\Storefront\Theme\StorefrontPluginRegistry;
use Shopware\Storefront\Theme\ThemeCollection;
use Shopware\Storefront\Theme\ThemeCompiler;
use Shopware\Storefront\Theme\ThemeEntity;
use Shopware\Storefront\Theme\ThemeService;
use Shopware\Tests\Unit\Storefront\Theme\fixtures\ThemeFixtures;
use Shopware\Tests\Unit\Storefront\Theme\fixtures\ThemeFixtures_6_7;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;

/**
 * @internal
 */
#[CoversClass(ThemeService::class)]
class ThemeServiceTest extends TestCase
{
    private Connection&MockObject $connectionMock;

    private StorefrontPluginRegistry&MockObject $storefrontPluginRegistryMock;

    /** @var EntityRepository<ThemeCollection>&MockObject */
    private EntityRepository&MockObject $themeRepositoryMock;

    /** @var EntityRepository<EntityCollection<Entity>>&MockObject */
    private EntityRepository&MockObject $themeSalesChannelRepositoryMock;

    private ThemeCompiler&MockObject $themeCompilerMock;

    private EventDispatcher&MockObject $eventDispatcherMock;

    private ThemeService $themeService;

    private Context $context;

    private SystemConfigService&MockObject $systemConfigMock;

    private MessageBus&MockObject $messageBusMock;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(Connection::class);
        $this->storefrontPluginRegistryMock = $this->createMock(StorefrontPluginRegistry::class);
        $this->themeRepositoryMock = $this->createMock(EntityRepository::class);
        $this->themeSalesChannelRepositoryMock = $this->createMock(EntityRepository::class);
        $this->themeCompilerMock = $this->createMock(ThemeCompiler::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcher::class);
        $databaseConfigLoaderMock = $this->createMock(DatabaseConfigLoader::class);
        $this->context = Context::createDefaultContext();
        $this->systemConfigMock = $this->createMock(SystemConfigService::class);
        $this->messageBusMock = $this->createMock(MessageBus::class);

        $this->themeService = new ThemeService(
            $this->storefrontPluginRegistryMock,
            $this->themeRepositoryMock,
            $this->themeSalesChannelRepositoryMock,
            $this->themeCompilerMock,
            $this->eventDispatcherMock,
            $databaseConfigLoaderMock,
            $this->connectionMock,
            $this->systemConfigMock,
            $this->messageBusMock,
            $this->createMock(NotificationService::class)
        );
    }

    public function testAssignTheme(): void
    {
        $themeId = Uuid::randomHex();

        $this->connectionMock->expects($this->once())->method('transactional')->willReturnCallback(function (callable $callback): void {
            $callback();
        });

        $this->themeSalesChannelRepositoryMock->expects($this->once())->method('upsert')->with(
            [[
                'themeId' => $themeId,
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
            ]],
            $this->context
        );

        $this->eventDispatcherMock->expects($this->once())->method('dispatch')->with(
            new ThemeAssignedEvent($themeId, TestDefaults::SALES_CHANNEL)
        );

        $this->themeCompilerMock->expects($this->once())->method('compileTheme')->with(
            TestDefaults::SALES_CHANNEL,
            $themeId,
            static::anything(),
            static::anything(),
            true,
            $this->context
        );

        $assigned = $this->themeService->assignTheme($themeId, TestDefaults::SALES_CHANNEL, $this->context);

        static::assertTrue($assigned);
    }

    public function testAssignThemeSkipCompile(): void
    {
        $this->connectionMock->expects($this->once())->method('transactional')->willReturnCallback(function (callable $callback): void {
            $callback();
        });

        $themeId = Uuid::randomHex();

        $this->themeSalesChannelRepositoryMock->expects($this->once())->method('upsert')->with(
            [[
                'themeId' => $themeId,
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
            ]],
            $this->context
        );

        $this->eventDispatcherMock->expects($this->once())->method('dispatch')->with(
            new ThemeAssignedEvent($themeId, TestDefaults::SALES_CHANNEL)
        );

        $this->themeCompilerMock->expects($this->never())->method('compileTheme');

        $assigned = $this->themeService->assignTheme($themeId, TestDefaults::SALES_CHANNEL, $this->context, true);

        static::assertTrue($assigned);
    }

    public function testCompileTheme(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeCompilerMock->expects($this->once())->method('compileTheme')->with(
            TestDefaults::SALES_CHANNEL,
            $themeId,
            static::anything(),
            static::anything(),
            true,
            $this->context
        );

        $this->themeService->compileTheme(TestDefaults::SALES_CHANNEL, $themeId, $this->context);
    }

    public function testCompileThemeAsyncSkipHeader(): void
    {
        $themeId = Uuid::randomHex();

        $this->context->addState(ThemeService::STATE_NO_QUEUE);

        $this->messageBusMock->expects($this->never())->method('dispatch');

        $this->themeCompilerMock->expects($this->once())->method('compileTheme')->with(
            TestDefaults::SALES_CHANNEL,
            $themeId,
            static::anything(),
            static::anything(),
            true,
            $this->context
        );

        $this->systemConfigMock->method('get')->with(ThemeService::CONFIG_THEME_COMPILE_ASYNC)->willReturn(true);

        $this->themeService->compileTheme(TestDefaults::SALES_CHANNEL, $themeId, $this->context);
    }

    public function testCompileThemeAsyncSetting(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeCompilerMock->expects($this->never())->method('compileTheme');

        $context = $this->context;
        $this->messageBusMock->expects($this->once())->method('dispatch')
            ->willReturnCallback(function () use ($themeId, $context): Envelope {
                return new Envelope(
                    new CompileThemeMessage(
                        TestDefaults::SALES_CHANNEL,
                        $themeId,
                        true,
                        $context
                    )
                );
            });

        $this->systemConfigMock->method('get')->with(ThemeService::CONFIG_THEME_COMPILE_ASYNC)->willReturn(true);

        $this->themeService->compileTheme(TestDefaults::SALES_CHANNEL, $themeId, $this->context);
    }

    public function testCompileThemeGivenConf(): void
    {
        $themeId = Uuid::randomHex();

        $confCollection = new StorefrontPluginConfigurationCollection();

        $this->themeCompilerMock->expects($this->once())->method('compileTheme')->with(
            TestDefaults::SALES_CHANNEL,
            $themeId,
            static::anything(),
            $confCollection,
            true,
            $this->context
        );

        $this->themeService->compileTheme(TestDefaults::SALES_CHANNEL, $themeId, $this->context, $confCollection);
    }

    public function testCompileThemeWithAssets(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeCompilerMock->expects($this->once())->method('compileTheme')->with(
            TestDefaults::SALES_CHANNEL,
            $themeId,
            static::anything(),
            static::anything(),
            false,
            $this->context
        );

        $this->themeService->compileTheme(TestDefaults::SALES_CHANNEL, $themeId, $this->context, null, false);
    }

    public function testCompileThemeById(): void
    {
        $themeId = Uuid::randomHex();
        $dependendThemeId = Uuid::randomHex();

        $this->connectionMock->method('fetchAllAssociative')->willReturn(
            [
                [
                    'id' => $themeId,
                    'saleschannelId' => TestDefaults::SALES_CHANNEL,
                    'dependentId' => $dependendThemeId,
                    'dsaleschannelId' => TestDefaults::SALES_CHANNEL,
                ],
            ]
        );

        $parameters = [];

        $this->themeCompilerMock
            ->expects($this->exactly(2))
            ->method('compileTheme')
            ->willReturnCallback(function ($salesChannelId, $themeId) use (&$parameters): void {
                $parameters[] = [$salesChannelId, $themeId];
            });

        $this->themeService->compileThemeById($themeId, $this->context);

        static::assertSame([
            [
                TestDefaults::SALES_CHANNEL,
                $themeId,
            ],
            [
                TestDefaults::SALES_CHANNEL,
                $dependendThemeId,
            ],
        ], $parameters);
    }

    public function testUpdateThemeNoTheme(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection([]),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage(\sprintf('Could not find theme with id "%s"', $themeId));

        $this->themeService->updateTheme($themeId, null, null, $this->context);
    }

    public function testUpdateTheme(): void
    {
        $themeId = Uuid::randomHex();
        $dependendThemeId = Uuid::randomHex();

        $this->connectionMock->method('fetchAllAssociative')->willReturn(
            [
                [
                    'id' => $themeId,
                    'saleschannelId' => TestDefaults::SALES_CHANNEL,
                    'dependentId' => $dependendThemeId,
                    'dsaleschannelId' => TestDefaults::SALES_CHANNEL,
                ],
            ]
        );

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection(
                    [
                        (new ThemeEntity())->assign(
                            [
                                '_uniqueIdentifier' => $themeId,
                                'salesChannels' => new SalesChannelCollection(),
                            ]
                        ),
                    ]
                ),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->themeCompilerMock->expects($this->exactly(2))->method('compileTheme');

        $this->themeService->updateTheme($themeId, null, null, $this->context);
    }

    public function testUpdateThemeWithConfig(): void
    {
        $themeId = Uuid::randomHex();
        $parentThemeId = Uuid::randomHex();
        $dependendThemeId = Uuid::randomHex();

        $this->connectionMock->method('fetchAllAssociative')->willReturn(
            [
                [
                    'id' => $themeId,
                    'saleschannelId' => TestDefaults::SALES_CHANNEL,
                    'dependentId' => $dependendThemeId,
                    'dsaleschannelId' => TestDefaults::SALES_CHANNEL,
                ],
            ]
        );

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection(
                    [
                        (new ThemeEntity())->assign(
                            [
                                '_uniqueIdentifier' => $themeId,
                                'salesChannels' => new SalesChannelCollection(),
                                'configValues' => [
                                    'test' => ['value' => ['no_test']],
                                ],
                            ]
                        ),
                    ]
                ),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->eventDispatcherMock->expects($this->once())->method('dispatch')->with(
            new ThemeConfigChangedEvent($themeId, ['test' => ['value' => ['test']]])
        );

        $this->themeCompilerMock->expects($this->exactly(2))->method('compileTheme');

        $this->themeService->updateTheme($themeId, ['test' => ['value' => ['test']]], $parentThemeId, $this->context);
    }

    public function testUpdateThemeNoSalesChannelAssigned(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection(
                    [
                        (new ThemeEntity())->assign(
                            [
                                '_uniqueIdentifier' => $themeId,
                            ]
                        ),
                    ]
                ),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->themeCompilerMock->expects($this->never())->method('compileTheme');

        $this->themeService->updateTheme($themeId, null, null, $this->context);
    }

    public function testResetTheme(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection(
                    [
                        (new ThemeEntity())->assign(
                            [
                                '_uniqueIdentifier' => $themeId,
                            ]
                        ),
                    ]
                ),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->eventDispatcherMock->expects($this->once())->method('dispatch')->with(
            new ThemeConfigResetEvent($themeId)
        );

        $this->themeRepositoryMock->expects($this->once())->method('update')->with(
            [
                [
                    'id' => $themeId,
                    'configValues' => null,
                ],
            ],
            $this->context
        );

        $this->themeService->resetTheme($themeId, $this->context);
    }

    public function testResetThemeNoTheme(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection([]),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage(\sprintf('Could not find theme with id "%s"', $themeId));
        $this->themeService->resetTheme($themeId, $this->context);
    }

    public function testGetPlainThemeConfigurationNoTheme(): void
    {
        $themeId = Uuid::randomHex();

        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                new ThemeCollection(
                    [
                        (new ThemeEntity())->assign(
                            [
                                '_uniqueIdentifier' => 'no',
                                'salesChannels' => new SalesChannelCollection(),
                            ]
                        ),
                    ]
                ),
                null,
                new Criteria(),
                $this->context
            )
        );

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage(\sprintf('Could not find theme with id "%s"', $themeId));

        $this->themeService->getPlainThemeConfiguration($themeId, $this->context);
    }

    /**
     * @deprecated tag:v6.8.0 Will be removed, use testGetPlainThemeConfiguration instead
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed>|null $expected
     * @param array<string, mixed>|null $expectedStructured
     */
    #[DataProviderExternal(ThemeFixtures_6_7::class, 'getThemeCollectionForThemeConfiguration')]
    #[DisabledFeatures(['v6.8.0.0'])]
    public function testGetPlainThemeConfigurationWithTranslations(
        array $ids,
        ThemeCollection $themeCollection,
        ?array $expected = null,
        ?array $expectedStructured = null,
    ): void {
        $this->testGetPlainThemeConfiguration($ids, $themeCollection, $expected, $expectedStructured);
    }

    /**
     * @param array<string, mixed> $ids
     * @param array<string, mixed>|null $expected
     * @param array<string, mixed>|null $expectedStructured
     */
    #[DataProviderExternal(ThemeFixtures::class, 'getThemeCollectionForThemeConfiguration')]
    public function testGetPlainThemeConfiguration(
        array $ids,
        ThemeCollection $themeCollection,
        ?array $expected = null,
        ?array $expectedStructured = null,
    ): void {
        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                $themeCollection,
                null,
                new Criteria(),
                $this->context
            )
        );

        $storefrontPlugin = new StorefrontPluginConfiguration('Test');
        $storefrontPlugin->setThemeConfig(ThemeFixtures::getThemeJsonConfig());

        $this->storefrontPluginRegistryMock->method('getConfigurations')->willReturn(
            new StorefrontPluginConfigurationCollection(
                [
                    $storefrontPlugin,
                ]
            )
        );

        $config = $this->themeService->getPlainThemeConfiguration($ids['themeId'], $this->context, true);

        static::assertArrayHasKey('fields', $config);
        static::assertArrayHasKey('currentFields', $config);
        static::assertArrayHasKey('baseThemeFields', $config);
        static::assertEquals($expected, $config);
    }

    /**
     * @deprecated tag:v6.8.0 Will be removed, use testGetThemeConfigurationFieldStructure instead
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed>|null $expected
     * @param array<string, mixed>|null $expectedStructured
     */
    #[DataProviderExternal(ThemeFixtures_6_7::class, 'getThemeCollectionForThemeConfiguration')]
    #[DisabledFeatures(['v6.8.0.0'])]
    public function testGetThemeConfigurationFieldStructureWithTranslations(
        array $ids,
        ThemeCollection $themeCollection,
        ?array $expected = null,
        ?array $expectedStructured = null,
    ): void {
        $this->testGetThemeConfigurationFieldStructure($ids, $themeCollection, $expected, $expectedStructured);
    }

    /**
     * @param array<string, mixed> $ids
     * @param array<string, mixed>|null $expected
     * @param array<string, mixed>|null $expectedStructured
     */
    #[DataProviderExternal(ThemeFixtures::class, 'getThemeCollectionForThemeConfiguration')]
    public function testGetThemeConfigurationFieldStructure(
        array $ids,
        ThemeCollection $themeCollection,
        ?array $expected = null,
        ?array $expectedStructured = null,
    ): void {
        $this->themeRepositoryMock->method('search')->willReturn(
            new EntitySearchResult(
                'theme',
                1,
                $themeCollection,
                null,
                new Criteria(),
                $this->context
            )
        );

        $storefrontPlugin = new StorefrontPluginConfiguration('Test');
        $storefrontPlugin->setThemeConfig(ThemeFixtures::getThemeJsonConfig());

        $this->storefrontPluginRegistryMock->method('getConfigurations')->willReturn(
            new StorefrontPluginConfigurationCollection(
                [
                    $storefrontPlugin,
                ]
            )
        );

        $config = $this->themeService->getThemeConfigurationFieldStructure($ids['themeId'], $this->context, true);

        static::assertArrayHasKey('tabs', $config);
        static::assertArrayHasKey('default', $config['tabs']);
        static::assertArrayHasKey('blocks', $config['tabs']['default']);
        static::assertEquals($expectedStructured, $config);
    }

    public function testAsyncCompilationIsSkippedWhenUsingStaticConfigLoader(): void
    {
        $themeId = Uuid::randomHex();
        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        $fs->write(\sprintf('theme-config/%s.json', $themeId), (string) json_encode([
            'styleFiles' => [],
            'scriptFiles' => [],
        ], \JSON_THROW_ON_ERROR));
        $configLoader = new StaticFileConfigLoader($fs);

        $themeService = new ThemeService(
            $this->storefrontPluginRegistryMock,
            $this->themeRepositoryMock,
            $this->themeSalesChannelRepositoryMock,
            $this->themeCompilerMock,
            $this->eventDispatcherMock,
            $configLoader,
            $this->connectionMock,
            $this->systemConfigMock,
            $this->messageBusMock,
            $this->createMock(NotificationService::class)
        );

        $this->systemConfigMock->expects($this->never())->method('get');
        $this->messageBusMock->expects($this->never())->method('dispatch');

        $this->themeCompilerMock->expects($this->once())->method('compileTheme')->with(
            TestDefaults::SALES_CHANNEL,
            $themeId,
            static::anything(),
            static::anything(),
            true,
            $this->context
        );

        $themeService->compileTheme(TestDefaults::SALES_CHANNEL, $themeId, $this->context);
    }
}
