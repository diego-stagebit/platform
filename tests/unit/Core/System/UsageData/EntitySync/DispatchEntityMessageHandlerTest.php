<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\System\UsageData\EntitySync;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityWriteGateway;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BlobField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyIdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\VersionField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\FieldSerializerInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\UsageData\Consent\ConsentService;
use Shopware\Core\System\UsageData\EntitySync\DispatchEntityMessage;
use Shopware\Core\System\UsageData\EntitySync\DispatchEntityMessageHandler;
use Shopware\Core\System\UsageData\EntitySync\EntityDispatcher;
use Shopware\Core\System\UsageData\EntitySync\Operation;
use Shopware\Core\System\UsageData\Services\EntityDefinitionService;
use Shopware\Core\System\UsageData\Services\ManyToManyAssociationService;
use Shopware\Core\System\UsageData\Services\ShopIdProvider;
use Shopware\Core\System\UsageData\Services\UsageDataAllowListService;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Shopware\Core\Test\Stub\Doctrine\FakeResultFactory;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Tests\Unit\Core\System\UsageData\Services\ManyToManyMappingEntityDefinition;
use Shopware\Tests\Unit\Core\System\UsageData\Services\MockEntityDefinition;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[Package('data-services')]
#[CoversClass(DispatchEntityMessageHandler::class)]
class DispatchEntityMessageHandlerTest extends TestCase
{
    public function testIgnoresMessageIfEntityDefinitionIsNotFound(): void
    {
        $connection = $this->createConnectionMock();
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->never())
            ->method('dispatch');

        static::expectException(UnrecoverableMessageHandlingException::class);
        static::expectExceptionMessage('No allowed entity definition found. Skipping dispatching of entity sync message. Entity: non_existing_entity, Operation: create');

