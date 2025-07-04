<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Field;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\DataAbstractionLayerFieldTestBehaviour;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ConfigJsonDefinition;
use Shopware\Core\Framework\Test\TestCaseBase\CacheTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class ConfigJsonFieldTest extends TestCase
{
    use CacheTestBehaviour;
    use DataAbstractionLayerFieldTestBehaviour {
        tearDown as protected tearDownDefinitions;
    }
    use KernelTestBehaviour;

    private Connection $connection;

    private ConfigJsonDefinition $configJsonDefinition;

    protected function setUp(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);

        $nullableTable = <<<EOF
DROP TABLE IF EXISTS _test_nullable;
CREATE TABLE `_test_nullable` (
  `id` varbinary(16) NOT NULL,
  `data` json NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NULL,
  PRIMARY KEY `id` (`id`)
);
EOF;
        $this->connection->executeStatement($nullableTable);
        $this->connection->beginTransaction();

        $definition = $this->registerDefinition(ConfigJsonDefinition::class);
        static::assertInstanceOf(ConfigJsonDefinition::class, $definition);
        $this->configJsonDefinition = $definition;
    }

    protected function tearDown(): void
    {
        $this->tearDownDefinitions();
        $this->connection->rollBack();
        $this->connection->executeStatement('DROP TABLE `_test_nullable`');
    }

    public function testFilter(): void
    {
        $context = WriteContext::createFromContext(Context::createDefaultContext());

        $stringId = Uuid::randomHex();
        $string = 'random string';

        $objectId = Uuid::randomHex();
        $object = [
            'foo' => 'bar',
        ];

        $data = [
            [
                'id' => $stringId,
                'data' => $string,
            ],
            [
                'id' => $objectId,
                'data' => $object,
            ],
        ];
        $this->getWriter()->insert($this->configJsonDefinition, $data, $context);

        $searcher = $this->getSearcher();
        $context = $context->getContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('data', $string));
        $result = $searcher->search($this->configJsonDefinition, $criteria, $context);

        static::assertCount(1, $result->getIds());
        static::assertSame([$stringId], $result->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('data.foo', 'bar'));
        $result = $searcher->search($this->configJsonDefinition, $criteria, $context);

        static::assertCount(1, $result->getIds());
        static::assertSame([$objectId], $result->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('data', 'not found'));
        $result = $searcher->search($this->configJsonDefinition, $criteria, $context);

        static::assertCount(0, $result->getIds());
    }

    private function getWriter(): EntityWriterInterface
    {
        return static::getContainer()->get(EntityWriter::class);
    }

    private function getSearcher(): EntitySearcherInterface
    {
        return static::getContainer()->get(EntitySearcherInterface::class);
    }
}
