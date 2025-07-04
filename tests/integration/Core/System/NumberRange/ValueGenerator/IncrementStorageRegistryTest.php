<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\NumberRange\ValueGenerator;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\Exception\IncrementStorageNotFoundException;
use Shopware\Core\System\NumberRange\ValueGenerator\Pattern\IncrementStorage\IncrementSqlStorage;
use Shopware\Core\System\NumberRange\ValueGenerator\Pattern\IncrementStorage\IncrementStorageRegistry;
use Shopware\Core\Test\Stub\System\NumberRange\ValueGenerator\IncrementArrayStorage;

/**
 * @internal
 */
class IncrementStorageRegistryTest extends TestCase
{
    use IntegrationTestBehaviour;

    private IncrementStorageRegistry $registry;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->registry = static::getContainer()->get(IncrementStorageRegistry::class);

        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->executeStatement('DELETE FROM `number_range_state`');
    }

    public function testGetDefaultStorage(): void
    {
        static::assertInstanceOf(IncrementSqlStorage::class, $this->registry->getStorage());
    }

    public function testGetUnknownStorageThrows(): void
    {
        static::expectException(IncrementStorageNotFoundException::class);
        $this->registry->getStorage('foo');
    }

    public function testMigrateToSqlStorage(): void
    {
        $arrayStorage = new IncrementArrayStorage([
            Uuid::randomHex() => 10,
            Uuid::randomHex() => 4,
        ]);
        $sqlStorage = static::getContainer()->get(IncrementSqlStorage::class);

        $registry = new IncrementStorageRegistry(
            new \ArrayObject(
                [
                    'SQL' => $sqlStorage,
                    'Array' => $arrayStorage,
                ],
            ),
            'SQL'
        );

        static::assertEmpty($sqlStorage->list());

        $registry->migrate('Array', 'SQL');

        static::assertSame($arrayStorage->list(), $sqlStorage->list());
    }

    public function testMigrateFromSqlStorage(): void
    {
        $states = [
            Uuid::randomHex() => 10,
            Uuid::randomHex() => 4,
        ];
        $sqlStorage = static::getContainer()->get(IncrementSqlStorage::class);
        foreach ($states as $key => $value) {
            $sqlStorage->set($key, $value);
        }

        static::assertSame($states, $sqlStorage->list());
        $arrayStorage = new IncrementArrayStorage([]);

        $registry = new IncrementStorageRegistry(
            new \ArrayObject(
                [
                    'SQL' => $sqlStorage,
                    'Array' => $arrayStorage,
                ],
            ),
            'SQL'
        );

        static::assertEmpty($arrayStorage->list());

        $registry->migrate('SQL', 'Array');

        static::assertSame($sqlStorage->list(), $arrayStorage->list());
    }

    public function testMigrateWithUnknownFromStorageThrows(): void
    {
        static::expectException(IncrementStorageNotFoundException::class);
        $this->registry->migrate('foo', 'SQL');
    }

    public function testMigrateWithUnknownToStorageThrows(): void
    {
        static::expectException(IncrementStorageNotFoundException::class);
        $this->registry->migrate('SQL', 'foo');
    }
}