        $consentService = $this->createMock(ConsentService::class);

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            new EntityDefinitionService([], new UsageDataAllowListService()),
            new ManyToManyAssociationService($connection),
            new UsageDataAllowListService(),
            $connection,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        $handler(new DispatchEntityMessage(
            'non_existing_entity',
            Operation::CREATE,
            new \DateTimeImmutable(),
            [],
            'current-shop-id'
        ));
    }

    public function testIgnoresMessageIfApprovalWasNeverGiven(): void
    {
        $connection = $this->createConnectionMock();
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->never())
            ->method('dispatch');

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('getLastConsentIsAcceptedDate')
            ->willReturn(null);

        $definition = new SyncEntityDefinition();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                return new FieldCollection($definition->getFields());
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            new EntityDefinitionService(
                [$definition],
                $usageDataAllowListService,
            ),
            new ManyToManyAssociationService($connection),
            $usageDataAllowListService,
            $connection,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        static::expectException(UnrecoverableMessageHandlingException::class);
        static::expectExceptionMessage(\sprintf('No approval date found. Skipping dispatching of entity sync message. Entity: %s, Operation: create', $definition->getEntityName()));
        $handler(new DispatchEntityMessage(
            SyncEntityDefinition::ENTITY_NAME,
            Operation::CREATE,
            new \DateTimeImmutable(),
            [],
            'current-shop-id'
        ));
    }

    public function testIgnoresMessageIfWasDispatchedForFormerShopId(): void
    {
        $connection = $this->createConnectionMock();
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->never())
            ->method('dispatch');

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->never())
            ->method('getLastConsentIsAcceptedDate');

        $definition = new SyncEntityDefinition();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                return new FieldCollection($definition->getFields());
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            new EntityDefinitionService(
                [$definition],
                $usageDataAllowListService,
            ),
            new ManyToManyAssociationService($connection),
            $usageDataAllowListService,
            $connection,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        static::expectException(UnrecoverableMessageHandlingException::class);
        static::expectExceptionMessage(\sprintf('Message dispatched for old shopId. Skipping dispatching of entity sync message. Entity: %s, Operation: create', $definition->getEntityName()));
        $handler(new DispatchEntityMessage(
            SyncEntityDefinition::ENTITY_NAME,
            Operation::CREATE,
            new \DateTimeImmutable(),
            [],
            'old-shop-id'
        ));
    }

    public function testItHandlesDeletionsAndUpdatesCurrentRunDateIfApprovalIsGiven(): void
    {
        $idsCollection = new IdsCollection();

        // keys for the corresponding entries in the table usage_data_entity_deletion
        $primaryKeys = [
            ['id' => '0189e3c51ce6732e9339ac7664f5d966'],
            ['id' => '0189e3c51ce6732e9339ac766535f1ab'],
            ['id' => '0189e3c51ce6732e9339ac7665587c0e'],
        ];

        $expectedDispatchPayload = [];
        $queryResult = [];
        for ($i = 0; $i < \count($primaryKeys); ++$i) {
            $expectedDispatchPayload[$i] = [
                'product_id' => $idsCollection->get('product-' . $i),
                'category_id' => $idsCollection->get('category-' . $i),
            ];

            $queryResult[] = [
                'entity_ids' => json_encode($expectedDispatchPayload[$i]),
            ];
        }

        $definition = new SyncEntityDefinition();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                (new SyncEntityDefinition())->getEntityName(),
                $expectedDispatchPayload
            );

        $connectionMock = $this->createConnectionMock();
        $connectionMock->expects($this->once())
            ->method('executeQuery') // SELECT
            ->willReturn(FakeResultFactory::createResult($queryResult, $connectionMock));
        $connectionMock->expects($this->once())
            ->method('executeStatement') // DELETE
            ->willReturn(\count($primaryKeys));

        $consentService = $this->createMock(ConsentService::class);
        $consentService->method('getLastConsentIsAcceptedDate')
            ->willReturn(new \DateTimeImmutable());

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                return new FieldCollection($definition->getFields());
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            new EntityDefinitionService(
                [$definition],
                $usageDataAllowListService,
            ),
            new ManyToManyAssociationService($connectionMock),
            $usageDataAllowListService,
            $connectionMock,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        $message = new DispatchEntityMessage(
            $definition->getEntityName(),
            Operation::DELETE,
            new \DateTimeImmutable('2023-08-01 12:00:00'),
            $primaryKeys
        );

        $handler($message);
    }

    public function testFetchesAndEncodesAndSendsEntities(): void
    {
        $entityIds = [
            ['id' => '0189e3c51ce6732e9339ac7664f5d966'],
            ['id' => '0189e3c51ce6732e9339ac766535f1ab'],
            ['id' => '0189e3c51ce6732e9339ac7665587c0e'],
        ];

        $definition = new SyncEntityDefinition();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $doctrineResult = $this->createMock(Result::class);
        $doctrineResult->expects($this->once())
            ->method('iterateAssociative')
            ->willReturn(new \ArrayIterator([
                [
                    'id' => Uuid::fromHexToBytes($entityIds[0]['id']),
                    'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    'created_at' => '2021-08-01 12:00:00',
                    'updated_at' => '2021-08-02 12:00:00',
                ],
                [
                    'id' => Uuid::fromHexToBytes($entityIds[1]['id']),
                    'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    'created_at' => '2021-08-01 12:00:00',
                    'updated_at' => '2021-08-02 12:00:00',
                ],
                [
                    'id' => Uuid::fromHexToBytes($entityIds[2]['id']),
                    'version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    'created_at' => '2021-08-01 12:00:00',
                    'updated_at' => '2021-08-02 12:00:00',
                ],
            ]));

        $connectionMock = $this->createConnectionMock();
        $connectionMock->expects($this->once())
            ->method('executeQuery')
            ->willReturn($doctrineResult);

        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                SyncEntityDefinition::ENTITY_NAME,
                [
                    [
                        'id' => $entityIds[0]['id'],
                        'createdAt' => new \DateTimeImmutable('2021-08-01 12:00:00'),
                        'updatedAt' => new \DateTimeImmutable('2021-08-02 12:00:00'),
                    ],
                    [
                        'id' => $entityIds[1]['id'],
                        'createdAt' => new \DateTimeImmutable('2021-08-01 12:00:00'),
                        'updatedAt' => new \DateTimeImmutable('2021-08-02 12:00:00'),
                    ],
                    [
                        'id' => $entityIds[2]['id'],
                        'createdAt' => new \DateTimeImmutable('2021-08-01 12:00:00'),
                        'updatedAt' => new \DateTimeImmutable('2021-08-02 12:00:00'),
                    ],
                ]
            );

        $consentService = $this->createMock(ConsentService::class);
        $consentService->method('getLastConsentIsAcceptedDate')
            ->willReturn(new \DateTimeImmutable());

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                $fields = $definition->getFields()->getElements();

                // filter out all VersionFields
                $fields = array_filter($fields, function (Field $field) {
                    return !($field instanceof VersionField);
                });

                return new FieldCollection($fields);
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            new EntityDefinitionService(
                [$definition],
                $usageDataAllowListService,
            ),
            new ManyToManyAssociationService($connectionMock),
            $usageDataAllowListService,
            $connectionMock,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        $handler(new DispatchEntityMessage(
            SyncEntityDefinition::ENTITY_NAME,
            Operation::CREATE,
            new \DateTimeImmutable(),
            $entityIds,
            'current-shop-id'
        ));
    }

    public function testItAddsGivenAssociationFieldsToFieldsToSelect(): void
    {
        $definition = new EntityWithManyToManyAssociationField();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $idFieldStorageName = 'storage_name';
        $entityDefinitionService = $this->createMock(EntityDefinitionService::class);
        $entityDefinitionService->method('getAllowedEntityDefinition')
            ->willReturn($definition);
        $entityDefinitionService->method('getManyToManyAssociationIdFields')
            ->willReturn([
                [
                    'idField' => new CustomManyToManyIdsField($idFieldStorageName),
                    'associationField' => null,
                ],
            ]);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('createExpressionBuilder')
            ->willReturn($expressionBuilder);
        $connection->method('executeQuery')
            ->with(static::callback(function (string $query) use ($idFieldStorageName) {
                return str_contains($query, EntityDefinitionQueryHelper::escape($idFieldStorageName));
            }));

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('getLastConsentIsAcceptedDate')
            ->willReturn(new \DateTimeImmutable());

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                return new FieldCollection($definition->getFields());
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            $entityDefinitionService,
            $this->createMock(ManyToManyAssociationService::class),
            $usageDataAllowListService,
            $connection,
            $this->createMock(EntityDispatcher::class),
            $consentService,
            $shopIdProvider
        );

        $handler(new DispatchEntityMessage(
            $definition->getEntityName(),
            Operation::CREATE,
            new \DateTimeImmutable(),
            [['id' => '1234']],
            'current-shop-id'
        ));
    }

    public function testItThrowsExceptionWhenEntityHasMultiplePrimaryKeysAndMissingAssociationIdFields(): void
    {
        $definition = new EntityWithManyToManyAssociationField();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $entityDefinitionService = $this->createMock(EntityDefinitionService::class);
        $entityDefinitionService->method('getAllowedEntityDefinition')
            ->willReturn($definition);
        $entityDefinitionService->method('getManyToManyAssociationIdFields')
            ->willReturn([
                [
                    'idField' => null,
                    'associationField' => 'association_for_missing_id_field',
                ],
            ]);

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('getLastConsentIsAcceptedDate')
            ->willReturn(new \DateTimeImmutable());

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            $entityDefinitionService,
            $this->createMock(ManyToManyAssociationService::class),
            new UsageDataAllowListService(),
            $this->createMock(Connection::class),
            $this->createMock(EntityDispatcher::class),
            $consentService,
            $shopIdProvider,
        );

        static::expectException(UnrecoverableMessageHandlingException::class);
        static::expectExceptionMessage(\sprintf('Entity sync does not support composite primary keys. Skipping dispatching of entity sync message. Entity: %s, Operation: create', $definition->getEntityName()));
        $handler(new DispatchEntityMessage(
            $definition->getEntityName(),
            Operation::CREATE,
            new \DateTimeImmutable(),
            // this indicates multiple primary keys
            [['id' => '1234', 'id2' => '4321']],
            'current-shop-id'
        ));
    }

    public function testItFetchesMissingAssociationFieldAndAddsItToTheEntity(): void
    {
        $definition = new EntityWithManyToManyAssociationField();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $entityDefinitionService = $this->createMock(EntityDefinitionService::class);
        $entityDefinitionService->method('getAllowedEntityDefinition')
            ->willReturn($definition);
        $entityDefinitionService->method('getManyToManyAssociationIdFields')
            ->willReturn([
                [
                    'idField' => null,
                    'associationField' => 'missing',
                ],
            ]);

        $manyToManyAssociationService = $this->createMock(ManyToManyAssociationService::class);
        $manyToManyAssociationService->expects($this->once())
            ->method('getMappingIdsForAssociationFields')
            ->with(static::callback(function (array $associationFields) {
                return $associationFields[0] === 'missing';
            }))
            ->willReturn(['associationName' => ['primaryKeyValue' => 'associationValue']]);

        $createdAndUpdatedAt = new \DateTimeImmutable('2023-07-31');
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('createExpressionBuilder')
            ->willReturn($expressionBuilder);

        $queryResult = FakeResultFactory::createResult(
            [
                [
                    'id' => 'primaryKeyValue',
                    'created_at' => $createdAndUpdatedAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'updated_at' => $createdAndUpdatedAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ],
            ],
            $connection
        );

        $connection->method('executeQuery')
            ->willReturn($queryResult);

        $runDate = new \DateTimeImmutable();
        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $definition->getEntityName(),
                [
                    [
                        'createdAt' => $createdAndUpdatedAt,
                        'updatedAt' => $createdAndUpdatedAt,
                        'associationName' => 'associationValue',
                    ],
                ],
                Operation::CREATE,
                $runDate
            );

        $consentService = $this->createMock(ConsentService::class);
        $consentService->expects($this->once())
            ->method('getLastConsentIsAcceptedDate')
            ->willReturn($createdAndUpdatedAt);

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                return new FieldCollection($definition->getFields());
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            $entityDefinitionService,
            $manyToManyAssociationService,
            $usageDataAllowListService,
            $connection,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        $handler(new DispatchEntityMessage(
            $definition->getEntityName(),
            Operation::CREATE,
            $runDate,
            [['id' => '1234']],
            'current-shop-id'
        ));
    }

    public function testFormatsValueUsingFieldSerializer(): void
    {
        $serializerMock = $this->createMock(FieldSerializerInterface::class);
        $serializerMock->method('decode')
            ->willReturn('decoded_value');

        /** @phpstan-ignore shopware.mockingSimpleObjects (for test purpose) */
        $idFieldMock = $this->createMock(ManyToManyIdField::class);
        $idFieldMock->method('getSerializer')
            ->willReturn($serializerMock);
        $idFieldMock->method('getAssociationName')
            ->willReturn('association_name');
        $idFieldMock->method('getStorageName')
            ->willReturn('storage_name');

        $definition = new EntityEncoderEntity();
        $definition->setExtraFields([$idFieldMock]);

        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $serialized = DispatchEntityMessageHandler::serialize($definition->getFields(), [
            'string' => 'foo',
            'int' => '1337',
            'created_at' => (new \DateTimeImmutable('2023-07-31'))->format(Defaults::STORAGE_DATE_FORMAT),
            'updated_at' => null,
            'storage_name' => '1234',
            'blob' => 'blob',
        ]);

        static::assertArrayHasKey('string', $serialized);
        static::assertSame('foo', $serialized['string']);

        static::assertArrayHasKey('int', $serialized);
        static::assertSame(1337, $serialized['int']);

        static::assertArrayHasKey('createdAt', $serialized);
        $createdAt = $serialized['createdAt'];
        static::assertInstanceOf(\DateTimeInterface::class, $createdAt);
        static::assertSame((new \DateTimeImmutable('2023-07-31'))->format(Defaults::STORAGE_DATE_TIME_FORMAT), $createdAt->format(Defaults::STORAGE_DATE_TIME_FORMAT));

        static::assertArrayHasKey('updatedAt', $serialized);
        static::assertNull($serialized['updatedAt']);

        static::assertArrayHasKey('association_name', $serialized);
        static::assertSame('decoded_value', $serialized['association_name']);

        static::assertArrayHasKey('blob', $serialized);
        static::assertSame('blob', base64_decode($serialized['blob'], true));

        static::assertArrayNotHasKey('one_to_one', $serialized);
    }

    public function testDoesNotDispatchIfNoEntitiesAreGiven(): void
    {
        $definition = new SyncEntityDefinition();
        new StaticDefinitionInstanceRegistry(
            [$definition],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $doctrineResult = $this->createMock(Result::class);
        $doctrineResult->expects($this->once())
            ->method('iterateAssociative')
            ->willReturn(new \ArrayIterator([])); // could be empty if the entities were deleted in the meantime

        $connectionMock = $this->createConnectionMock();
        $connectionMock->expects($this->once())
            ->method('executeQuery')
            ->willReturn($doctrineResult);

        $entityDispatcher = $this->createMock(EntityDispatcher::class);
        $entityDispatcher->expects($this->never())
            ->method('dispatch');

        $consentService = $this->createMock(ConsentService::class);
        $consentService->method('getLastConsentIsAcceptedDate')
            ->willReturn(new \DateTimeImmutable());

        $usageDataAllowListService = $this->createMock(UsageDataAllowListService::class);
        $usageDataAllowListService->method('isEntityAllowed')
            ->willReturn(true);
        $usageDataAllowListService->method('getFieldsToSelectFromDefinition')
            ->willReturnCallback(function (EntityDefinition $definition) {
                $fields = $definition->getFields()->getElements();

                // filter out all VersionFields
                $fields = array_filter($fields, function (Field $field) {
                    return !($field instanceof VersionField);
                });

                return new FieldCollection($fields);
            });

        $shopIdProvider = $this->createMock(ShopIdProvider::class);
        $shopIdProvider->method('getShopId')->willReturn('current-shop-id');

        $handler = new DispatchEntityMessageHandler(
            new EntityDefinitionService(
                [$definition],
                $usageDataAllowListService,
            ),
            new ManyToManyAssociationService($connectionMock),
            $usageDataAllowListService,
            $connectionMock,
            $entityDispatcher,
            $consentService,
            $shopIdProvider
        );

        $handler(new DispatchEntityMessage(
            SyncEntityDefinition::ENTITY_NAME,
            Operation::CREATE,
            new \DateTimeImmutable(),
            [
                ['id' => '0189e3c51ce6732e9339ac7664f5d966'],
                ['id' => '0189e3c51ce6732e9339ac766535f1ab'],
                ['id' => '0189e3c51ce6732e9339ac7665587c0e'],
            ],
            'current-shop-id',
        ));
    }

    private function createConnectionMock(): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        $connection->expects($this->never())
            ->method('createQueryBuilder');
        $connection->expects($this->any())
            ->method('createExpressionBuilder')
            ->willReturn(new ExpressionBuilder($connection));

        return $connection;
    }
}

