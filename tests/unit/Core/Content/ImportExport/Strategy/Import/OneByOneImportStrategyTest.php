<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\ImportExport\Strategy\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\Core\Content\ImportExport\Event\ImportExportExceptionImportRecordEvent;
use Shopware\Core\Content\ImportExport\Strategy\Import\OneByOneImportStrategy;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Content\ImportExport\Struct\Progress;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
#[CoversClass(OneByOneImportStrategy::class)]
class OneByOneImportStrategyTest extends ImportStrategyTestCase
{
    private OneByOneImportStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new OneByOneImportStrategy(
            $this->eventDispatcher,
            $this->repository
        );
    }

    #[DataProvider('importProvider')]
    public function testSuccessfulImport(Config $config, string $method): void
    {
        $record = ['some' => 'data'];

        $writeResult = new EntityWrittenContainerEvent(Context::createDefaultContext(), new NestedEventCollection(), []);

        $this->repository->expects($this->once())->method($method)->willReturn($writeResult);
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $progress = new Progress('logId', Progress::STATE_PROGRESS);

        $result = $this->strategy->import($record, [], $config, $progress, Context::createDefaultContext());

        static::assertSame([$writeResult], $result->results);
        static::assertSame([], $result->failedRecords);
        static::assertSame(1, $progress->getProcessedRecords());
    }

    public function testFailedImport(): void
    {
        $record = ['some' => 'data'];

        $writeResult = new EntityWrittenContainerEvent(Context::createDefaultContext(), new NestedEventCollection(), []);

        $this->repository->expects($this->once())->method('create')->willReturnCallback(
            function () use ($writeResult) {
                static $counter = 0;
                if ($counter++ === 0) {
                    throw new \Exception('Error');
                }

                return $writeResult;
            }
        );

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(static::isInstanceOf(ImportExportExceptionImportRecordEvent::class));

        $config = new Config(
            mapping: [],
            parameters: [
                'createEntities' => true,
                'updateEntities' => false,
            ],
            updateBy: []
        );

        $progress = new Progress('logId', Progress::STATE_PROGRESS);

        $result = $this->strategy->import($record, [], $config, $progress, Context::createDefaultContext());

        static::assertSame([], $result->results);
        static::assertSame([
            ['some' => 'data', '_error' => 'Error'],
        ], $result->failedRecords);
    }

    public function testCommit(): void
    {
        $config = new Config([], [], []);
        $progress = new Progress('logId', Progress::STATE_PROGRESS);

        $result = $this->strategy->commit($config, $progress, Context::createDefaultContext());

        static::assertSame([], $result->results);
        static::assertSame([], $result->failedRecords);
    }
}
