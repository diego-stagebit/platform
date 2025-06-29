<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Plugin;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Migration\MigrationCollectionLoader;
use Shopware\Core\Framework\Plugin\Composer\CommandExecutor;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Plugin\Requirement\RequirementsValidator;
use Shopware\Core\Framework\Plugin\Util\AssetService;
use Shopware\Core\Framework\Plugin\Util\VersionSanitizer;
use Shopware\Core\Framework\Test\Plugin\PluginIntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Kernel;
use Shopware\Core\System\CustomEntity\Schema\CustomEntityPersister;
use Shopware\Core\System\CustomEntity\Schema\CustomEntitySchemaUpdater;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagTestPlugin\SwagTestPlugin;
use SwagTestSkipRebuild\SwagTestSkipRebuild;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[Group('slow')]
class KernelPluginIntegrationTest extends TestCase
{
    use PluginIntegrationTestBehaviour;

    private Kernel $kernel;

    protected function tearDown(): void
    {
        $serviceContainer = $this->kernel->getContainer()->get('test.service_container');
        static::assertInstanceOf(TestContainer::class, $serviceContainer);
        $serviceContainer->get('cache.object')->clear();
    }

    public function testWithDisabledPlugins(): void
    {
        $this->insertPlugin($this->getActivePlugin());

        $loader = new StaticKernelPluginLoader($this->classLoader);
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        static::assertEmpty($this->kernel->getPluginLoader()->getPluginInstances()->all());
    }

    public function testInactive(): void
    {
        $this->insertPlugin($this->getInstalledInactivePlugin());

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $plugins = $this->kernel->getPluginLoader()->getPluginInstances();
        static::assertNotEmpty($plugins->all());

        $testPlugin = $plugins->get(SwagTestPlugin::class);
        static::assertNotNull($testPlugin);

        static::assertFalse($testPlugin->isActive());
    }

    public function testActive(): void
    {
        $this->insertPlugin($this->getActivePlugin());

        static::getContainer()
            ->get(Connection::class)
            ->executeStatement('UPDATE plugin SET active = 1, installed_at = date(now())');

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $testPlugin = $this->kernel->getPluginLoader()->getPluginInstances()->get(SwagTestPlugin::class);
        static::assertNotNull($testPlugin);

        static::assertTrue($testPlugin->isActive());
    }

    public function testInactiveDefinitionsNotLoaded(): void
    {
        $this->insertPlugin($this->getInstalledInactivePlugin());

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        static::assertFalse($this->kernel->getContainer()->has(SwagTestPlugin::class));
    }

    public function testActiveAutoLoadedAndWired(): void
    {
        $this->insertPlugin($this->getActivePlugin());

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $swagTestPlugin = $this->kernel->getContainer()->get(SwagTestPlugin::class);
        static::assertInstanceOf(SwagTestPlugin::class, $swagTestPlugin);

        // autowired
        static::assertInstanceOf(SystemConfigService::class, $swagTestPlugin->systemConfig);

        // manually set
        static::assertSame($this->kernel->getContainer()->get('category.repository'), $swagTestPlugin->categoryRepository);
    }

    public function testActivate(): void
    {
        $inactive = $this->getInstalledInactivePlugin();
        $this->insertPlugin($inactive);

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $lifecycleService = $this->makePluginLifecycleService();
        $lifecycleService->activatePlugin($inactive, Context::createDefaultContext());

        $swagTestPlugin = $this->kernel->getPluginLoader()->getPluginInstances()->get($inactive->getBaseClass());
        static::assertInstanceOf(SwagTestPlugin::class, $swagTestPlugin);

        // autowired
        static::assertInstanceOf(SystemConfigService::class, $swagTestPlugin->systemConfig);

        // manually set
        static::assertSame($this->kernel->getContainer()->get('category.repository'), $swagTestPlugin->categoryRepository);

        // the plugin services are still not loaded when the preActivate fires but in the postActivateContext event
        static::assertNull($swagTestPlugin->preActivateContext);
        static::assertNotNull($swagTestPlugin->postActivateContext);
        static::assertNull($swagTestPlugin->preDeactivateContext);
        static::assertNull($swagTestPlugin->postDeactivateContext);
    }

