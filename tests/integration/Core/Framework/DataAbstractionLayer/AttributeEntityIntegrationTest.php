<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\AttributeEntityCompiler;
use Shopware\Core\Framework\DataAbstractionLayer\AttributeEntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\AttributeMappingDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\AttributeTranslationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldType\DateInterval;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Test\TestCaseBase\BasicTestDataBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\AttributeEntity;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\AttributeEntityCollection;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\AttributeEntityInvalidEnum;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\AttributeEntityUnionEnum;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\AttributeEntityWithHydrator;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\DummyHydrator;
use Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\StringEnum;

/**
 * @internal
 */
class AttributeEntityIntegrationTest extends TestCase
{
    use BasicTestDataBehaviour;
    use DatabaseTransactionBehaviour;
    use KernelTestBehaviour;

    protected function setUp(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $connection->commit();

        $sql = file_get_contents(__DIR__ . '/fixture/schema.sql');
        static::assertIsString($sql);
        try {
            $connection->executeStatement($sql);
        } catch (\Exception $e) {
            // restart transaction, otherwise follow-up tests will fail
            $connection->beginTransaction();
            static::fail($e->getMessage());
        }

        $connection->beginTransaction();
    }

    public function testDefinitionsExist(): void
    {
        // registered in config/services_test.xml
        static::assertTrue(static::getContainer()->has('attribute_entity.repository'));
        static::assertTrue(static::getContainer()->has('attribute_entity.definition'));

        static::assertTrue(static::getContainer()->has('attribute_entity_agg.repository'));
        static::assertTrue(static::getContainer()->has('attribute_entity_agg.definition'));

        static::assertTrue(static::getContainer()->has('attribute_entity_with_hydrator.repository'));
        static::assertTrue(static::getContainer()->has('attribute_entity_with_hydrator.definition'));

        static::assertTrue(static::getContainer()->has('attribute_entity_currency.definition'));

        static::assertTrue(static::getContainer()->has('attribute_entity_translation.repository'));
        static::assertTrue(static::getContainer()->has('attribute_entity_translation.definition'));

        static::assertTrue(static::getContainer()->has('my_own_mapping_table_name.definition'));

        static::assertInstanceOf(AttributeEntityDefinition::class, static::getContainer()->get('attribute_entity.definition'));
        static::assertSame(AttributeEntityCollection::class, static::getContainer()->get('attribute_entity.definition')->getCollectionClass());

        static::assertInstanceOf(AttributeEntityDefinition::class, static::getContainer()->get('attribute_entity_agg.definition'));
        static::assertSame(EntityCollection::class, static::getContainer()->get('attribute_entity_agg.definition')->getCollectionClass());

        static::assertInstanceOf(AttributeEntityDefinition::class, static::getContainer()->get('attribute_entity_with_hydrator.definition'));
        static::assertSame(EntityCollection::class, static::getContainer()->get('attribute_entity_with_hydrator.definition')->getCollectionClass());
        $definition = static::getContainer()->get('attribute_entity_with_hydrator.definition');
        static::assertInstanceOf(EntityDefinition::class, $definition);
        static::assertSame(DummyHydrator::class, $definition->getHydratorClass());

        static::assertInstanceOf(AttributeMappingDefinition::class, static::getContainer()->get('attribute_entity_currency.definition'));
        static::assertInstanceOf(AttributeTranslationDefinition::class, static::getContainer()->get('attribute_entity_translation.definition'));

        static::assertInstanceOf(EntityRepository::class, static::getContainer()->get('attribute_entity.repository'));
        static::assertInstanceOf(EntityRepository::class, static::getContainer()->get('attribute_entity_currency.repository'));
        static::assertInstanceOf(EntityRepository::class, static::getContainer()->get('attribute_entity_agg.repository'));
        static::assertInstanceOf(EntityRepository::class, static::getContainer()->get('attribute_entity_translation.repository'));
    }

