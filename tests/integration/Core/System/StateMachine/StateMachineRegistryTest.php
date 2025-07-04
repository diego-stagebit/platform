<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\StateMachine;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\BasicTestDataBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Test\Integration\Builder\Order\OrderBuilder;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
class StateMachineRegistryTest extends TestCase
{
    use BasicTestDataBehaviour;
    use KernelTestBehaviour;

    private Connection $connection;

    private string $stateMachineId;

    private string $openId;

    private string $inProgressId;

    private string $closedId;

    private string $stateMachineName;

    private StateMachineRegistry $stateMachineRegistry;

    private EntityRepository $stateMachineRepository;

    protected function setUp(): void
    {
        $this->connection = static::getContainer()->get(Connection::class);
        $this->stateMachineRegistry = static::getContainer()->get(StateMachineRegistry::class);
        $this->stateMachineRepository = static::getContainer()->get('state_machine.repository');

        $this->stateMachineName = 'test_state_machine';
        $this->stateMachineId = Uuid::randomHex();
        $this->openId = Uuid::randomHex();
        $this->inProgressId = Uuid::randomHex();
        $this->closedId = Uuid::randomHex();

        $nullableTable = <<<EOF
DROP TABLE IF EXISTS _test_nullable;
CREATE TABLE `_test_nullable` (
  `id` varbinary(16) NOT NULL,
  `state` varchar(255) NULL,
  PRIMARY KEY `id` (`id`)
);
EOF;
        $this->connection->executeStatement($nullableTable);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        $this->connection->executeStatement('DROP TABLE `_test_nullable`');
    }

    public function testNonExistingStateMachine(): void
    {
        $this->expectException(StateMachineException::class);

        $context = Context::createDefaultContext();

        $this->stateMachineRegistry->getStateMachine('wusel', $context);
    }

    public function testStateMachineShouldIncludeRelations(): void
    {
        $context = Context::createDefaultContext();
        $this->createStateMachine($context);

        $stateMachine = $this->stateMachineRegistry->getStateMachine($this->stateMachineName, $context);

        static::assertNotNull($stateMachine->getStates());
        static::assertCount(3, $stateMachine->getStates());
        static::assertNotNull($stateMachine->getTransitions());
        static::assertCount(4, $stateMachine->getTransitions());
    }

    public function testStateMachineAvailableTransitionShouldIncludeReOpenAndReTourTransition(): void
    {
        $this->createOrderWithPartiallyReturnedDeliveryState();
        $availableTransitions = $this->stateMachineRegistry->getAvailableTransitions('order_delivery', $this->fetchFirstIdFromTable('order_delivery'), 'stateId', Context::createDefaultContext());

        static::assertNotEmpty($availableTransitions);
        static::assertCount(2, $availableTransitions);

        $reopenActionExisted = false;
        $retourActionExisted = false;

        /** @var StateMachineTransitionEntity $transition */
        foreach ($availableTransitions as $transition) {
            if ($transition->getActionName() === 'reopen') {
                $reopenActionExisted = true;
                static::assertSame(OrderDeliveryStates::STATE_OPEN, $transition->getToStateMachineState()?->getTechnicalName());
            }

            if ($transition->getActionName() === 'retour') {
                $retourActionExisted = true;
                static::assertSame(OrderDeliveryStates::STATE_RETURNED, $transition->getToStateMachineState()?->getTechnicalName());
            }
        }

        static::assertTrue($reopenActionExisted);
        static::assertTrue($retourActionExisted);
    }

    public function testStateMachineStateRetourTransitionFromReturnedPartially(): void
    {
        $orderDeliveryId = $this->createOrderWithPartiallyReturnedDeliveryState();
        $transition = new Transition('order_delivery', $orderDeliveryId, 'retour', 'stateId');
        $stateCollection = $this->stateMachineRegistry->transition($transition, Context::createDefaultContext());

        static::assertNotEmpty($stateCollection);
        static::assertNotEmpty($stateCollection->get('fromPlace'));
        static::assertNotEmpty($stateCollection->get('toPlace'));
        $fromPlace = $stateCollection->get('fromPlace');
        $toPlace = $stateCollection->get('toPlace');
        static::assertSame(OrderDeliveryStates::STATE_PARTIALLY_RETURNED, $fromPlace->getTechnicalName());
        static::assertSame(OrderDeliveryStates::STATE_RETURNED, $toPlace->getTechnicalName());
    }

