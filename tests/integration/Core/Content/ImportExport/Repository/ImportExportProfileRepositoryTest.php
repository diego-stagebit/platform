<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\ImportExport\Repository;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ImportExport\Exception\DeleteDefaultProfileException;
use Shopware\Core\Content\ImportExport\ImportExportProfileEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class ImportExportProfileRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $repository;

    private Connection $connection;

    private Context $context;

    protected function setUp(): void
    {
        $this->repository = static::getContainer()->get('import_export_profile.repository');
        $this->connection = static::getContainer()->get(Connection::class);
        $this->context = Context::createDefaultContext();

        // Clean up system defaults before testing.
        $this->connection->executeStatement('DELETE FROM `import_export_profile`');
    }

    public function testImportExportProfileSingleCreateSuccess(): void
    {
        $data = $this->prepareImportExportProfileTestData();

        $id = array_key_first($data);

        $this->repository->create([$data[$id]], $this->context);

        $record = $this->connection->fetchAssociative(
            'SELECT * FROM import_export_profile WHERE id = :id',
            ['id' => $id]
        );

        $translationRecord = $this->connection->fetchAssociative(
            'SELECT * FROM import_export_profile_translation WHERE import_export_profile_id = :id',
            ['id' => $id]
        );
        static::assertIsArray($translationRecord);

        $expect = $data[$id];
        static::assertIsArray($record);
        static::assertSame($id, $record['id']);
        static::assertSame($expect['technicalName'], $record['technical_name']);
        static::assertSame($expect['label'], $translationRecord['label']);
        static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
        static::assertSame($expect['sourceEntity'], $record['source_entity']);
        static::assertSame($expect['fileType'], $record['file_type']);
        static::assertSame($expect['delimiter'], $record['delimiter']);
        static::assertSame($expect['enclosure'], $record['enclosure']);
        static::assertSame(json_encode($expect['mapping'], \JSON_THROW_ON_ERROR), $record['mapping']);
    }

    public function testImportExportProfileSingleCreateMissingRequired(): void
    {
        $requiredProperties = ['sourceEntity', 'fileType'];
        $num = \count($requiredProperties);
        $data = $this->prepareImportExportProfileTestData($num);

        foreach ($requiredProperties as $property) {
            /** @var array<string, mixed> $entry */
            $entry = array_shift($data);
            unset($entry[$property]);

            try {
                $this->repository->create([$entry], $this->context);
                static::fail(\sprintf('Create without required property \'%s\'', $property));
            } catch (\Exception $e) {
                static::assertInstanceOf(WriteException::class, $e);
            }
        }
    }

    public function testImportExportProfileMultiCreateSuccess(): void
    {
        $num = 5;
        $data = $this->prepareImportExportProfileTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $records = $this->connection->fetchAllAssociative(
            'SELECT * FROM import_export_profile'
        );
        $translationRecords = $this->getTranslationRecords();

        static::assertCount($num, $records);

        foreach ($records as $record) {
            $expect = $data[$record['id']];
            static::assertSame($expect['technicalName'], $record['technical_name']);
            static::assertSame($expect['label'], $translationRecords[$record['id']]['label']);
            static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
            static::assertSame($expect['sourceEntity'], $record['source_entity']);
            static::assertSame($expect['fileType'], $record['file_type']);
            static::assertSame($expect['delimiter'], $record['delimiter']);
            static::assertSame($expect['enclosure'], $record['enclosure']);
            static::assertSame(json_encode($expect['mapping'], \JSON_THROW_ON_ERROR), $record['mapping']);
            unset($data[$record['id']]);
        }
    }

    public function testImportExportProfileMultiCreateMissingRequired(): void
    {
        $data = $this->prepareImportExportProfileTestData(2);

        $requiredProperties = ['sourceEntity', 'fileType'];
        $incompleteData = $this->prepareImportExportProfileTestData(\count($requiredProperties));

        foreach ($requiredProperties as $property) {
            /** @var array<string, mixed> $entry */
            $entry = array_shift($incompleteData);
            unset($entry[$property]);
            $data[] = $entry;
        }

        try {
            $this->repository->create(array_values($data), $this->context);
            static::fail('Create without required properties');
        } catch (WriteException $e) {
            static::assertCount(\count($requiredProperties), $e->getExceptions());
            $foundViolations = [];

            /** @var WriteConstraintViolationException $violations */
            foreach ($e->getExceptions() as $violations) {
                foreach ($violations->getViolations() as $violation) {
                    $foundViolations[] = $violation->getPropertyPath();
                }
            }

            $missingPropertyPaths = array_map(fn ($property) => '/' . $property, $requiredProperties);

            static::assertSame($missingPropertyPaths, $foundViolations);
        }
    }

    public function testImportExportProfileReadSuccess(): void
    {
        $num = 5;
        $data = $this->prepareImportExportProfileTestData($num);

        $this->repository->create(array_values($data), $this->context);

        foreach ($data as $expect) {
            $id = $expect['id'];
            /** @var ImportExportProfileEntity $importExportProfile */
            $importExportProfile = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
            static::assertSame($expect['label'], $importExportProfile->getLabel());
            static::assertSame($expect['systemDefault'], $importExportProfile->getSystemDefault());
            static::assertSame($expect['sourceEntity'], $importExportProfile->getSourceEntity());
            static::assertSame($expect['fileType'], $importExportProfile->getFileType());
            static::assertSame($expect['delimiter'], $importExportProfile->getDelimiter());
            static::assertSame($expect['enclosure'], $importExportProfile->getEnclosure());
            static::assertSame($expect['mapping'], $importExportProfile->getMapping());
            static::assertSame($expect['technicalName'], $importExportProfile->getTechnicalName());
        }
    }

    public function testImportExportProfileReadNoResult(): void
    {
        $num = 3;
        $data = $this->prepareImportExportProfileTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $result = $this->repository->search(new Criteria([Uuid::randomHex()]), $this->context);
        static::assertCount(0, $result);
    }

    public function testImportExportProfileUpdateFull(): void
    {
        $num = 5;
        $data = $this->prepareImportExportProfileTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $new_data = array_values($this->prepareImportExportProfileTestData($num, 'xxx'));
        foreach ($data as $id => $value) {
            $new_value = array_pop($new_data);
            $new_value['id'] = $value['id'];
            $data[$id] = $new_value;
        }

        $this->repository->upsert(array_values($data), $this->context);

        $records = $this->connection->fetchAllAssociative(
            'SELECT * FROM import_export_profile'
        );
        $translationRecords = $this->getTranslationRecords();

        static::assertCount($num, $records);

        foreach ($records as $record) {
            $expect = $data[$record['id']];
            static::assertSame($expect['technicalName'], $record['technical_name']);
            static::assertSame($expect['label'], $translationRecords[$record['id']]['label']);
            static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
            static::assertSame($expect['sourceEntity'], $record['source_entity']);
            static::assertSame($expect['fileType'], $record['file_type']);
            static::assertSame($expect['delimiter'], $record['delimiter']);
            static::assertSame($expect['enclosure'], $record['enclosure']);
            static::assertSame(json_encode($expect['mapping'], \JSON_THROW_ON_ERROR), $record['mapping']);
            unset($data[$record['id']]);
        }
    }

    public function testImportExportProfileUpdatePartial(): void
    {
        $upsertData = [];
        $data = $this->prepareImportExportProfileTestData();
        /** @var array<string, mixed> $last */
        $last = array_pop($data);
        $properties = array_keys($last);

        $num = \count($properties);
        $data = $this->prepareImportExportProfileTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $new_data = array_values($this->prepareImportExportProfileTestData($num, 'xxx'));
        foreach ($data as $id => $value) {
            $new_value = array_pop($new_data);
            $new_value['id'] = $value['id'];
            $data[$id] = $new_value;
            $upsertData = $data;

            // Remove property before write
            $property = array_pop($properties);
            if ($property === 'id') {
                continue;
            }
            unset($upsertData[$id][$property]);
        }

        $this->repository->upsert(array_values($upsertData), $this->context);

        $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');
        $translationRecords = $this->getTranslationRecords();

        static::assertCount($num, $records);

        foreach ($records as $record) {
            $expect = $data[$record['id']];
            static::assertSame($expect['technicalName'], $record['technical_name']);
            static::assertSame($expect['label'], $translationRecords[$record['id']]['label']);
            static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
            static::assertSame($expect['sourceEntity'], $record['source_entity']);
            static::assertSame($expect['fileType'], $record['file_type']);
            static::assertSame($expect['delimiter'], $record['delimiter']);
            static::assertSame($expect['enclosure'], $record['enclosure']);
            static::assertSame(json_encode($expect['mapping'], \JSON_THROW_ON_ERROR), $record['mapping']);
            unset($data[$record['id']]);
        }
    }

    public function testImportExportProfileDeleteNonSystemDefault(): void
    {
        $num = 2;
        $data = $this->prepareImportExportProfileTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $deleted = 0;
        foreach (array_column($data, 'id') as $id) {
            if (!$data[Uuid::fromHexToBytes($id)]['systemDefault']) {
                $this->repository->delete([['id' => $id]], $this->context);
                ++$deleted;
            }
        }

        $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');

        static::assertCount($num - $deleted, $records);
    }

    public function testImportExportProfileDeleteSystemDefault(): void
    {
        $num = 2;
        $data = $this->prepareImportExportProfileTestData($num);
        $this->repository->create(array_values($data), $this->context);

        foreach (array_column($data, 'id') as $id) {
            if ($data[Uuid::fromHexToBytes($id)]['systemDefault']) {
                try {
                    $this->repository->delete([['id' => $id]], $this->context);
                    static::fail('System defaults should not be deletable.');
                } catch (\Exception $e) {
                    static::assertInstanceOf(WriteException::class, $e);
                    static::assertInstanceOf(DeleteDefaultProfileException::class, $e->getExceptions()[0]);
                }
            }
        }

        $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');

        static::assertCount($num, $records);
    }

    public function testImportExportProfileDeleteUnknown(): void
    {
        $num = 5;
        $data = $this->prepareImportExportProfileTestData($num);
        $this->repository->create(array_values($data), $this->context);

        $ids = [];
        for ($i = 0; $i <= $num; ++$i) {
            $ids[] = ['id' => Uuid::randomHex()];
        }

        $this->repository->delete($ids, $this->context);

        $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');

        static::assertCount($num, $records);
    }

    /**
     * Prepare a defined number of test data.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function prepareImportExportProfileTestData(int $num = 1, string $add = ''): array
    {
        $data = [];
        for ($i = 1; $i <= $num; ++$i) {
            $uuid = Uuid::randomHex();

            $data[Uuid::fromHexToBytes($uuid)] = [
                'id' => $uuid,
                'technicalName' => uniqid('technical_name_'),
                'label' => \sprintf('Test label %d %s', $i, $add),
                'systemDefault' => ($i % 2 === 0),
                'sourceEntity' => \sprintf('Test entity %d %s', $i, $add),
                'fileType' => \sprintf('Test file type %d %s', $i, $add),
                'delimiter' => \sprintf('Test delimiter %d %s', $i, $add),
                'enclosure' => \sprintf('Test enclosure %d %s', $i, $add),
                'mapping' => ['Mapping ' . $i => 'Value ' . $i . $add],
            ];
        }

        return $data;
    }

    /**
     * Read out the contents of the import_export_profile_translation table
     *
     * @return array<string, array<string, string>>
     */
    protected function getTranslationRecords(): array
    {
        return array_reduce(
            $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile_translation'),
            static function ($carry, $translationRecord) {
                $carry[$translationRecord['import_export_profile_id']] = $translationRecord;

                return $carry;
            },
            []
        );
    }
}
