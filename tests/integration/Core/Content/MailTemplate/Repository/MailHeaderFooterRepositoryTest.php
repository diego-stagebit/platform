<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\MailTemplate\Repository;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\MailTemplate\Aggregate\MailHeaderFooter\MailHeaderFooterEntity;
use Shopware\Core\Content\Test\Media\MediaFixtures;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[Package('after-sales')]
class MailHeaderFooterRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;
    use MediaFixtures;

    private EntityRepository $repository;

    private Connection $connection;

    private Context $context;

    protected function setUp(): void
    {
        $this->repository = static::getContainer()->get('mail_header_footer.repository');
        $this->connection = static::getContainer()->get(Connection::class);
        $this->context = Context::createDefaultContext();

        try {
            $this->connection->executeStatement('DELETE FROM mail_header_footer');
        } catch (\Exception $e) {
            static::fail('Failed to remove testdata: ' . $e->getMessage());
        }
    }

    /**
     * Test single CREATE
     */
    public function testMailHeaderFooterSingleCreate(): void
    {
        $data = $this->prepareHeaderFooterTestData();

        $id = array_key_first($data);

        $this->repository->create([$data[$id]], $this->context);

        $record = $this->connection->fetchAssociative(
            'SELECT *
             FROM mail_header_footer mhf
             JOIN mail_header_footer_translation mhft
                 ON mhf.id=mhft.mail_header_footer_id
             WHERE id = :id',
            ['id' => $id]
        );

        $expect = $data[$id];
        static::assertIsArray($record);
        static::assertSame($id, $record['id']);
        static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
        static::assertSame($expect['name'], $record['name']);
        static::assertSame($expect['description'], $record['description']);
        static::assertSame($expect['headerHtml'], $record['header_html']);
        static::assertSame($expect['headerPlain'], $record['header_plain']);
        static::assertSame($expect['footerHtml'], $record['footer_html']);
        static::assertSame($expect['footerPlain'], $record['footer_plain']);
    }

    /**
     * Test multiple CREATE
     */
    public function testMailHeaderFooterMultiCreate(): void
    {
        $num = 10;
        $data = $this->prepareHeaderFooterTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $records = $this->connection->fetchAllAssociative(
            'SELECT *
             FROM mail_header_footer mhf
             JOIN mail_header_footer_translation mhft
                 ON mhf.id=mhft.mail_header_footer_id'
        );

        static::assertCount($num, $records);

        foreach ($records as $record) {
            $expect = $data[$record['id']];
            static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
            static::assertSame($expect['name'], $record['name']);
            static::assertSame($expect['description'], $record['description']);
            static::assertSame($expect['headerHtml'], $record['header_html']);
            static::assertSame($expect['headerPlain'], $record['header_plain']);
            static::assertSame($expect['footerHtml'], $record['footer_html']);
            static::assertSame($expect['footerPlain'], $record['footer_plain']);
            unset($data[$record['id']]);
        }
    }

    /**
     * Test READ
     */
    public function testMailHeaderFooterRead(): void
    {
        $num = 10;
        $data = $this->prepareHeaderFooterTestData($num);

        $this->repository->create(array_values($data), $this->context);

        foreach ($data as $expect) {
            $id = $expect['id'];
            $mailHeaderFooter = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
            static::assertInstanceOf(MailHeaderFooterEntity::class, $mailHeaderFooter);
            static::assertSame($expect['systemDefault'], $mailHeaderFooter->getSystemDefault());
            static::assertSame($expect['name'], $mailHeaderFooter->getName());
            static::assertSame($expect['description'], $mailHeaderFooter->getDescription());
            static::assertSame($expect['headerHtml'], $mailHeaderFooter->getHeaderHtml());
            static::assertSame($expect['headerPlain'], $mailHeaderFooter->getHeaderPlain());
            static::assertSame($expect['footerHtml'], $mailHeaderFooter->getFooterHtml());
            static::assertSame($expect['footerPlain'], $mailHeaderFooter->getFooterPlain());
        }
    }

    /**
     * Test UPDATE
     */
    public function testMailHeaderFooterUpdate(): void
    {
        $num = 10;
        $data = $this->prepareHeaderFooterTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $new_data = array_values($this->prepareHeaderFooterTestData($num, 'xxx'));
        foreach ($data as $id => $value) {
            $new_value = array_pop($new_data);
            $new_value['id'] = $value['id'];
            $data[$id] = $new_value;
        }

        $this->repository->upsert(array_values($data), $this->context);

        $records = $this->connection->fetchAllAssociative(
            'SELECT *
             FROM mail_header_footer mhf
             JOIN mail_header_footer_translation mhft ON mhf.id=mhft.mail_header_footer_id'
        );

        static::assertCount($num, $records);

        foreach ($records as $record) {
            $expect = $data[$record['id']];
            static::assertSame($expect['systemDefault'], (bool) $record['system_default']);
            static::assertSame($expect['name'], $record['name']);
            static::assertSame($expect['description'], $record['description']);
            static::assertSame($expect['headerHtml'], $record['header_html']);
            static::assertSame($expect['headerPlain'], $record['header_plain']);
            static::assertSame($expect['footerHtml'], $record['footer_html']);
            static::assertSame($expect['footerPlain'], $record['footer_plain']);
            unset($data[$record['id']]);
        }
    }

    /**
     * Test DELETE
     */
    public function testMailHeaderFooterDelete(): void
    {
        $num = 10;
        $data = $this->prepareHeaderFooterTestData($num);

        $this->repository->create(array_values($data), $this->context);

        $ids = [];
        foreach (array_column($data, 'id') as $id) {
            $ids[] = ['id' => $id];
        }

        $this->repository->delete($ids, $this->context);

        $records = $this->connection->fetchAllAssociative(
            'SELECT *
             FROM mail_header_footer mhf
             JOIN mail_header_footer_translation mhft ON mhf.id=mhft.mail_header_footer_id'
        );

        static::assertCount(0, $records);
    }

    /**
     * Prepare a defined number of test data.
     *
     * @return array<string, array<string, mixed>>
     */
    private function prepareHeaderFooterTestData(int $num = 1, string $add = ''): array
    {
        $data = [];
        for ($i = 1; $i <= $num; ++$i) {
            $uuid = Uuid::randomHex();

            $data[Uuid::fromHexToBytes($uuid)] = [
                'id' => $uuid,
                'systemDefault' => $i % 2 !== 0,
                'name' => \sprintf('Test-Template %d %s', $i, $add),
                'description' => \sprintf('John Doe %d %s', $i, $add),
                'headerPlain' => \sprintf('Test header 123 %d %s', $i, $add),
                'headerHtml' => \sprintf('<h1>Test header %d %s </h1>', $i, $add),
                'footerPlain' => \sprintf('Test footer 123 %d %s', $i, $add),
                'footerHtml' => \sprintf('<h1>Test footer %d %s </h1>', $i, $add),
            ];
        }

        return $data;
    }
}