    public function testStateMachineRegistryUnnecessaryTransition(): void
    {
        $orderDeliveryId = $this->createOrderWithPartiallyReturnedDeliveryState();
        $transition = new Transition('order_delivery', $orderDeliveryId, 'retour_partially', 'stateId');
        $stateCollection = $this->stateMachineRegistry->transition($transition, Context::createDefaultContext());

        static::assertNotEmpty($stateCollection);
        static::assertNotEmpty($stateCollection->get('fromPlace'));
        static::assertNotEmpty($stateCollection->get('toPlace'));
        $fromPlace = $stateCollection->get('fromPlace');
        $toPlace = $stateCollection->get('toPlace');
        static::assertSame(OrderDeliveryStates::STATE_PARTIALLY_RETURNED, $fromPlace->getTechnicalName());
        static::assertSame(OrderDeliveryStates::STATE_PARTIALLY_RETURNED, $toPlace->getTechnicalName());
    }

    public function testStateMachineTransitionStoresUserAndIntegrationId(): void
    {
        $ids = new IdsCollection();

        $userRepo = self::getContainer()->get('user.repository');
        static::assertInstanceOf(EntityRepository::class, $userRepo);

        $userId = $userRepo->searchIds((new Criteria())->setLimit(1), Context::createDefaultContext())->firstId();

        $integration = [
            'id' => $ids->get('integration-1'),
            'label' => 'Integration 1',
            'accessKey' => 'test123',
            'secretAccessKey' => TestDefaults::HASHED_PASSWORD,
        ];

        $integrationRepo = self::getContainer()->get('integration.repository');
        static::assertInstanceOf(EntityRepository::class, $integrationRepo);
        $integrationRepo->create([$integration], Context::createDefaultContext());

        static::assertNotNull($userId);

        $orderBuilder = new OrderBuilder($ids, 'o-1');

        $orderRepo = self::getContainer()->get('order.repository');
        static::assertInstanceOf(EntityRepository::class, $orderRepo);
        $orderRepo->create([$orderBuilder->build()], Context::createCLIContext());

        $context = new Context(
            new AdminApiSource($userId, $ids->get('integration-1'))
        );

        $stateMachineRegistry = self::getContainer()->get(StateMachineRegistry::class);
        static::assertInstanceOf(StateMachineRegistry::class, $stateMachineRegistry);
        $stateMachineRegistry->transition(
            new Transition('order', $ids->get('o-1'), 'process', 'stateId'),
            $context
        );

        $connection = self::getContainer()->get(Connection::class);
        static::assertInstanceOf(Connection::class, $connection);

        $historyData = $connection->fetchAssociative('SELECT LOWER(HEX(integration_id)) as integration_id, LOWER(HEX(user_id)) as user_id FROM `state_machine_history` WHERE referenced_id = :id AND referenced_version_id = :version ORDER BY created_at DESC LIMIT 1', [
            'id' => Uuid::fromHexToBytes($ids->get('o-1')),
            'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);

        static::assertNotFalse($historyData);
        static::assertSame([
            'integration_id' => $ids->get('integration-1'),
            'user_id' => $userId,
        ], $historyData);
    }

    private function createOrderWithPartiallyReturnedDeliveryState(): string
    {
        $orderId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $orderLineItemId = Uuid::randomHex();

        $connection = static::getContainer()->get(Connection::class);

        $orderStateMachineId = $connection->fetchOne('SELECT id FROM state_machine WHERE technical_name = :name', ['name' => 'order.state']);
        $orderOpen = $connection->fetchOne('SELECT id FROM state_machine_state WHERE technical_name = :name AND state_machine_id = :id', ['name' => OrderStates::STATE_OPEN, 'id' => $orderStateMachineId]);

        $deliveryStateMachineId = $connection->fetchOne('SELECT id FROM state_machine WHERE technical_name = :name', ['name' => 'order_delivery.state']);
        /** @var string $returnedPartially */
        $returnedPartially = $connection->fetchOne('SELECT id FROM state_machine_state WHERE technical_name = :name AND state_machine_id = :id', ['name' => OrderDeliveryStates::STATE_PARTIALLY_RETURNED, 'id' => $deliveryStateMachineId]);
        $returnedPartially = Uuid::fromBytesToHex($returnedPartially);

        $orderDeliveryId = Uuid::randomHex();

        $order = [
            'id' => $orderId,
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'orderDateTime' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'price' => new CartPrice(
                10,
                10,
                10,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                CartPrice::TAX_STATE_NET
            ),
            'shippingCosts' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'orderCustomer' => [
                'customerId' => $this->createCustomer(),
                'email' => 'test@example.com',
                'salutationId' => $this->fetchFirstIdFromTable('salutation'),
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
            ],
            'orderNumber' => Uuid::randomHex(),
            'stateId' => Uuid::fromBytesToHex($orderOpen),
            'paymentMethodId' => $this->fetchFirstIdFromTable('payment_method'),
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1.0,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'billingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'salutationId' => $this->fetchFirstIdFromTable('salutation'),
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                    'countryId' => $this->fetchFirstIdFromTable('country'),
                ],
            ],
            'lineItems' => [
                [
                    'id' => $orderLineItemId,
                    'identifier' => 'test',
                    'quantity' => 1,
                    'type' => 'test',
                    'label' => 'test',
                    'price' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
                    'priceDefinition' => new QuantityPriceDefinition(10, new TaxRuleCollection(), 2),
                    'good' => true,
                ],
            ],
            'deliveries' => [
                [
                    'id' => $orderDeliveryId,
                    'shippingMethodId' => $this->getValidShippingMethodId(),
                    'shippingCosts' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
                    'shippingDateEarliest' => date(\DATE_ATOM),
                    'shippingDateLatest' => date(\DATE_ATOM),
                    'stateId' => $returnedPartially,
                    'shippingOrderAddress' => [
                        'salutationId' => $this->getValidSalutationId(),
                        'firstName' => 'Floy',
                        'lastName' => 'Glover',
                        'zipcode' => '59438-0403',
                        'city' => 'Stellaberg',
                        'street' => 'street',
                        'country' => [
                            'name' => 'kasachstan',
                            'id' => $this->getValidCountryId(),
                        ],
                    ],
                    'positions' => [
                        [
                            'price' => new CalculatedPrice(10, 10, new CalculatedTaxCollection(), new TaxRuleCollection()),
                            'orderLineItemId' => $orderLineItemId,
                        ],
                    ],
                ],
            ],
            'context' => '{}',
            'payload' => '{}',
        ];

        static::getContainer()->get('order.repository')->upsert([$order], Context::createDefaultContext());

        return $orderDeliveryId;
    }

