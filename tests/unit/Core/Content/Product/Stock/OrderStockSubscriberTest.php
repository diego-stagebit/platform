<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\Stock;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\Stock\OrderStockSubscriber;
use Shopware\Core\Content\Product\Stock\StockAlteration;
use Shopware\Core\Content\Product\Stock\StockStorage;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityExistence;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(OrderStockSubscriber::class)]
class OrderStockSubscriberTest extends TestCase
{
    private IdsCollection $ids;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();
    }

    /**
     * @param class-string<EntityDefinition> $class
     */
    public function getDefinition(string $class = OrderLineItemDefinition::class): EntityDefinition
    {
        new StaticDefinitionInstanceRegistry(
            [$definition = new $class()],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class)
        );

        return $definition;
    }

    public function testGetSubscribedEvents(): void
    {
        $events = OrderStockSubscriber::getSubscribedEvents();

        static::assertArrayHasKey(StateMachineTransitionEvent::class, $events);
        static::assertArrayHasKey(EntityWriteEvent::class, $events);
    }

    public function testBeforeWriteCanBeDisabled(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId($this->ids->create('version'));

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            false
        );

        $event = EntityWriteEvent::create(
            WriteContext::createFromContext($context),
            [],
        );

        $stockSubscriber->beforeWriteOrderItems($event);
        $event->success();
    }

    public function testBeforeWriteOnlyReactsToLiveVersions(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId($this->ids->create('version'));

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            true
        );

        $event = EntityWriteEvent::create(
            WriteContext::createFromContext($context),
            [],
        );

        $stockSubscriber->beforeWriteOrderItems($event);
        $event->success();
    }

    public function testBeforeWriteOnlyReactsToOrderLineItems(): void
    {
        $context = Context::createDefaultContext();

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            true
        );

        $definition = $this->getDefinition(ProductDefinition::class);

        $event = EntityWriteEvent::create(
            WriteContext::createFromContext($context),
            [
                new DeleteCommand(
                    $definition,
                    ['id' => $this->ids->getBytes('item-1')],
                    new EntityExistence(
                        OrderLineItemDefinition::ENTITY_NAME,
                        ['id' => $this->ids->get('item-1')],
                        true,
                        false,
                        false,
                        []
                    ),
                ),
            ],
        );

        $stockSubscriber->beforeWriteOrderItems($event);
        $event->success();
    }

    public function testBeforeWriteOnlyReactsToProductAndQuantityChanges(): void
    {
        $context = Context::createDefaultContext();

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            true
        );

        $definition = $this->getDefinition();

        $event = EntityWriteEvent::create(
            WriteContext::createFromContext($context),
            [
                new UpdateCommand(
                    $definition,
                    ['some-field' => 'some-value'],
                    ['id' => $this->ids->getBytes('item-1')],
                    new EntityExistence(
                        OrderLineItemDefinition::ENTITY_NAME,
                        ['id' => $this->ids->get('item-1')],
                        true,
                        false,
                        false,
                        []
                    ),
                    '/0'
                ),
            ],
        );

        $stockSubscriber->beforeWriteOrderItems($event);
        $event->success();
    }

    /**
     * @param list<array{id: string, quantity: string, referenced_id: string}> $beforeState
     * @param list<array{id: string, quantity: string, referenced_id: string}> $afterState
     * @param list<array{lineItemId: string, productId: string, quantityBefore: int, newQuantity: int}> $expectedUpdates
     * @param list<array{type: 'insert'|'delete'|'update', id: string, state: array<string, mixed>}> $commands
     */
    #[DataProvider('orderItemWriteProvider')]
    public function testOrderItemWrites(array $beforeState, array $afterState, array $expectedUpdates, array $commands): void
    {
        $idMapper = function (array $fields): callable {
            return function (array $lineItem) use ($fields): array {
                foreach ($fields as $field) {
                    if (isset($lineItem[$field])) {
                        $lineItem[$field] = $this->ids->get($lineItem[$field]);
                    }
                }

                return $lineItem;
            };
        };

        $beforeState = array_map($idMapper(['id', 'referenced_id']), $beforeState);
        $afterState = array_map($idMapper(['id', 'referenced_id']), $afterState);

        $beforeState = array_combine(
            array_map(fn (array $lineItem) => $lineItem['id'], $beforeState),
            $beforeState
        );

        $afterState = array_combine(
            array_map(fn (array $lineItem) => $lineItem['id'], $afterState),
            $afterState
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociativeIndexed')->willReturnOnConsecutiveCalls(
            $beforeState,
            $afterState,
        );

        $stockStorage = $this->createMock(StockStorage::class);
        $stockSubscriber = new OrderStockSubscriber(
            $connection,
            $stockStorage,
            true
        );

        $expectedUpdates = array_map($idMapper(['lineItemId', 'productId']), $expectedUpdates);

        $context = Context::createDefaultContext();
        $stockStorage->expects($this->once())
            ->method('alter')
            ->with(static::callback(function (array $changes) use ($expectedUpdates): bool {
                static::assertSameSize($expectedUpdates, $changes);

                foreach ($expectedUpdates as $i => $expectedUpdate) {
                    static::assertInstanceOf(StockAlteration::class, $changes[$i]);
                    static::assertSame($expectedUpdate['lineItemId'], $changes[$i]->lineItemId);
                    static::assertSame($expectedUpdate['productId'], $changes[$i]->productId);

                    static::assertSame($expectedUpdate['quantityBefore'], $changes[$i]->quantityBefore);
                    static::assertSame($expectedUpdate['newQuantity'], $changes[$i]->newQuantity);
                }

                return true;
            }));

        $orderItemDefinition = $this->getDefinition();

        $commands = array_map(
            function (array $command) use ($orderItemDefinition, $idMapper) {
                return match ($command['type']) {
                    'insert' => new InsertCommand(
                        $orderItemDefinition,
                        [],
                        ['id' => $this->ids->getBytes($command['id'])],
                        new EntityExistence(
                            OrderLineItemDefinition::ENTITY_NAME,
                            ['id' => $this->ids->get($command['id'])],
                            false,
                            false,
                            false,
                            []
                        ),
                        '/0'
                    ),
                    'delete' => new DeleteCommand(
                        $orderItemDefinition,
                        ['id' => $this->ids->getBytes($command['id'])],
                        new EntityExistence(
                            OrderLineItemDefinition::ENTITY_NAME,
                            ['id' => $this->ids->get($command['id'])],
                            true,
                            false,
                            false,
                            []
                        ),
                    ),
                    'update' => new UpdateCommand(
                        $orderItemDefinition,
                        $idMapper(['referenced_id'])($command['state']),
                        ['id' => $this->ids->getBytes($command['id'])],
                        new EntityExistence(
                            OrderLineItemDefinition::ENTITY_NAME,
                            ['id' => $this->ids->get($command['id'])],
                            true,
                            false,
                            false,
                            []
                        ),
                        '/0'
                    ),
                };
            },
            $commands
        );

        $event = EntityWriteEvent::create(
            WriteContext::createFromContext($context),
            $commands,
        );
        $stockSubscriber->beforeWriteOrderItems($event);
        $event->success();
    }

    public static function orderItemWriteProvider(): \Generator
    {
        yield 'new-orders' => [
            'beforeState' => [],
            'afterState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-1'],
                ['id' => 'item-2', 'quantity' => '10', 'referenced_id' => 'product-2'],
            ],
            'expectedUpdates' => [
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-1',
                    'quantityBefore' => 0,
                    'newQuantity' => 10,
                ],
                [
                    'lineItemId' => 'item-2',
                    'productId' => 'product-2',
                    'quantityBefore' => 0,
                    'newQuantity' => 10,
                ],
            ],
            'commands' => [
                [
                    'type' => 'insert',
                    'id' => 'item-1',
                ],
                [
                    'type' => 'insert',
                    'id' => 'item-2',
                ],
            ],
        ];

        yield 'new-item-and-deleted-item' => [
            'beforeState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-1'],
            ],
            'afterState' => [
                ['id' => 'item-2', 'quantity' => '10', 'referenced_id' => 'product-2'],
            ],
            'expectedUpdates' => [
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-1',
                    'quantityBefore' => 10,
                    'newQuantity' => 0,
                ],
                [
                    'lineItemId' => 'item-2',
                    'productId' => 'product-2',
                    'quantityBefore' => 0,
                    'newQuantity' => 10,
                ],
            ],
            'commands' => [
                [
                    'type' => 'delete',
                    'id' => 'item-1',
                ],
                [
                    'type' => 'insert',
                    'id' => 'item-2',
                ],
            ],
        ];

        yield 'items-deleted' => [
            'beforeState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-1'],
                ['id' => 'item-2', 'quantity' => '10', 'referenced_id' => 'product-2'],
            ],
            'afterState' => [],
            'expectedUpdates' => [
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-1',
                    'quantityBefore' => 10,
                    'newQuantity' => 0,
                ],
                [
                    'lineItemId' => 'item-2',
                    'productId' => 'product-2',
                    'quantityBefore' => 10,
                    'newQuantity' => 0,
                ],
            ],
            'commands' => [
                [
                    'type' => 'delete',
                    'id' => 'item-1',
                ],
                [
                    'type' => 'delete',
                    'id' => 'item-2',
                ],
            ],
        ];

        yield 'items-qty-changed' => [
            'beforeState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-1'],
                ['id' => 'item-2', 'quantity' => '5', 'referenced_id' => 'product-2'],
            ],
            'afterState' => [
                ['id' => 'item-1', 'quantity' => '20', 'referenced_id' => 'product-1'],
                ['id' => 'item-2', 'quantity' => '3', 'referenced_id' => 'product-2'],
            ],
            'expectedUpdates' => [
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-1',
                    'quantityBefore' => 10,
                    'newQuantity' => 20,
                ],
                [
                    'lineItemId' => 'item-2',
                    'productId' => 'product-2',
                    'quantityBefore' => 5,
                    'newQuantity' => 3,
                ],
            ],
            'commands' => [
                [
                    'type' => 'update',
                    'id' => 'item-1',
                    'state' => ['quantity' => 20],
                ],
                [
                    'type' => 'update',
                    'id' => 'item-2',
                    'state' => ['quantity' => 3],
                ],
            ],
        ];

        yield 'items-product-changed' => [
            'beforeState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-1'],
            ],
            'afterState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-2'],
            ],
            'expectedUpdates' => [
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-1',
                    'quantityBefore' => 10,
                    'newQuantity' => 0,
                ],
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-2',
                    'quantityBefore' => 0,
                    'newQuantity' => 10,
                ],
            ],
            'commands' => [
                [
                    'type' => 'update',
                    'id' => 'item-1',
                    'state' => ['referenced_id' => 'product-2'],
                ],
            ],
        ];

        yield 'items-product-and-qty-changed' => [
            'beforeState' => [
                ['id' => 'item-1', 'quantity' => '10', 'referenced_id' => 'product-1'],
            ],
            'afterState' => [
                ['id' => 'item-1', 'quantity' => '15', 'referenced_id' => 'product-2'],
            ],
            'expectedUpdates' => [
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-1',
                    'quantityBefore' => 10,
                    'newQuantity' => 0,
                ],
                [
                    'lineItemId' => 'item-1',
                    'productId' => 'product-2',
                    'quantityBefore' => 0,
                    'newQuantity' => 15,
                ],
            ],
            'commands' => [
                [
                    'type' => 'update',
                    'id' => 'item-1',
                    'state' => ['quantity' => 15, 'referenced_id' => 'product-2'],
                ],
            ],
        ];
    }

    public function testStateChangeCanBeDisabled(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId($this->ids->create('version'));

        $fromState = new StateMachineStateEntity();
        $fromState->setTechnicalName(OrderStates::STATE_OPEN);

        $toState = new StateMachineStateEntity();
        $toState->setTechnicalName(OrderStates::STATE_CANCELLED);

        $event = new StateMachineTransitionEvent(
            OrderDefinition::ENTITY_NAME,
            $this->ids->get('order-1'),
            $fromState,
            $toState,
            $context
        );

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            false
        );

        $stockSubscriber->stateChanged($event);
    }

    public function testStateChangeOnlyReactsToLiveVersions(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId($this->ids->create('version'));

        $fromState = new StateMachineStateEntity();
        $fromState->setTechnicalName(OrderStates::STATE_OPEN);

        $toState = new StateMachineStateEntity();
        $toState->setTechnicalName(OrderStates::STATE_CANCELLED);

        $event = new StateMachineTransitionEvent(
            OrderDefinition::ENTITY_NAME,
            $this->ids->get('order-1'),
            $fromState,
            $toState,
            $context
        );

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            true
        );

        $stockSubscriber->stateChanged($event);
    }

    public function testStateChangeOnlyReactsToOrderEntities(): void
    {
        $context = Context::createDefaultContext();

        $fromState = new StateMachineStateEntity();
        $fromState->setTechnicalName(OrderStates::STATE_OPEN);

        $toState = new StateMachineStateEntity();
        $toState->setTechnicalName(OrderStates::STATE_CANCELLED);

        $event = new StateMachineTransitionEvent(
            'wrong-entity',
            $this->ids->get('order-1'),
            $fromState,
            $toState,
            $context
        );

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->never())->method('alter');

        $stockSubscriber = new OrderStockSubscriber(
            $this->createMock(Connection::class),
            $stockStorage,
            true
        );

        $stockSubscriber->stateChanged($event);
    }

    #[DataProvider('orderStateTransitionProvider')]
    public function testStocksAreUpdatedWhenOrdersTransitionThroughStates(
        string $fromStateName,
        string $toStateName,
        int $quantityBefore,
        int $quantityAfter
    ): void {
        $context = Context::createDefaultContext();

        $fromState = new StateMachineStateEntity();
        $fromState->setTechnicalName($fromStateName);

        $toState = new StateMachineStateEntity();
        $toState->setTechnicalName($toStateName);

        $event = new StateMachineTransitionEvent(
            OrderDefinition::ENTITY_NAME,
            $this->ids->get('order-1'),
            $fromState,
            $toState,
            $context
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => $this->ids->get('item-1'), 'quantity' => '10', 'product_id' => $this->ids->get('product-1')],
            ['id' => $this->ids->get('item-2'), 'quantity' => '10', 'product_id' => $this->ids->get('product-2')],
        ]);

        $stockStorage = $this->createMock(StockStorage::class);
        $stockStorage->expects($this->once())
            ->method('alter')
            ->with(static::callback(function (array $changes) use ($quantityBefore, $quantityAfter) {
                static::assertCount(2, $changes);
                static::assertInstanceOf(StockAlteration::class, $changes[0]);
                static::assertInstanceOf(StockAlteration::class, $changes[1]);

                static::assertSame($this->ids->get('item-1'), $changes[0]->lineItemId);
                static::assertSame($this->ids->get('product-1'), $changes[0]->productId);
                static::assertSame($quantityBefore, $changes[0]->quantityBefore);
                static::assertSame($quantityAfter, $changes[0]->newQuantity);

                static::assertSame($this->ids->get('item-2'), $changes[1]->lineItemId);
                static::assertSame($this->ids->get('product-2'), $changes[1]->productId);
                static::assertSame($quantityBefore, $changes[1]->quantityBefore);
                static::assertSame($quantityAfter, $changes[1]->newQuantity);

                return true;
            }));

        $stockSubscriber = new OrderStockSubscriber(
            $connection,
            $stockStorage,
            true
        );

        $stockSubscriber->stateChanged($event);
    }

    /**
     * @return array<string, array{fromStateName: string, toStateName: string, quantityBefore: int, quantityAfter: int}>
     */
    public static function orderStateTransitionProvider(): array
    {
        return [
            'order-cancelled' => [
                'fromStateName' => OrderStates::STATE_OPEN,
                'toStateName' => OrderStates::STATE_CANCELLED,
                'quantityBefore' => 10,
                'quantityAfter' => 0,
            ],
            'order-reopened' => [
                'fromStateName' => OrderStates::STATE_CANCELLED,
                'toStateName' => OrderStates::STATE_OPEN,
                'quantityBefore' => 0,
                'quantityAfter' => 10,
            ],
        ];
    }
}