    public function testActivateWithoutRebuildWithSystemSource(): void
    {
        $inactive = $this->getInstalledInactivePluginRebuildDisabled();
        $this->insertPlugin($inactive);

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $lifecycleService = $this->makePluginLifecycleService();
        $lifecycleService->activatePlugin($inactive, Context::createDefaultContext());

        $swagTestPlugin = $this->kernel->getPluginLoader()->getPluginInstances()->get($inactive->getBaseClass());
        static::assertInstanceOf(SwagTestSkipRebuild::class, $swagTestPlugin);

        // not autowired
        static::assertNull($swagTestPlugin->systemConfig);

        // not set
        static::assertNull($swagTestPlugin->categoryRepository);

        // the plugin services are still not loaded
        static::assertNull($swagTestPlugin->preActivateContext);
        static::assertNull($swagTestPlugin->postActivateContext);
        static::assertNull($swagTestPlugin->preDeactivateContext);
        static::assertNull($swagTestPlugin->postDeactivateContext);
    }

    public function testActivateWithoutRebuildWithNonSystemContext(): void
    {
        $inactive = $this->getInstalledInactivePluginRebuildDisabled();
        $this->insertPlugin($inactive);

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $lifecycleService = $this->makePluginLifecycleService();
        $lifecycleService->activatePlugin($inactive, Context::createDefaultContext(new AdminApiSource(Uuid::randomHex())));

        $swagTestPlugin = $this->kernel->getPluginLoader()->getPluginInstances()->get($inactive->getBaseClass());
        static::assertInstanceOf(SwagTestSkipRebuild::class, $swagTestPlugin);

        // autowired
        static::assertInstanceOf(SystemConfigService::class, $swagTestPlugin->systemConfig);

        // manually set
        static::assertSame($this->kernel->getContainer()->get('category.repository'), $swagTestPlugin->categoryRepository);

        // the plugin services are still not loaded when the preActivate fires but in the postActivateContext event
        static::assertNull($swagTestPlugin->preActivateContext);
        static::assertNotNull($swagTestPlugin->postActivateContext);
        static::assertNull($swagTestPlugin->preDeactivateContext);
        static::assertNull($swagTestPlugin->postDeactivateContext);
    }

    public function testDeactivate(): void
    {
        $active = $this->getActivePlugin();
        $this->insertPlugin($active);

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $lifecycleService = $this->makePluginLifecycleService();

        $oldPluginInstance = $this->kernel->getPluginLoader()->getPluginInstances()->get($active->getBaseClass());
        static::assertInstanceOf(SwagTestPlugin::class, $oldPluginInstance);

        $lifecycleService->deactivatePlugin($active, Context::createDefaultContext());

        $swagTestPlugin = $this->kernel->getPluginLoader()->getPluginInstances()->get($active->getBaseClass());
        static::assertInstanceOf(SwagTestPlugin::class, $swagTestPlugin);

        // only the preDeactivate is called with the plugin still active
        static::assertNull($oldPluginInstance->preActivateContext);
        static::assertNull($oldPluginInstance->postActivateContext);
        static::assertNotNull($oldPluginInstance->preDeactivateContext);
        static::assertNull($oldPluginInstance->postDeactivateContext);

        // no plugin service should be loaded after deactivating it
        static::assertNull($swagTestPlugin->systemConfig);
        static::assertNull($swagTestPlugin->categoryRepository);

        static::assertNull($swagTestPlugin->preActivateContext);
        static::assertNull($swagTestPlugin->postActivateContext);
        static::assertNull($swagTestPlugin->preDeactivateContext);
        static::assertNull($swagTestPlugin->postDeactivateContext);
    }

