<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Elasticsearch\Framework\Indexing;

use Doctrine\DBAL\Connection;
use OpenSearch\Client;
use OpenSearch\Namespaces\IndicesNamespace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Elasticsearch\ElasticsearchException;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;
use Shopware\Elasticsearch\Framework\ElasticsearchRegistry;
use Shopware\Elasticsearch\Framework\Indexing\ElasticsearchIndexer;
use Shopware\Elasticsearch\Framework\Indexing\ElasticsearchIndexingMessage;
use Shopware\Elasticsearch\Framework\Indexing\Event\ElasticsearchIndexIteratorEvent;
use Shopware\Elasticsearch\Framework\Indexing\IndexCreator;
use Shopware\Elasticsearch\Framework\Indexing\IndexerOffset;
use Shopware\Elasticsearch\Framework\Indexing\IndexingDto;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[CoversClass(ElasticsearchIndexer::class)]
class ElasticsearchIndexerTest extends TestCase
{
    private Connection&MockObject $connection;

    private MockObject&ElasticsearchHelper $helper;

    private ElasticsearchRegistry $registry;

    private MockObject&IndexCreator $indexCreator;

    private MockObject&IteratorFactory $iteratorFactory;

    private Client&MockObject $client;

    private IndicesNamespace&MockObject $indices;

    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->helper = $this->createMock(ElasticsearchHelper::class);
        $this->registry = new ElasticsearchRegistry([$this->createDefinition('product')]);
        $this->indexCreator = $this->createMock(IndexCreator::class);
        $this->iteratorFactory = $this->createMock(IteratorFactory::class);
        $this->client = $this->createMock(Client::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->helper->method('allowIndexing')->willReturn(true);

        $this->indices = $this->createMock(IndicesNamespace::class);
        $this->client->method('indices')->willReturn($this->indices);

        parent::setUp();
    }

    public function testIterateESDisabled(): void
    {
        $this->helper = $this->createMock(ElasticsearchHelper::class);
        $indexer = $this->getIndexer();

        static::assertNull($indexer->iterate(), 'Iterate should return null if es is disabled');
    }

    public function testIterateNullCreatesIndices(): void
    {
        $indexer = $this->getIndexer();

        $this->indexCreator
            ->expects($this->once())
            ->method('createIndex');

        $msg = $indexer->iterate();

        static::assertNull($msg);
    }

    public function testIterateNullCreatesIndicesAndIndexTaskInDB(): void
    {
        $indexer = $this->getIndexer();
        $this->connection
            ->expects($this->once())
            ->method('insert')
            ->with('elasticsearch_index_task');

        $this->indexCreator
            ->method('aliasExists')
            ->willReturn(true);

        $this
            ->indexCreator
            ->expects($this->once())
            ->method('createIndex');

        $msg = $indexer->iterate();

        static::assertNull($msg);
    }

    public function testIterateWithMessage(): void
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatched = false;

        $query = $this->createMock(IterableQuery::class);
        $query->method('fetch')->willReturn(['1', '2']);

        $this->iteratorFactory
            ->method('createIterator')
            ->willReturn($query);

        $eventDispatcher->addListener(ElasticsearchIndexIteratorEvent::class, function (ElasticsearchIndexIteratorEvent $event) use (&$eventDispatched, $query): void {
            $eventDispatched = true;
            static::assertSame($query, $event->iterator);
        });

        $indexer = $this->getIndexer(eventDispatcher: $eventDispatcher);

        $offset = new IndexerOffset(['product'], null);

        $msg = $indexer->iterate($offset);

