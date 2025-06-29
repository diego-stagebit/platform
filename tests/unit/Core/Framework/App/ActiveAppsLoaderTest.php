<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\App;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\ActiveAppsLoader;
use Shopware\Core\Framework\App\Lifecycle\AppLoader;
use Shopware\Core\Framework\App\Manifest\Manifest;

/**
 * @internal
 */
#[CoversClass(ActiveAppsLoader::class)]
class ActiveAppsLoaderTest extends TestCase
{
    public function testLoadAppsFromDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'name' => 'test',
                    'path' => 'test',
                    'author' => 'test',
                    'self_managed' => 1,
                ],
            ]);

        $activeAppsLoader = new ActiveAppsLoader(
            $connection,
            $this->createMock(AppLoader::class),
            '/'
        );

        $expected = [
            [
                'name' => 'test',
                'path' => 'test',
                'author' => 'test',
                'selfManaged' => true,
            ],
        ];

        // call twice to test it gets cached
        static::assertSame($expected, $activeAppsLoader->getActiveApps());
        static::assertSame($expected, $activeAppsLoader->getActiveApps());

        // reset cache

        $activeAppsLoader->reset();

        static::assertSame($expected, $activeAppsLoader->getActiveApps());
    }

    public function testLoadAppsFromLocal(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('test'));

        $appLoader = $this->createMock(AppLoader::class);

        $xmlFile = __DIR__ . '/_fixtures/manifest.xml';

        $appLoader
            ->method('load')
            ->willReturn([
                Manifest::createFromXmlFile($xmlFile),
            ]);

        $activeAppsLoader = new ActiveAppsLoader(
            $connection,
            $appLoader,
            \dirname($xmlFile, 2)
        );

        $expected = [
            [
                'name' => 'test',
                'path' => \basename(\dirname($xmlFile)),
                'author' => 'shopware AG',
                'selfManaged' => false,
            ],
        ];

        static::assertSame($expected, $activeAppsLoader->getActiveApps());
    }
}