    private function createStateMachine(Context $context): void
    {
        $this->stateMachineRepository->upsert([
            [
                'id' => $this->stateMachineId,
                'technicalName' => $this->stateMachineName,
                'translations' => [
                    'en-GB' => ['name' => 'Order state'],
                    'de-DE' => ['name' => 'Bestellungsstatus'],
                ],
                'states' => [
                    ['id' => $this->openId, 'technicalName' => OrderDeliveryStates::STATE_OPEN, 'name' => OrderDeliveryStates::STATE_OPEN],
                    ['id' => $this->inProgressId, 'technicalName' => 'in_progress', 'name' => 'In progress'],
                    ['id' => $this->closedId, 'technicalName' => 'closed', 'name' => 'Closed'],
                ],
                'transitions' => [
                    ['actionName' => 'start', 'fromStateId' => $this->openId, 'toStateId' => $this->inProgressId],

                    ['actionName' => 'reopen', 'fromStateId' => $this->inProgressId, 'toStateId' => $this->openId],
                    ['actionName' => 'close', 'fromStateId' => $this->inProgressId, 'toStateId' => $this->closedId],

                    ['actionName' => 'reopen', 'fromStateId' => $this->closedId, 'toStateId' => $this->openId],
                ],
            ],
        ], $context);
    }

    private function fetchFirstIdFromTable(string $table): string
    {
        $connection = static::getContainer()->get(Connection::class);

        return Uuid::fromBytesToHex((string) $connection->fetchOne('SELECT id FROM ' . $table . ' LIMIT 1'));
    }

    private function createCustomer(): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();

        $customer = [
            'id' => $customerId,
            'number' => '1337',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'customerNumber' => '1337',
            'email' => Uuid::randomHex() . '@example.com',
            'password' => TestDefaults::HASHED_PASSWORD,
            'groupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => $this->getValidCountryId(),
                    'salutationId' => $this->getValidSalutationId(),
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                ],
            ],
        ];

        static::getContainer()->get('customer.repository')->upsert([$customer], Context::createDefaultContext());

        return $customerId;
    }
}