/**
 * @internal
 */
class SyncEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'sync_entity';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new IdField('id', 'id'),
            new VersionField(),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }

    protected function defaultFields(): array
    {
        return [];
    }
}

/**
 * @internal
 */
class EntityEncoderEntity extends EntityDefinition
{
    /**
     * @var array<Field>
     */
    private array $extraFields = [];

    /**
     * @param array<Field> $fields
     */
    public function setExtraFields(array $fields): void
    {
        $this->extraFields = $fields;
    }

    public function getEntityName(): string
    {
        return 'entity_encoder_entity';
    }

    protected function defineFields(): FieldCollection
    {
        $fields = [
            new StringField('string', 'string'),
            new IntField('int', 'int'),
            new OneToOneAssociationField('oneToOne', 'one_to_one', 'id', EntityEncoderEntity::class, false),
            new BlobField('blob', 'blob'),
        ];

        $fields = array_merge($fields, $this->extraFields);

        return new FieldCollection($fields);
    }
}

/**
 * @internal
 */
class EntityWithManyToManyAssociationField extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'entity_with_many_to_many_association_field';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            new ManyToManyAssociationField('manyToManyAssociationFieldProperty', MockEntityDefinition::class, ManyToManyMappingEntityDefinition::class, 'manyToMany', 'manyToMany'),
        ]);
    }
}

/**
 * @internal
 */
class QueryBuilderMock extends QueryBuilder
{
    /**
     * @param list<array<string, mixed>> $result
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $result,
    ) {
        parent::__construct($connection);
    }

    public function executeQuery(): Result
    {
        return FakeResultFactory::createResult($this->result, $this->connection);
    }

    public function executeStatement(): int
    {
        return 0;
    }
}

/**
 * @internal
 */
class CustomManyToManyIdsField extends ManyToManyIdField
{
    public function __construct(string $storageName)
    {
        parent::__construct($storageName, 'bar', 'baz');
    }
}
