<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Plugin;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\Source\SourceResolver;
use Shopware\Core\Framework\Plugin\BundleConfigGenerator;
use Shopware\Core\Framework\Plugin\BundleConfigGeneratorInterface;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\AppSystemTestBehaviour;
use Shopware\Storefront\Theme\StorefrontPluginRegistry;

/**
 * @internal
 */
class BundleConfigGeneratorTest extends TestCase
{
    use AppSystemTestBehaviour;
    use IntegrationTestBehaviour;

    private BundleConfigGeneratorInterface $configGenerator;

    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = __DIR__ . '/../../../../../src/Core/Framework/Test/Plugin/_fixture/';
        $this->configGenerator = static::getContainer()->get(BundleConfigGenerator::class);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(SourceResolver::class)->reset();
    }

    public function testGenerateAppConfigWithThemeAndScriptAndStylePaths(): void
    {
        $appPath = $this->fixturePath . 'apps/theme/';
        $this->loadAppsFromDir($appPath);
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');

        if (mb_strpos($appPath, $projectDir) === 0) {
            // make relative
            $appPath = ltrim(mb_substr($appPath, mb_strlen($projectDir)), '/');
        }

        $configs = $this->configGenerator->getConfig();

        static::assertArrayHasKey('SwagApp', $configs);

        $appConfig = $configs['SwagApp'];
        static::assertSame(
            $appPath,
            $appConfig['basePath']
        );
        static::assertSame(['Resources/views'], $appConfig['views']);
        static::assertSame('swag-app', $appConfig['technicalName']);
        static::assertArrayNotHasKey('administration', $appConfig);

        static::assertArrayHasKey('storefront', $appConfig);
        $storefrontConfig = $appConfig['storefront'];

        static::assertSame('Resources/app/storefront/src', $storefrontConfig['path']);
        static::assertSame('Resources/app/storefront/src/main.js', $storefrontConfig['entryFilePath']);
        static::assertNull($storefrontConfig['webpack']);

        // Style files can and need only be imported if storefront is installed
        if (static::getContainer()->has(StorefrontPluginRegistry::class)) {
            $appPath = 'src/Core/Framework/Test/Plugin/_fixture/apps/theme/';
            $expectedStyles = [
                $appPath . 'Resources/app/storefront/src/scss/base.scss',
                $appPath . 'Resources/app/storefront/src/scss/overrides.scss',
            ];
            static::assertSame([], array_diff($expectedStyles, $storefrontConfig['styleFiles']));
        }
    }

    public function testGenerateAppConfigWithPluginAndScriptAndStylePaths(): void
    {
        $appPath = $this->fixturePath . 'apps/plugin/';
        $this->loadAppsFromDir($appPath);

        $configs = $this->configGenerator->getConfig();
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');

        static::assertArrayHasKey('SwagApp', $configs);

        $appConfig = $configs['SwagApp'];
        static::assertSame(
            realpath($appPath),
            realpath($projectDir . '/' . $appConfig['basePath'])
        );
        static::assertSame(['Resources/views'], $appConfig['views']);
        static::assertSame('swag-app', $appConfig['technicalName']);
        static::assertArrayNotHasKey('administration', $appConfig);

        static::assertArrayHasKey('storefront', $appConfig);
        $storefrontConfig = $appConfig['storefront'];

        static::assertSame('Resources/app/storefront/src', $storefrontConfig['path']);
        static::assertSame('Resources/app/storefront/src/main.js', $storefrontConfig['entryFilePath']);
        static::assertNull($storefrontConfig['webpack']);

        // Style files can and need only be imported if storefront is installed
        if (static::getContainer()->has(StorefrontPluginRegistry::class)) {
            if (mb_strpos($appPath, $projectDir) === 0) {
                // make relative
                $appPath = ltrim(mb_substr((string) realpath($appPath), mb_strlen($projectDir)), '/');
            }

            // Only base.scss from /_fixture/apps/plugin/ should be included
            $expectedStyles = [
                $appPath . '/Resources/app/storefront/src/scss/base.scss',
            ];

            static::assertSame($expectedStyles, $storefrontConfig['styleFiles']);
        }
    }

    public function testGenerateAppConfigIgnoresInactiveApps(): void
    {
        $appPath = $this->fixturePath . 'apps/theme/';
        $this->loadAppsFromDir($appPath, false);

        $configs = $this->configGenerator->getConfig();

        static::assertArrayNotHasKey('SwagApp', $configs);
    }

    public function testGenerateAppConfigWithWebpackConfig(): void
    {
        $appPath = $this->fixturePath . 'apps/with-webpack/';
        $this->loadAppsFromDir($appPath);

        $configs = $this->configGenerator->getConfig();

        static::assertArrayHasKey('SwagTest', $configs);

        $appConfig = $configs['SwagTest'];
        static::assertSame(
            $appPath,
            static::getContainer()->getParameter('kernel.project_dir') . '/' . $appConfig['basePath']
        );
        static::assertSame(['Resources/views'], $appConfig['views']);
        static::assertSame('swag-test', $appConfig['technicalName']);
        static::assertArrayNotHasKey('administration', $appConfig);

        static::assertArrayHasKey('storefront', $appConfig);
        $storefrontConfig = $appConfig['storefront'];

        static::assertSame('Resources/app/storefront/src', $storefrontConfig['path']);
        static::assertNull($storefrontConfig['entryFilePath']);
        static::assertSame('Resources/app/storefront/build/webpack.config.js', $storefrontConfig['webpack']);
        static::assertSame([], $storefrontConfig['styleFiles']);
    }
}