    public function testAssociationDefinitions(): void
    {
        $definition = static::getContainer()->get('attribute_entity_agg.definition');

        static::assertInstanceOf(AttributeEntityDefinition::class, $definition);
        static::assertNotNull($definition->getFields()->get('ownColumn'));
        static::assertNotNull($definition->getFields()->get('attributeEntity'));

        $field = $definition->getFields()->get('ownColumn');
        static::assertInstanceOf(ManyToOneAssociationField::class, $field);
        static::assertSame('attribute_entity_id', $field->getStorageName());
        static::assertSame('id', $field->getReferenceField());
        static::assertSame('attribute_entity', $field->getReferenceEntity());

        $field = $definition->getFields()->get('attributeEntity');
        static::assertInstanceOf(ManyToOneAssociationField::class, $field);
        static::assertSame('attribute_entity_id', $field->getStorageName());
        static::assertSame('id', $field->getReferenceField());
        static::assertSame('attribute_entity', $field->getReferenceEntity());
    }

    public function testCrudRoot(): void
    {
        $ids = new IdsCollection();

        $context = Context::createDefaultContext();

        $result = $this->repository('attribute_entity')->create([
            [
                'id' => $ids->create('first-key'),
                'string' => 'string',
                'emptyString' => '',
                'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
                'transString' => 'transString',
                'differentName' => 'storageString',
            ],
        ], $context);

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));

        $written = $result->getPrimaryKeys('attribute_entity');
        static::assertContains($ids->get('first-key'), $written);

        $search = $this->repository('attribute_entity')
            ->search(new Criteria($ids->getList(['first-key'])), $context);

        static::assertCount(1, $search);
        static::assertTrue($search->has($ids->get('first-key')));

        $record = $search->get($ids->get('first-key'));

        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertSame($ids->get('first-key'), $record->id);
        static::assertSame('string', $record->string);
        static::assertSame('<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>', $record->htmlString);
        static::assertSame('transString', $record->getTranslation('transString'));
        static::assertSame('storageString', $record->differentName);

        $result = $this->repository('attribute_entity')->update([
            [
                'id' => $ids->get('first-key'),
                'string' => 'string-updated',
                'transString' => 'transString-updated',
                'differentName' => 'storageString-updated',
            ],
        ], $context);

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));

        $written = $result->getPrimaryKeys('attribute_entity');
        static::assertContains($ids->get('first-key'), $written);

        $search = $this->repository('attribute_entity')
            ->search(new Criteria($ids->getList(['first-key'])), $context);

        static::assertCount(1, $search);
        static::assertTrue($search->has($ids->get('first-key')));

        $record = $search->get($ids->get('first-key'));

        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertSame($ids->get('first-key'), $record->id);
        static::assertSame('string-updated', $record->string);
        static::assertSame('transString-updated', $record->getTranslation('transString'));
        static::assertSame('storageString-updated', $record->differentName);

        $result = $this->repository('attribute_entity')->delete([
            ['id' => $ids->get('first-key')],
        ], $context);

        static::assertNotEmpty($result->getDeletedPrimaryKeys('attribute_entity'));

        $deleted = $result->getDeletedPrimaryKeys('attribute_entity');
        static::assertContains($ids->get('first-key'), $deleted);

        $search = $this->repository('attribute_entity')
            ->search(new Criteria($ids->getList(['first-key'])), $context);

        static::assertCount(0, $search);
    }

    public function testScalarValues(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'emptyString' => '',
            'text' => 'text',
            'int' => 1,
            'float' => 1.1,
            'bool' => true,
            'datetime' => new \DateTimeImmutable('2020-01-01 15:15:15'),
            'date' => new \DateTimeImmutable('2020-01-01 00:00:00'),
            'dateInterval' => new \DateInterval('P1D'),
            'timeZone' => 'Europe/Berlin',
            'enum' => 'b',
            'json' => ['key' => 'value'],
            'serialized' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 1, 'net' => 1, 'linked' => true],
            ],
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 1, 'net' => 1, 'linked' => true],
            ],
            'differentName' => 'string',
            'transString' => 'string',
            'transText' => 'text',
            'transInt' => 1,
            'transFloat' => 1.1,
            'transBool' => true,
            'transDatetime' => new \DateTimeImmutable('2020-01-01 15:15:15'),
            'transDate' => new \DateTimeImmutable('2020-01-01 00:00:00'),
            'transDateInterval' => new \DateInterval('P1D'),
            'transTimeZone' => 'Europe/Berlin',
            'transJson' => ['key' => 'value'],
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        $written = $result->getPrimaryKeys('attribute_entity');
        static::assertContains($ids->get('first-key'), $written);

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        static::assertCount(1, $search);
        static::assertTrue($search->has($ids->get('first-key')));

        $record = $search->get($ids->get('first-key'));

        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertSame('string', $record->string);
        static::assertSame('', $record->emptyString);
        static::assertSame('text', $record->text);
        static::assertSame(1, $record->int);
        static::assertSame(1.1, $record->float);
        static::assertTrue($record->bool);
        static::assertSame((new \DateTimeImmutable('2020-01-01 15:15:15'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $record->datetime?->format(Defaults::STORAGE_DATE_TIME_FORMAT));
        static::assertSame((new \DateTimeImmutable('2020-01-01 00:00:00'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $record->date?->format(Defaults::STORAGE_DATE_TIME_FORMAT));
        static::assertSame((new DateInterval('P1D'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $record->dateInterval?->format(Defaults::STORAGE_DATE_TIME_FORMAT));
        static::assertSame('Europe/Berlin', $record->timeZone);
        static::assertSame(StringEnum::B, $record->enum);
        static::assertSame(['key' => 'value'], $record->json);
        static::assertEquals(
            new PriceCollection([new Price(Defaults::CURRENCY, 1, 1, true)]),
            $record->serialized
        );
        static::assertEquals(
            new PriceCollection([new Price(Defaults::CURRENCY, 1, 1, true)]),
            $record->price
        );

        static::assertSame('string', $record->transString);
        static::assertSame('text', $record->transText);
        static::assertSame(1, $record->transInt);
        static::assertSame(1.1, $record->transFloat);
        static::assertTrue($record->transBool);
        static::assertSame((new \DateTimeImmutable('2020-01-01 15:15:15'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $record->transDatetime?->format(Defaults::STORAGE_DATE_TIME_FORMAT));
        static::assertSame((new \DateTimeImmutable('2020-01-01 00:00:00'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $record->transDate?->format(Defaults::STORAGE_DATE_TIME_FORMAT));
        static::assertSame((new DateInterval('P1D'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $record->transDateInterval?->format(Defaults::STORAGE_DATE_TIME_FORMAT));
        static::assertSame('Europe/Berlin', $record->transTimeZone);
        static::assertSame(['key' => 'value'], $record->transJson);
        static::assertSame('string', $record->differentName);

        $json = $record->jsonSerialize();
        unset($json['extensions']);

        static::assertEquals([
            '_uniqueIdentifier' => $ids->get('first-key'),
            'versionId' => null,
            'translated' => $record->getTranslated(),
            'createdAt' => $record->getCreatedAt()?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'updatedAt' => null,
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'text' => 'text',
            'int' => 1,
            'float' => 1.1,
            'bool' => true,
            'datetime' => $record->datetime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'autoIncrement' => 1,
            'enum' => StringEnum::B,
            'json' => ['key' => 'value'],
            'date' => $record->date->format(\DateTimeInterface::RFC3339_EXTENDED),
            'dateInterval' => new DateInterval('P1D'),
            'timeZone' => 'Europe/Berlin',
            'serialized' => new PriceCollection([new Price(Defaults::CURRENCY, 1, 1, true)]),
            'price' => new PriceCollection([new Price(Defaults::CURRENCY, 1, 1, true)]),
            'transString' => 'string',
            'transText' => 'text',
            'transInt' => 1,
            'transFloat' => 1.1,
            'transBool' => true,
            'transDatetime' => $record->transDatetime->format(\DateTimeInterface::RFC3339_EXTENDED),
            'transJson' => ['key' => 'value'],
            'transDate' => $record->transDate->format(\DateTimeInterface::RFC3339_EXTENDED),
            'transDateInterval' => new DateInterval('P1D'),
            'transTimeZone' => 'Europe/Berlin',
            'differentName' => 'string',
            'currencyId' => null,
            'stateId' => null,
            'followId' => null,
            'currency' => null,
            'follow' => null,
            'state' => null,
            'aggs' => null,
            'currencies' => null,
            'orders' => null,
            'translations' => null,
            'customFields' => null,
            'emptyString' => '',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'ownMapping' => [],
        ], $json);
    }

    public function testInvalidEnumsFail(): void
    {
        $attributeCompiler = new AttributeEntityCompiler();
        try {
            $attributeCompiler->compile(AttributeEntityUnionEnum::class);
        } catch (\Throwable $e) {
            static::assertInstanceOf(DataAbstractionLayerException::class, $e);
            static::assertSame('Expected "enum" to be a BackedEnum. Got "Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\StringEnum&Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\fixture\IntEnum" instead.', $e->getMessage());
        }

        try {
            $attributeCompiler->compile(AttributeEntityInvalidEnum::class);
        } catch (\Throwable $e) {
            static::assertInstanceOf(DataAbstractionLayerException::class, $e);
            static::assertSame('Expected "enum" to be a BackedEnum. Got "string" instead.', $e->getMessage());
        }
    }

    public function testOneToOne(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'transString' => 'transString',
            'follow' => self::currency($ids->get('currency-1'), 'ABC'),
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        static::assertNotEmpty($result->getPrimaryKeys('currency'));
        static::assertContains($ids->get('currency-1'), $result->getPrimaryKeys('currency'));

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->follow);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('follow');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->follow);
        static::assertSame($ids->get('currency-1'), $record->follow->getId());

        // test on delete set null
        static::getContainer()->get('currency.repository')->delete([
            ['id' => $ids->get('currency-1')],
        ], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('follow');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->follow);
        static::assertNull($record->followId);
    }

    public function testOneToMany(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'transString' => 'transString',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'aggs' => [
                ['id' => $ids->get('agg-1'), 'number' => 'agg-1'],
                ['id' => $ids->get('agg-2'), 'number' => 'agg-2'],
            ],
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity_agg'));
        static::assertContains($ids->get('agg-1'), $result->getPrimaryKeys('attribute_entity_agg'));
        static::assertContains($ids->get('agg-2'), $result->getPrimaryKeys('attribute_entity_agg'));

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->aggs);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('aggs');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->aggs);
        static::assertCount(2, $record->aggs);
        static::assertArrayHasKey($ids->get('agg-1'), $record->aggs);
        static::assertArrayHasKey($ids->get('agg-2'), $record->aggs);

        $this->repository('attribute_entity_agg')->delete([
            ['id' => $ids->get('agg-1')],
        ], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('aggs');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->aggs);
        static::assertCount(1, $record->aggs);
        static::assertArrayHasKey($ids->get('agg-2'), $record->aggs);

        // test cascade delete
        $deleted = $this->repository('attribute_entity')->delete([
            ['id' => $ids->get('first-key')],
        ], Context::createDefaultContext());

        static::assertContains($ids->get('first-key'), $deleted->getDeletedPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('agg-2'), $deleted->getDeletedPrimaryKeys('attribute_entity_agg'));
    }

    public function testManyToOne(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'transString' => 'transString',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'currency' => self::currency($ids->get('currency-1'), 'ABC'),
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        static::assertNotEmpty($result->getPrimaryKeys('currency'));
        static::assertContains($ids->get('currency-1'), $result->getPrimaryKeys('currency'));

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->currency);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('currency');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->currency);
        static::assertSame($ids->get('currency-1'), $record->currency->getId());

        // set currencyId to null
        $result = $this->repository('attribute_entity')->update([
            [
                'id' => $ids->get('first-key'),
                'currencyId' => null,
            ],
        ], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('currency');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->currency);

        // set currencyId again
        $result = $this->repository('attribute_entity')->update([
            [
                'id' => $ids->get('first-key'),
                'currencyId' => $ids->get('currency-1'),
            ],
        ], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('currency');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->currency);
        static::assertSame($ids->get('currency-1'), $record->currency->getId());

        // test restrict delete
        $this->expectException(ForeignKeyConstraintViolationException::class);

        static::getContainer()->get('currency.repository')->delete([
            ['id' => $ids->get('currency-1')],
        ], Context::createDefaultContext());
    }

    public function testManyToMany(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'transString' => 'transString',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'currencies' => [
                self::currency($ids->get('currency-1'), 'ABC'),
                self::currency($ids->get('currency-2'), 'DEF'),
            ],
        ];

        $result = $this->repository('attribute_entity')
            ->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        static::assertNotEmpty($result->getPrimaryKeys('currency'));
        static::assertContains($ids->get('currency-1'), $result->getPrimaryKeys('currency'));
        static::assertContains($ids->get('currency-2'), $result->getPrimaryKeys('currency'));

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->currencies);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('currencies');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->currencies);
        static::assertCount(2, $record->currencies);
        static::assertArrayHasKey($ids->get('currency-1'), $record->currencies);
        static::assertArrayHasKey($ids->get('currency-2'), $record->currencies);

        $this->repository('attribute_entity_currency')->delete([
            ['attributeEntityId' => $ids->get('first-key'), 'currencyId' => $ids->get('currency-1')],
        ], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('currencies');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->currencies);
        static::assertCount(1, $record->currencies);
        static::assertArrayHasKey($ids->get('currency-2'), $record->currencies);
    }

    public function testState(): void
    {
        $ids = new IdsCollection();

        $stateId = $this->getStateMachineState(OrderStates::STATE_MACHINE, OrderStates::STATE_COMPLETED);
        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'transString' => 'transString',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'stateId' => $stateId,
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $search = $this->repository('attribute_entity')
                       ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->state);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('state');
        $search = $this->repository('attribute_entity')
                       ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->state);
        static::assertSame($stateId, $record->state->getId());

        // set stateId to null
        $result = $this->repository('attribute_entity')->update([
            [
                'id' => $ids->get('first-key'),
                'stateId' => null,
            ],
        ], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('state');
        $search = $this->repository('attribute_entity')
                       ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNull($record->state);

        // set stateId again
        $result = $this->repository('attribute_entity')->update([
            [
                'id' => $ids->get('first-key'),
                'stateId' => $stateId,
            ],
        ], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('state');
        $search = $this->repository('attribute_entity')
                       ->search($criteria, Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertNotNull($record->state);
        static::assertSame($stateId, $record->state->getId());
    }

    public function testTranslations(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'transString' => [
                'en-GB' => 'transString',
                'de-DE' => 'transString-de',
            ],
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $context = Context::createDefaultContext();
        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), $context);

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertSame('transString', $record->getTranslation('transString'));
        // translation association was not loaded in the criteria
        static::assertEmpty($record->translations);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('translations');
        $languageContext = new Context(
            $context->getSource(),
            $context->getRuleIds(),
            $context->getCurrencyId(),
            [$this->getDeDeLanguageId(), Defaults::LANGUAGE_SYSTEM],
        );
        $search = $this->repository('attribute_entity')
            ->search($criteria, $languageContext);

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertSame('transString-de', $record->getTranslation('transString'));
        $translations = $record->translations ?? [];
        static::assertCount(2, $translations);

        foreach ($translations as $translation) {
            static::assertInstanceOf(ArrayEntity::class, $translation);
            if ($translation->get('languageId') === Defaults::LANGUAGE_SYSTEM) {
                static::assertSame('transString', $translation->get('transString'));
            } else {
                static::assertSame('transString-de', $translation->get('transString'));
            }
        }
    }

    public function testCustomFields(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'emptyString' => '',
            'transString' => 'transString',
        ];

        $result = $this->repository('attribute_entity')->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        $context = Context::createDefaultContext();
        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), $context);

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertEmpty($record->getCustomFields());

        $this->repository('attribute_entity')->update([
            [
                'id' => $ids->get('first-key'),
                'customFields' => [
                    'foo' => 'bar',
                    'bar' => 'baz',
                ],
            ],
        ], Context::createDefaultContext());

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), $context);

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntity::class, $record);
        static::assertEquals(['bar' => 'baz', 'foo' => 'bar'], $record->getCustomFields());
        static::assertSame('bar', $record->getCustomFieldsValue('foo'));
        static::assertSame('baz', $record->getCustomFieldsValue('bar'));
    }

    public function testManyToManyVersioned(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'string' => 'string',
            'emptyString' => '',
            'transString' => 'transString',
            'htmlString' => '<p class="text-size-lg">Awesome string with <strong>HTML</strong>!</p>',
            'orders' => [
                self::order($ids->get('order-1'), $this->getStateMachineState(), $this->getValidCountryId()),
                self::order($ids->get('order-2'), $this->getStateMachineState(), $this->getValidCountryId()),
            ],
        ];

        $result = $this->repository('attribute_entity')
            ->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity'));

        static::assertNotEmpty($result->getPrimaryKeys('order'));
        static::assertContains($ids->get('order-1'), $result->getPrimaryKeys('order'));
        static::assertContains($ids->get('order-2'), $result->getPrimaryKeys('order'));

        $search = $this->repository('attribute_entity')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        /** @var AttributeEntity $record */
        $record = $search->get($ids->get('first-key'));
        static::assertNull($record->orders);

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('orders');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        /** @var AttributeEntity $record */
        $record = $search->get($ids->get('first-key'));
        static::assertNotNull($record->orders);
        static::assertCount(2, $record->orders);
        static::assertArrayHasKey($ids->get('order-1'), $record->orders);
        static::assertArrayHasKey($ids->get('order-2'), $record->orders);

        $this->repository('attribute_entity_order')->delete([
            ['attributeEntityId' => $ids->get('first-key'), 'orderId' => $ids->get('order-1')],
        ], Context::createDefaultContext());

        $criteria = new Criteria([$ids->get('first-key')]);
        $criteria->addAssociation('orders');
        $search = $this->repository('attribute_entity')
            ->search($criteria, Context::createDefaultContext());

        /** @var AttributeEntity $record */
        $record = $search->get($ids->get('first-key'));
        static::assertNotNull($record->orders);
        static::assertCount(1, $record->orders);
        static::assertArrayHasKey($ids->get('order-2'), $record->orders);
    }

    public function testHydrator(): void
    {
        $ids = new IdsCollection();

        $data = [
            'id' => $ids->get('first-key'),
            'number' => 'number',
        ];

        $result = $this->repository('attribute_entity_with_hydrator')
            ->create([$data], Context::createDefaultContext());

        static::assertNotEmpty($result->getPrimaryKeys('attribute_entity_with_hydrator'));
        static::assertContains($ids->get('first-key'), $result->getPrimaryKeys('attribute_entity_with_hydrator'));

        $search = $this->repository('attribute_entity_with_hydrator')
            ->search(new Criteria([$ids->get('first-key')]), Context::createDefaultContext());

        $record = $search->get($ids->get('first-key'));
        static::assertInstanceOf(AttributeEntityWithHydrator::class, $record);
        static::assertSame('code-number', $record->number);
    }

    private function repository(string $entity): EntityRepository
    {
        $repository = static::getContainer()->get($entity . '.repository');
        static::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private static function currency(string $id, string $iso): array
    {
        return [
            'id' => $id,
            'name' => 'currency-' . $id,
            'factor' => 1.5,
            'symbol' => '€',
            'isoCode' => $iso,
            'shortName' => 'Euro',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function order(string $id, string $stateId, string $countryId): array
    {
        $addressId = (new IdsCollection())->get('address-1');

        return [
            'id' => $id,
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'orderDateTime' => '2024-05-06 12:34:56',
            'price' => new CartPrice(10, 10, 10, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_NET),
            'shippingCosts' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'stateId' => $stateId,
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'billingAddressId' => $addressId,
            'addresses' => [
                [
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'street' => 'Main Street',
                    'zipcode' => '59438-0403',
                    'city' => 'City',
                    'countryId' => $countryId,
                    'id' => $addressId,
                ],
            ],
        ];
    }
}