        static::assertInstanceOf(ElasticsearchIndexingMessage::class, $msg);
        static::assertSame(Defaults::LANGUAGE_SYSTEM, $msg->getContext()->getLanguageId());
        static::assertSame(['1', '2'], $msg->getData()->getIds());
        static::assertTrue($eventDispatched);
    }

    public function testIterateWithUnknownDefinition(): void
    {
        $indexer = $this->getIndexer();

        $query = $this->createMock(IterableQuery::class);
        $query->method('fetch')->willReturn(['1', '2']);

        $this->iteratorFactory
            ->method('createIterator')
            ->willReturn($query);

        $offset = new IndexerOffset(['foo'], null);

        static::expectException(ElasticsearchException::class);
        static::expectExceptionMessage('Definition foo not found');

        $indexer->iterate($offset);
    }

    public function testIterateWithMessageMultipleDefinitions(): void
    {
        $this->registry = new ElasticsearchRegistry([
            $this->createDefinition('product'),
            $this->createDefinition('category'),
        ]);

        $indexer = $this->getIndexer();

        $msg = $indexer->iterate();

        static::assertNull($msg);
    }

    public function testUpdateIdsESDisabled(): void
    {
        $this->helper = $this->createMock(ElasticsearchHelper::class);
        $this->helper
            ->expects($this->never())
            ->method('getIndexName');

        $indexer = $this->getIndexer();

        $indexer->updateIds($this->createMock(EntityDefinition::class), ['1', '2']);
    }

    public function testUpdateIndexDoesNotExistsCreatesThem(): void
    {
        $this->indexCreator
            ->expects($this->once())
            ->method('createIndex');

        $indexer = $this->getIndexer();

        $indexer->updateIds($this->createMock(EntityDefinition::class), ['1', '2']);
    }

    public function testHandleESDisabled(): void
    {
        $this->helper = $this->createMock(ElasticsearchHelper::class);

        $this->connection->expects($this->never())->method('executeStatement');

        $indexer = $this->getIndexer();

        $indexer(new ElasticsearchIndexingMessage(new IndexingDto([Uuid::randomHex()], 'foo', 'not_existing'), null, Context::createDefaultContext()));
    }

    public function testHandleIndexingInvalidDefinition(): void
    {
        $message = new ElasticsearchIndexingMessage(
            new IndexingDto([Uuid::randomHex()], 'foo', 'not_existing'),
            null,
            Context::createDefaultContext()
        );

        $this->indices
            ->expects($this->once())
            ->method('exists')->willReturn(true);

        $indexer = $this->getIndexer();

        static::expectException(ElasticsearchException::class);
        static::expectExceptionMessage('Definition not_existing not found');

        $indexer($message);
    }

    public function testHandleIndexingNoIds(): void
    {
        $message = new ElasticsearchIndexingMessage(
            new IndexingDto([], 'foo', 'product'),
            null,
            Context::createDefaultContext()
        );

        $this->indices
            ->expects($this->once())
            ->method('exists')->willReturn(true);

        $indexer = $this->getIndexer();

        static::expectException(ElasticsearchException::class);
        static::expectExceptionMessage('Empty indexing request provided');

        $indexer($message);
    }

    public function testHandleIndexing(): void
    {
        $productDefinition = $this->createDefinition('product');
        $productDefinition->method('fetch')
            ->willReturn([
                [
                    'id' => '1',
                    'name' => 'foo',
                    'description' => 'bar',
                    'price' => 10,
                    'stock' => 100,
                    'manufacturer' => [
                        'id' => '1',
                        'name' => 'foo',
                    ],
                ],
            ]);

        $this->registry = new ElasticsearchRegistry([$productDefinition]);

        $message = new ElasticsearchIndexingMessage(
            new IndexingDto([Uuid::randomHex()], 'foo', 'product'),
            null,
            Context::createDefaultContext()
        );

        $this->indices
            ->expects($this->once())
            ->method('exists')->willReturn(true);

        $indexer = $this->getIndexer();

        $indexer($message);
    }

    public function testHandleIndexingFails(): void
    {
        $message = new ElasticsearchIndexingMessage(
            new IndexingDto([Uuid::randomHex()], 'foo', 'product'),
            null,
            Context::createDefaultContext()
        );

        $this->client->method('bulk')
            ->willReturn([
                'errors' => true,
                'items' => [
                    [
                        'index' => [
                            '_id' => '1',
                            '_index' => 'foo',
                            'status' => 200,
                        ],
                    ],
                    [
                        'index' => [
                            '_id' => '1',
                            '_index' => 'foo',
                            'status' => 400,
                            'error' => [
                                'type' => 'mapper_parsing_exception',
                                'reason' => 'failed to parse',
                            ],
                        ],
                    ],
                ],
            ]);

        $this->indices
            ->expects($this->once())
            ->method('exists')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->with('failed to parse');

        $this->helper->expects($this->once())->method('logAndThrowException')->with(ElasticsearchException::indexingError([
            [
                'index' => 'foo',
                'id' => '1',
                'type' => 'mapper_parsing_exception',
                'reason' => 'failed to parse',
            ],
        ]));

        $indexer = $this->getIndexer($logger);

        $indexer($message);
    }

    public function testIterateWithProductEntity(): void
    {
        $eventDispatched = false;
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(ElasticsearchIndexIteratorEvent::class, function () use (&$eventDispatched): void {
            $eventDispatched = true;
        });

        $indexer = $this->getIndexer(eventDispatcher: $eventDispatcher);

        $this->connection
            ->expects($this->once())
            ->method('insert')
            ->with('elasticsearch_index_task');

        $this->indexCreator
            ->method('aliasExists')
            ->willReturn(true);

        $entities = ['product'];

        $indexer->iterate(null, $entities);
        static::assertTrue($eventDispatched);
    }

    public function testIterateWithProductAndCategoryEntities(): void
    {
        $this->registry = new ElasticsearchRegistry([
            $this->createDefinition('product'),
            $this->createDefinition('category'),
        ]);

        $indexer = $this->getIndexer();

        $this->connection
            ->expects($this->exactly(2))
            ->method('insert')
            ->with('elasticsearch_index_task');

        $this->indexCreator
            ->method('aliasExists')
            ->willReturn(true);

        $entities = ['product', 'category'];

        $indexer->iterate(null, $entities);
    }

    public function testIterateLogErrorForInvalidEntity(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $indexer = $this->getIndexer($logger);

        $this->connection
            ->expects($this->once())
            ->method('insert')
            ->with('elasticsearch_index_task');

        $this->indexCreator
            ->method('aliasExists')
            ->willReturn(true);

        $this->helper->expects($this->once())->method('logAndThrowException')->with(ElasticsearchException::definitionNotFound('category'));

        $entities = ['product', 'category'];

        $indexer->iterate(null, $entities);
    }

    private function getIndexer(?LoggerInterface $logger = null, ?EventDispatcherInterface $eventDispatcher = null): ElasticsearchIndexer
    {
        $logger ??= new NullLogger();
        $eventDispatcher ??= $this->eventDispatcher;

        return new ElasticsearchIndexer(
            $this->connection,
            $this->helper,
            $this->registry,
            $this->indexCreator,
            $this->iteratorFactory,
            $this->client,
            $logger,
            $eventDispatcher,
            1,
        );
    }

    /**
     * @return AbstractElasticsearchDefinition&MockObject
     */
    private function createDefinition(string $name): AbstractElasticsearchDefinition
    {
        $es = $this->createMock(AbstractElasticsearchDefinition::class);

        $definition = $this->createMock(EntityDefinition::class);
        $definition->method('getEntityName')->willReturn($name);

        $es->method('getEntityDefinition')->willReturn($definition);

        return $es;
    }
}
