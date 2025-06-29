<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\ImportExport\Api;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class ImportExportProfileApiTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

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

    public function testImportExportProfileCreateSuccess(): void
    {
        // prepare test data
        $num = 3;
        $data = $this->prepareImportExportProfileTestData($num);

        // do API calls
        foreach ($data as $entry) {
            $this->getBrowser()->request('POST', $this->prepareRoute(), [], [], [], json_encode($entry, \JSON_THROW_ON_ERROR));
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        }

        // read created data from db
        $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');
        $translationRecords = $this->getTranslationRecords();

        // compare expected and resulting data
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

    public function testImportExportProfileCreateMissingRequired(): void
    {
        $requiredProperties = ['sourceEntity', 'fileType'];
        foreach ($requiredProperties as $property) {
            $entry = current($this->prepareImportExportProfileTestData());
            unset($entry[$property]);
            $this->getBrowser()->request('POST', $this->prepareRoute(), $entry);
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        }
    }

    public function testImportExportProfileList(): void
    {
        foreach ([0, 5] as $num) {
            // Create test data.
            $data = $this->prepareImportExportProfileTestData($num);
            if (!empty($data)) {
                $this->repository->create(array_values($data), $this->context);
            }

            $this->getBrowser()->request('GET', $this->prepareRoute(), [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);

            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_OK, $response->getStatusCode());
            static::assertNotFalse($response->getContent());

            $content = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

            // Prepare expected data.
            $expectData = [];
            foreach (array_values($data) as $entry) {
                $expectData[$entry['id']] = $entry;
            }

            // compare expected and resulting data
            static::assertSame($num, $content['total']);
            for ($i = 0; $i < $num; ++$i) {
                $importExportProfile = $content['data'][$i];
                $expect = $expectData[$importExportProfile['_uniqueIdentifier']];
                static::assertSame($expect['technicalName'], $importExportProfile['technicalName']);
                static::assertSame($expect['label'], $importExportProfile['label']);
                static::assertSame($expect['systemDefault'], (bool) $importExportProfile['systemDefault']);
                static::assertSame($expect['sourceEntity'], $importExportProfile['sourceEntity']);
                static::assertSame($expect['fileType'], $importExportProfile['fileType']);
                static::assertSame($expect['delimiter'], $importExportProfile['delimiter']);
                static::assertSame($expect['enclosure'], $importExportProfile['enclosure']);
                static::assertSame($expect['mapping'], $importExportProfile['mapping']);
            }
        }
    }

    public function testImportExportProfileUpdateFull(): void
    {
        // create test data
        $num = 5;
        $data = $this->prepareImportExportProfileTestData($num);
        $this->repository->create(array_values($data), $this->context);

        $ids = array_column($data, 'id');
        $data = $this->rotateTestData($data);

        $expectData = [];
        foreach ($ids as $idx => $id) {
            $technicalName = uniqid('test_name_');
            $data[$idx]['technicalName'] = $technicalName;

            $expectData[$id] = $data[$idx];
            unset($data[$idx]['id']);

            $this->getBrowser()->request('PATCH', $this->prepareRoute() . $id, [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ], json_encode($data[$idx], \JSON_THROW_ON_ERROR));
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        }

        $this->getBrowser()->request('GET', $this->prepareRoute(), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = $this->getBrowser()->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertNotFalse($response->getContent());

        $content = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // Compare expected and received data.
        static::assertSame($num, $content['total']);
        for ($i = 0; $i < $num; ++$i) {
            $importExportProfile = $content['data'][$i];
            $expect = $expectData[$importExportProfile['_uniqueIdentifier']];
            static::assertSame($expect['technicalName'], $importExportProfile['technicalName']);
            static::assertSame($expect['label'], $importExportProfile['label']);
            static::assertSame($expect['systemDefault'], (bool) $importExportProfile['systemDefault']);
            static::assertSame($expect['sourceEntity'], $importExportProfile['sourceEntity']);
            static::assertSame($expect['fileType'], $importExportProfile['fileType']);
            static::assertSame($expect['delimiter'], $importExportProfile['delimiter']);
            static::assertSame($expect['enclosure'], $importExportProfile['enclosure']);
            static::assertSame($expect['mapping'], $importExportProfile['mapping']);
        }
    }

    public function testImportExportProfileUpdatePartial(): void
    {
        // create test data
        $num = 5;
        $data = $this->prepareImportExportProfileTestData($num);
        $this->repository->create(array_values($data), $this->context);

        $ids = array_column($data, 'id');
        $data = $this->rotateTestData($data);

        /** @var array<string, mixed> $current */
        $current = current($data);
        $properties = array_keys($current);
        $expectProperties = $properties;

        $expectData = [];
        foreach ($ids as $idx => $id) {
            $technicalName = uniqid('test_name_');
            $data[$idx]['technicalName'] = $technicalName;

            $removedProperty = array_pop($properties);
            $expectData[$id] = $data[$idx];
            unset($data[$idx][$removedProperty]);
            unset($data[$idx]['id']);

            $this->getBrowser()->request('PATCH', $this->prepareRoute() . $id, [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ], json_encode($data[$idx], \JSON_THROW_ON_ERROR));
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

            $this->getBrowser()->request('GET', $this->prepareRoute() . $id, [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_OK, $response->getStatusCode());
            static::assertNotFalse($response->getContent());

            $content = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

            $importExportProfile = $content['data'];
            $expect = $expectData[$id];
            foreach ($expectProperties as $property) {
                if ($property === 'id') {
                    continue;
                }
                $currentValue = $importExportProfile[$property];
                $expectValue = $expect[$property];
                if ($property === 'systemDefault') {
                    $currentValue = (bool) $currentValue;
                }
                if ($property === $removedProperty) {
                    static::assertNotSame($expectValue, $currentValue);
                } else {
                    static::assertSame($expectValue, $currentValue);
                }
            }
        }
    }

    public function testImportExportProfileDetailSuccess(): void
    {
        // create test data
        $num = 2;
        $data = $this->prepareImportExportProfileTestData($num);
        $this->repository->create(array_values($data), $this->context);

        foreach ($data as $expect) {
            // Request details
            $this->getBrowser()->request('GET', $this->prepareRoute() . $expect['id'], [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_OK, $response->getStatusCode());
            static::assertNotFalse($response->getContent());

            // compare details with expected
            $content = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

            static::assertSame($expect['technicalName'], $content['data']['technicalName']);
            static::assertSame($expect['label'], $content['data']['label']);
            static::assertSame($expect['systemDefault'], (bool) $content['data']['systemDefault']);
            static::assertSame($expect['sourceEntity'], $content['data']['sourceEntity']);
            static::assertSame($expect['fileType'], $content['data']['fileType']);
            static::assertSame($expect['delimiter'], $content['data']['delimiter']);
            static::assertSame($expect['enclosure'], $content['data']['enclosure']);
            static::assertSame($expect['mapping'], $content['data']['mapping']);
        }
    }

    public function testImportExportProfileDetailNotFound(): void
    {
        $this->getBrowser()->request('GET', $this->prepareRoute() . Uuid::randomHex(), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = $this->getBrowser()->getResponse();
        static::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testImportExportProfileSearch(): void
    {
        $data = $this->prepareImportExportProfileTestData(2);

        // Prepare invalid search data
        /** @var array<string, mixed> $invalidData */
        $invalidData = array_pop($data);

        // Prepare valid search data
        $this->repository->create(array_values($data), $this->context);
        /** @var array<string, mixed> $searchData */
        $searchData = array_pop($data);

        $searchExcludes = ['mapping'];
        $filter = [];
        foreach ($searchData as $key => $value) {
            if (!\in_array($key, $searchExcludes, true)) {
                // Search call without result
                $filter['filter'][$key] = $invalidData[$key];
                $this->getBrowser()->request('POST', $this->prepareRoute(true), [], [], [
                    'HTTP_ACCEPT' => 'application/json',
                ], json_encode($filter, \JSON_THROW_ON_ERROR));
                $response = $this->getBrowser()->getResponse();
                static::assertSame(Response::HTTP_OK, $response->getStatusCode());
                static::assertNotFalse($response->getContent());
                $content = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
                static::assertSame(0, $content['total']);

                // Search call
                $filter['filter'][$key] = $value;
                $this->getBrowser()->request('POST', $this->prepareRoute(true), [], [], [
                    'HTTP_ACCEPT' => 'application/json',
                ], json_encode($filter, \JSON_THROW_ON_ERROR));
                $response = $this->getBrowser()->getResponse();
                static::assertSame(Response::HTTP_OK, $response->getStatusCode());
                static::assertNotFalse($response->getContent());
                $content = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
                static::assertSame(1, $content['total']);
            }
        }
    }

    public function testImportExportProfileDelete(): void
    {
        // create test data
        $num = 2;
        $data = $this->prepareImportExportProfileTestData($num);
        $this->repository->create(array_values($data), $this->context);

        $deleted = 0;
        foreach ($data as $profile) {
            $deleteId = $profile['id'];

            // Test request
            $this->getBrowser()->request('GET', $this->prepareRoute() . $deleteId, [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_OK, $response->getStatusCode());

            // Delete call with invalid id.
            $this->getBrowser()->request('DELETE', $this->prepareRoute() . Uuid::randomHex(), [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);
            $response = $this->getBrowser()->getResponse();
            static::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
            $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');
            static::assertCount($num - $deleted, $records);

            // Delete call with valid id.
            $this->getBrowser()->request('DELETE', $this->prepareRoute() . $deleteId, [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]);
            $response = $this->getBrowser()->getResponse();

            if ($profile['systemDefault']) {
                static::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
            } else {
                static::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
                ++$deleted;
            }
            $records = $this->connection->fetchAllAssociative('SELECT * FROM import_export_profile');
            static::assertCount($num - $deleted, $records);
        }
    }

    protected function prepareRoute(bool $search = false): string
    {
        $addPath = '';
        if ($search) {
            $addPath = '/search';
        }

        return '/api' . $addPath . '/import-export-profile/';
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
                'technicalName' => uniqid('test_name_'),
                'label' => \sprintf('Test label %d %s', $i, $add),
                'systemDefault' => (($i % 2 === 0) ? true : false),
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
     * @param array<string, array<string, mixed>> $data
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rotateTestData(array $data): array
    {
        /** @var array<string, mixed> $first */
        $first = array_shift($data);

        $data[] = $first;

        return array_values($data);
    }

    /**
     * Read out the contents of the import_export_profile_translation table
     *
     * @return array<string, array<string, mixed>>
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