    public function testKernelParameters(): void
    {
        $plugin = $this->getInstalledInactivePlugin();
        $this->insertPlugin($plugin);

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->kernel = $this->makeKernel($loader);
        $this->kernel->boot();

        $expectedParameters = [
            'kernel.project_dir' => TEST_PROJECT_DIR,
            'kernel.plugin_dir' => TEST_PROJECT_DIR . '/custom/plugins',
        ];

        $actualParameters = [];
        foreach ($expectedParameters as $key => $_value) {
            $actualParameters[$key] = $this->kernel->getContainer()->getParameter($key);
        }

        static::assertSame($expectedParameters, $actualParameters);

        $lifecycleService = $this->makePluginLifecycleService();

        $lifecycleService->activatePlugin($plugin, Context::createDefaultContext());

        $newActualParameters = [];
        foreach ($expectedParameters as $key => $_value) {
            $newActualParameters[$key] = $this->kernel->getContainer()->getParameter($key);
        }

        $activePlugins = $this->kernel->getContainer()->getParameter('kernel.active_plugins');

        static::assertIsArray($activePlugins);
        static::assertArrayHasKey(SwagTestPlugin::class, $activePlugins);

        static::assertArrayHasKey('name', $activePlugins[SwagTestPlugin::class]);
        static::assertArrayHasKey('path', $activePlugins[SwagTestPlugin::class]);
        static::assertArrayHasKey('class', $activePlugins[SwagTestPlugin::class]);

        static::assertSame($expectedParameters, $newActualParameters);
    }

    public function testScheduledTaskIsRegisteredOnPluginStateChange(): void
    {
        $plugin = $this->getInstalledInactivePlugin();
        $this->insertPlugin($plugin);

        $loader = new DbalKernelPluginLoader(
            $this->classLoader,
            null,
            static::getContainer()->get(Connection::class)
        );
        $this->makeKernel($loader);
        $kernel = $this->kernel;
        $kernel->boot();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'swag_test.test_task'));

        $context = Context::createDefaultContext();

        $scheduledTasksRepo = $kernel->getContainer()->get('scheduled_task.repository');
        $result = $scheduledTasksRepo->search($criteria, $context)->getEntities()->first();
        static::assertNull($result);

        $pluginLifecycleManager = $this->makePluginLifecycleService();
        $pluginLifecycleManager->activatePlugin($plugin, $context);

        $scheduledTasksRepo = $kernel->getContainer()->get('scheduled_task.repository');
        $result = $scheduledTasksRepo->search($criteria, $context)->getEntities();
        static::assertNotNull($result);

        $pluginLifecycleManager->deactivatePlugin($plugin, $context);

        $scheduledTasksRepo = $kernel->getContainer()->get('scheduled_task.repository');
        $result = $scheduledTasksRepo->search($criteria, $context)->getEntities()->first();
        static::assertNull($result);
    }

    private function makePluginLifecycleService(): PluginLifecycleService
    {
        $kernel = $this->kernel;
        $container = $kernel->getContainer();

        $emptyPluginCollection = new PluginCollection();
        $pluginRepoMock = $this->createMock(EntityRepository::class);

        $pluginRepoMock
            ->method('search')
            ->willReturn(new EntitySearchResult('plugin', 0, $emptyPluginCollection, null, new Criteria(), Context::createDefaultContext()));

        return new PluginLifecycleService(
            $pluginRepoMock,
            $container->get('event_dispatcher'),
            $kernel->getPluginLoader()->getPluginInstances(),
            $container,
            $this->createMock(MigrationCollectionLoader::class),
            $this->createMock(AssetService::class),
            $this->createMock(CommandExecutor::class),
            $this->createMock(RequirementsValidator::class),
            new ArrayAdapter(),
            $container->getParameter('kernel.shopware_version'),
            $this->createMock(SystemConfigService::class),
            $this->createMock(CustomEntityPersister::class),
            $this->createMock(CustomEntitySchemaUpdater::class),
            $this->createMock(PluginService::class),
            $this->createMock(VersionSanitizer::class),
            $this->createMock(DefinitionInstanceRegistry::class)
        );
    }

    private function makeKernel(KernelPluginLoader $loader): Kernel
    {
        $kernel = KernelFactory::create(
            'test',
            true,
            KernelLifecycleManager::getClassLoader(),
            $loader,
            static::getContainer()->get(Connection::class)
        );
        static::assertInstanceOf(Kernel::class, $kernel);
        $this->kernel = $kernel;

        return $this->kernel;
    }
}
