<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DependencyInjection\CompilerPass;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Shopware\Core\Framework\DependencyInjection\DependencyInjectionException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
#[Package('framework')]
class TwigLoaderConfigCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $fileSystemLoader = $container->findDefinition('twig.loader.native_filesystem');

        $bundlesMetadata = $container->getParameter('kernel.bundles_metadata');
        if (!\is_array($bundlesMetadata)) {
            throw DependencyInjectionException::bundlesMetadataIsNotAnArray();
        }

        foreach ($bundlesMetadata as $name => $bundle) {
            $resourcesDirectory = $bundle['path'] . '/Resources';
            $viewDirectory = $resourcesDirectory . '/views';
            $distDirectory = $resourcesDirectory . '/app/storefront/dist';

            if (\is_dir($viewDirectory)) {
                $fileSystemLoader->addMethodCall('addPath', [$viewDirectory]);
                $fileSystemLoader->addMethodCall('addPath', [$viewDirectory, $name]);
            }

            if (\is_dir($distDirectory)) {
                $fileSystemLoader->addMethodCall('addPath', [$distDirectory, $name]);
            }

            if (\is_dir($resourcesDirectory)) {
                $fileSystemLoader->addMethodCall('addPath', [$resourcesDirectory, $name]);
            }
        }

        // App templates are only loaded in dev env from files
        // on prod they are loaded from DB as the app files might not exist locally
        if ($container->getParameter('kernel.environment') === 'dev') {
            $this->addAppTemplatePaths($container, $fileSystemLoader);
        }
    }

    private function addAppTemplatePaths(ContainerBuilder $container, Definition $fileSystemLoader): void
    {
        $connection = $container->get(Connection::class);

        try {
            $apps = $connection->fetchAllAssociative('SELECT `name`, `path` FROM `app` WHERE `active` = 1');
        } catch (Exception) {
            // If DB is not yet set up correctly we don't need to add app paths
            return;
        }

        $projectDir = $container->getParameter('kernel.project_dir');
        if (!\is_string($projectDir)) {
            throw DependencyInjectionException::projectDirNotInContainer();
        }

        foreach ($apps as $app) {
            \assert(\is_string($app['path']));
            $resourcesDirectory = \sprintf('%s/%s/Resources', $projectDir, $app['path']);
            $viewDirectory = $resourcesDirectory . '/views';
            $distDirectory = $resourcesDirectory . '/app/storefront/dist';

            if (\is_dir($viewDirectory)) {
                $fileSystemLoader->addMethodCall('addPath', [$viewDirectory]);
                $fileSystemLoader->addMethodCall('addPath', [$viewDirectory, $app['name']]);
            }

            if (\is_dir($distDirectory)) {
                $fileSystemLoader->addMethodCall('addPath', [$distDirectory, $app['name']]);
            }

            if (\is_dir($resourcesDirectory)) {
                $fileSystemLoader->addMethodCall('addPath', [$resourcesDirectory, $app['name']]);
            }
        }
    }
}
