<?php declare(strict_types=1);

namespace Shopware\Tests\Migration\Core;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Migration\Exception\UnknownMigrationSourceException;
use Shopware\Core\Framework\Migration\MigrationCollectionLoader;
use Shopware\Core\Framework\Migration\MigrationException;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Test\Migration\MigrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * @internal
 */
#[CoversClass(MigrationCollectionLoader::class)]
class MigrationLoaderTest extends TestCase
{
    use IntegrationTestBehaviour;
    use MigrationTestBehaviour;

    private Connection $connection;

    private MigrationCollectionLoader $loader;

    protected function setUp(): void
    {
        $container = static::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->loader = $container->get(MigrationCollectionLoader::class);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `migration`
              WHERE `class` LIKE \'%_test_migrations_valid%\'
              OR `class` LIKE \'%_test_migrations_invalid_namespace%\''
        );
    }

    public function testExceptionForInvalidNames(): void
    {
        $this->expectException(UnknownMigrationSourceException::class);
        $this->expectExceptionMessage('No source registered for "FOOBAR"');
        $this->loader->collect('FOOBAR');
    }

    public function testTheInterfaceNew(): void
    {
        $collection = $this->loader->collect('core');

        static::assertSame('core', $collection->getName());
        static::assertContainsOnlyInstancesOf(MigrationStep::class, $collection->getMigrationSteps());
        static::assertCount(0, $collection->getMigrationSteps());

        $collection = $this->loader->collect('core.V6_3');

        static::assertSame('core.V6_3', $collection->getName());
        static::assertContainsOnlyInstancesOf(MigrationStep::class, $collection->getMigrationSteps());
        static::assertGreaterThan(1, \count($collection->getMigrationSteps()));
    }

    public function testItLoadsTheValidMigrations(): void
    {
        $collection = $this->loader->collect('_test_migrations_valid');
        $collection->sync();

        $migrations = $this->getMigrations();

        $migrationsObjects = [];
        foreach ($migrations as $migration) {
            $migrationObject = new $migration['class']();
            static::assertInstanceOf(MigrationStep::class, $migrationObject);
            $migrationsObjects[] = $migrationObject;
        }

        static::assertCount(2, $migrationsObjects);
        static::assertNull($migrations[0]['update']);
        static::assertNull($migrations[0]['update_destructive']);
        static::assertNull($migrations[0]['message']);
        static::assertNotNull($migrations[0]['class']);
        static::assertNotNull($migrations[0]['creation_timestamp']);
        static::assertSame(1, $migrationsObjects[0]->getCreationTimestamp());
        static::assertSame(2, $migrationsObjects[1]->getCreationTimestamp());
    }

    public function testItGetsCorrectMigrationTimestamps(): void
    {
        $collection = $this->loader->collect('_test_migrations_valid');
        $migrations = $collection->getActiveMigrationTimestamps();

        static::assertCount(2, $migrations);
        static::assertSame(1, $migrations[0]);
        static::assertSame(2, $migrations[1]);
    }

    public function testThatInvalidMigrationClassesThrowOnLazyInit(): void
    {
        $collection = $this->loader->collect('_test_migrations_invalid_namespace');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/Unable to load migration Shopware\\\\Core\\\\Framework\\\\Test\\\\Migration\\\\_test_migrations_invalid_namespace\\\\Migration1WithoutANamespace at path/');

        $collection->getMigrationSteps();
    }

    public function testNullCollection(): void
    {
        $nullCollection = $this->loader->collect('null');

        $nullCollection->sync();

        static::assertCount(0, $nullCollection->migrateInPlace());
        static::assertCount(0, $nullCollection->migrateDestructiveInPlace());

        static::assertCount(0, $nullCollection->getMigrationSteps());
        static::assertCount(0, $nullCollection->getActiveMigrationTimestamps());
        static::assertCount(0, $nullCollection->getExecutableMigrations());
        static::assertCount(0, $nullCollection->getExecutableDestructiveMigrations());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getMigrations(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('migration')
            ->where('`class` LIKE \'%_test_migrations_valid%\'')
            ->orderBy('creation_timestamp', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
