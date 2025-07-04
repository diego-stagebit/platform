<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\ImportExport\Aggregate\ImportExportLog\ImportExportLogEntity;
use Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\OrderSerializer;
use Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
#[CoversClass(OrderSerializer::class)]
class OrderSerializerTest extends TestCase
{
    private OrderSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new OrderSerializer();
        $this->serializer->setRegistry($this->createMock(SerializerRegistry::class));
    }

    public function testSupports(): void
    {
        static::assertTrue($this->serializer->supports('order'));
        static::assertFalse($this->serializer->supports('not_order'));
    }

    /**
     * @param array<mixed>|Struct|null $entity
     * @param array<mixed> $expected
     */
    #[DataProvider('serializeDataProvider')]
    public function testSerialize($entity, array $expected): void
    {
        $logEntity = new ImportExportLogEntity();
        $logEntity->setId(Uuid::randomHex());
        $config = Config::fromLog($logEntity);
        $definition = new OrderDefinition();
        $definition->compile($this->createMock(DefinitionInstanceRegistry::class));

        $result = iterator_to_array($this->serializer->serialize($config, $definition, $entity));

        static::assertEquals($expected, $result);
    }

    /**
     * @return iterable<string, array{entity: Struct|array<mixed>|null, expected: array<string, mixed>}>
     */
    public static function serializeDataProvider(): iterable
    {
        yield 'without entity' => [
            'entity' => null,
            'expected' => [],
        ];

        yield 'with array record' => [
            'entity' => [
                'orderNumber' => 'NUM-1',
            ],
            'expected' => [
                'orderNumber' => 'NUM-1',
            ],
        ];

        yield 'with entity' => [
            'entity' => self::createOrderEntity(),
            'expected' => self::getExpected(),
        ];

        yield 'with order empty line items' => [
            'entity' => self::createOrderEntity([
                'lineItems' => new OrderLineItemCollection(),
            ]),
            'expected' => self::getExpected(),
        ];

        yield 'with order line items' => [
            'entity' => self::createOrderEntity([
                'lineItems' => self::createLineItems(),
            ]),
            'expected' => self::getExpected([
                'lineItems' => '3x |2x ',
            ]),
        ];

        yield 'with order empty deliveries' => [
            'entity' => self::createOrderEntity([
                'deliveries' => new OrderDeliveryCollection(),
            ]),
            'expected' => self::getExpected([
                'deliveries' => new OrderDeliveryCollection(),
            ]),
        ];

        yield 'with order empty transactions' => [
            'entity' => self::createOrderEntity([
                'transactions' => new OrderTransactionCollection(),
            ]),
            'expected' => self::getExpected([
                'transactions' => new OrderTransactionCollection(),
            ]),
        ];

        yield 'with order item rounding and total rounding' => [
            'entity' => self::createOrderEntity([
                'itemRounding' => self::createItemRounding(),
                'totalRounding' => self::createTotalRounding(),
            ]),
            'expected' => self::getExpected([
                'itemRounding' => [
                    'extensions' => [],
                    'decimals' => 2,
                    'interval' => 0.01,
                    'roundForNet' => true,
                ],
                'totalRounding' => [
                    'extensions' => [],
                    'decimals' => 2,
                    'interval' => 0.1,
                    'roundForNet' => false,
                ],
            ]),
        ];

        yield 'with order deliveries' => [
            'entity' => self::createOrderEntity([
                'deliveries' => self::createDeliveries(),
            ]),
            'expected' => self::getExpected([
                'deliveries' => [
                    'extensions' => [],
                    '_uniqueIdentifier' => 'delivery-1',
                    'versionId' => null,
                    'translated' => [],
                    'createdAt' => null,
                    'updatedAt' => null,
                    'shippingMethodId' => 'shipping-method-id',
                    'trackingCodes' => 'CODE-1|CODE-2',
                    'shippingCosts' => new CalculatedPrice(
                        1,
                        1,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection(),
                    ),
                    'shippingOrderAddress' => null,
                    'stateId' => '',
                    'stateMachineState' => null,
                    'shippingMethod' => null,
                    'order' => null,
                    'positions' => new OrderDeliveryPositionCollection(),
                    'id' => 'delivery-1',
                    'customFields' => null,
                    'primaryOrder' => null,
                ],
            ]),
        ];

        yield 'with order transactions' => [
            'entity' => self::createOrderEntity([
                'transactions' => self::createTransactions(),
            ]),
            'expected' => self::getExpected([
                'transactions' => [
                    'extensions' => [],
                    '_uniqueIdentifier' => 'transaction-1',
                    'versionId' => null,
                    'translated' => [],
                    'createdAt' => null,
                    'updatedAt' => null,
                    'amount' => new CalculatedPrice(
                        42,
                        42,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection(),
                    ),
                    'paymentMethod' => null,
                    'order' => null,
                    'stateMachineState' => [
                        'extensions' => [],
                        'versionId' => null,
                        'translated' => [],
                        'createdAt' => null,
                        'updatedAt' => null,
                        'name' => null,
                        'stateMachine' => null,
                        'fromStateMachineTransitions' => null,
                        'toStateMachineTransitions' => null,
                        'orders' => null,
                        'orderTransactionCaptures' => null,
                        'orderTransactionCaptureRefunds' => null,
                        'orderTransactions' => null,
                        'orderDeliveries' => null,
                        'fromStateMachineHistoryEntries' => null,
                        'toStateMachineHistoryEntries' => null,
                        'customFields' => null,
                        'translations' => null,
                    ],
                    'captures' => null,
                    'customFields' => null,
                    'id' => 'transaction-1',
                    'validationData' => [],
                    'primaryOrder' => null,
                ],
            ]),
        ];

        yield 'with order with line items and deliveries' => [
            'entity' => self::createOrderEntity([
                'lineItems' => self::createLineItems(),
                'deliveries' => self::createDeliveries(),
            ]),
            'expected' => self::getExpected([
                'lineItems' => '3x |2x ',
                'deliveries' => [
                    'extensions' => [],
                    '_uniqueIdentifier' => 'delivery-1',
                    'versionId' => null,
                    'translated' => [],
                    'createdAt' => null,
                    'updatedAt' => null,
                    'shippingMethodId' => 'shipping-method-id',
                    'trackingCodes' => 'CODE-1|CODE-2',
                    'shippingCosts' => new CalculatedPrice(
                        1,
                        1,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection(),
                    ),
                    'shippingOrderAddress' => null,
                    'stateId' => '',
                    'stateMachineState' => null,
                    'shippingMethod' => null,
                    'order' => null,
                    'positions' => new OrderDeliveryPositionCollection(),
                    'id' => 'delivery-1',
                    'customFields' => null,
                    'primaryOrder' => null,
                ],
            ]),
        ];

        yield 'with order with line items and deliveries and transactions' => [
            'entity' => self::createOrderEntity([
                'lineItems' => self::createLineItems(),
                'deliveries' => self::createDeliveries(),
                'transactions' => self::createTransactions(),
            ]),
            'expected' => self::getExpected([
                'lineItems' => '3x |2x ',
                'deliveries' => [
                    'extensions' => [],
                    '_uniqueIdentifier' => 'delivery-1',
                    'versionId' => null,
                    'translated' => [],
                    'createdAt' => null,
                    'updatedAt' => null,
                    'shippingMethodId' => 'shipping-method-id',
                    'trackingCodes' => 'CODE-1|CODE-2',
                    'shippingCosts' => new CalculatedPrice(
                        1,
                        1,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection(),
                    ),
                    'shippingOrderAddress' => null,
                    'stateId' => '',
                    'stateMachineState' => null,
                    'shippingMethod' => null,
                    'order' => null,
                    'positions' => new OrderDeliveryPositionCollection(),
                    'id' => 'delivery-1',
                    'customFields' => null,
                    'primaryOrder' => null,
                ],
                'transactions' => [
                    'extensions' => [],
                    '_uniqueIdentifier' => 'transaction-1',
                    'versionId' => null,
                    'translated' => [],
                    'createdAt' => null,
                    'updatedAt' => null,
                    'amount' => new CalculatedPrice(
                        42,
                        42,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection(),
                    ),
                    'paymentMethod' => null,
                    'order' => null,
                    'stateMachineState' => [
                        'extensions' => [],
                        'versionId' => null,
                        'translated' => [],
                        'createdAt' => null,
                        'updatedAt' => null,
                        'name' => null,
                        'stateMachine' => null,
                        'fromStateMachineTransitions' => null,
                        'toStateMachineTransitions' => null,
                        'orders' => null,
                        'orderTransactionCaptures' => null,
                        'orderTransactionCaptureRefunds' => null,
                        'orderTransactions' => null,
                        'orderDeliveries' => null,
                        'fromStateMachineHistoryEntries' => null,
                        'toStateMachineHistoryEntries' => null,
                        'customFields' => null,
                        'translations' => null,
                    ],
                    'captures' => null,
                    'customFields' => null,
                    'id' => 'transaction-1',
                    'validationData' => [],
                    'primaryOrder' => null,
                ],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function createOrderEntity(array $data = []): OrderEntity
    {
        $rawData = [
            'price' => new CartPrice(
                0,
                0,
                0,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                'gross',
            ),
            'shippingCosts' => new CalculatedPrice(
                0,
                0,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'currencyId' => '',
            'currencyFactor' => 0.0,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'customerComment' => null,
            'affiliateCode' => null,
            'campaignCode' => null,
            'itemRounding' => null,
            'totalRounding' => null,
            'orderCustomer' => (new OrderCustomerEntity())->assign([
                'id' => 'order-customer-id',
                'company' => null,
                'customFields' => null,
                'customerId' => 'customer-id',
                'customerNumber' => 'customer-number',
                'email' => 'customer-email',
                'firstName' => 'customer-first-name',
                'lastName' => 'customer-last-name',
                'remoteAddress' => null,
                'salutationId' => 'customer-salutation-id',
                'title' => null,
                'vatIds' => null,
            ]),
            'orderNumber' => '10000',
            'ruleIds' => [],
            'addresses' => new OrderAddressCollection([
                (new OrderAddressEntity())->assign([
                    'id' => 'order-address-id',
                    'city' => 'billing-address-city',
                    'countryId' => 'billing-address-country-id',
                    'firstName' => 'billing-address-first-name',
                    'lastName' => 'billing-address-last-name',
                    'salutationId' => 'billing-address-salutation-id',
                    'street' => 'billing-address-street',
                    'zipcode' => 'billing-address-zipcode',
                ]),
            ]),
            'billingAddressVersionId' => null,
        ];

        $mergedData = array_merge_recursive($rawData, $data);

        if (isset($data['itemRounding'])) {
            $mergedData['itemRounding'] = $data['itemRounding'];
        }

        if (isset($data['totalRounding'])) {
            $mergedData['totalRounding'] = $data['totalRounding'];
        }

        return (new OrderEntity())->assign($mergedData);
    }

    private static function createLineItems(): OrderLineItemCollection
    {
        $lineItem1 = (new OrderLineItemEntity())->assign([
            '_uniqueIdentifier' => 'line-item-id-1',
            'identifier' => 'line-item-id-1',
            'quantity' => 3,
            'type' => 'line-item-type-1',
            'label' => 'line-item-label-1',
            'good' => true,
            'removable' => false,
            'stackable' => false,
            'states' => [],
            'position' => 1,
            'price' => new CalculatedPrice(
                1,
                1,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'payload' => [],
        ]);

        $lineItem2 = (new OrderLineItemEntity())->assign([
            '_uniqueIdentifier' => 'line-item-id-2',
            'identifier' => 'line-item-id-2',
            'quantity' => 2,
            'type' => 'line-item-type-2',
            'label' => 'line-item-label-2',
            'good' => true,
            'removable' => false,
            'stackable' => false,
            'states' => [],
            'position' => 2,
            'price' => new CalculatedPrice(
                1,
                1,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'payload' => [],
        ]);

        return new OrderLineItemCollection([$lineItem1, $lineItem2]);
    }

    /**
     * @param array<string, mixed> $overrided
     *
     * @return array<string, mixed>
     */
    private static function getExpected(array $overrided = []): array
    {
        return array_merge(
            [
                'updatedBy' => null,
                'updatedById' => null,
                'customFields' => null,
                'translated' => [],
                'extensions' => [],
                'versionId' => null,
                'createdAt' => null,
                'updatedAt' => null,
                'currency' => null,
                'taxStatus' => null,
                'language' => null,
                'salesChannel' => null,
                'billingAddress' => null,
                'deliveries' => null,
                'lineItems' => null,
                'deepLinkCode' => null,
                'stateMachineState' => null,
                'documents' => null,
                'tags' => null,
                'createdById' => null,
                'createdBy' => null,
                'price' => new CartPrice(
                    0,
                    0,
                    0,
                    new CalculatedTaxCollection(),
                    new TaxRuleCollection(),
                    'gross',
                ),
                'shippingCosts' => new CalculatedPrice(
                    0,
                    0,
                    new CalculatedTaxCollection(),
                    new TaxRuleCollection(),
                ),
                'currencyId' => '',
                'currencyFactor' => 0.0,
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
                'customerComment' => null,
                'affiliateCode' => null,
                'campaignCode' => null,
                'itemRounding' => null,
                'totalRounding' => null,
                'orderCustomer' => (new OrderCustomerEntity())->assign([
                    'id' => 'order-customer-id',
                    'company' => null,
                    'customFields' => null,
                    'customerId' => 'customer-id',
                    'customerNumber' => 'customer-number',
                    'email' => 'customer-email',
                    'firstName' => 'customer-first-name',
                    'lastName' => 'customer-last-name',
                    'remoteAddress' => null,
                    'salutationId' => 'customer-salutation-id',
                    'title' => null,
                    'vatIds' => null,
                ]),
                'transactions' => null,
                'orderNumber' => '10000',
                'ruleIds' => [],
                'taxCalculationType' => null,
                'addresses' => new OrderAddressCollection([
                    (new OrderAddressEntity())->assign([
                        'id' => 'order-address-id',
                        'city' => 'billing-address-city',
                        'countryId' => 'billing-address-country-id',
                        'firstName' => 'billing-address-first-name',
                        'lastName' => 'billing-address-last-name',
                        'salutationId' => 'billing-address-salutation-id',
                        'street' => 'billing-address-street',
                        'zipcode' => 'billing-address-zipcode',
                    ]),
                ]),
                'source' => null,
                'internalComment' => null,
            ],
            $overrided,
            [
                'primaryOrderDelivery' => null,
                'primaryOrderDeliveryId' => null,
                'primaryOrderTransaction' => null,
                'primaryOrderTransactionId' => null,
                'primaryOrderDeliveryVersionId' => null,
                'primaryOrderTransactionVersionId' => null,
            ]
        );
    }

    private static function createDeliveries(): OrderDeliveryCollection
    {
        $delivery1 = (new OrderDeliveryEntity())->assign([
            'id' => 'delivery-1',
            'positions' => new OrderDeliveryPositionCollection(),
            'shippingCosts' => new CalculatedPrice(
                1,
                1,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'trackingCodes' => ['CODE-1', 'CODE-2'],
            'shippingMethodId' => 'shipping-method-id',
            'stateId' => '',
        ]);

        $delivery2 = (new OrderDeliveryEntity())->assign([
            'id' => 'delivery-2',
            'positions' => new OrderDeliveryPositionCollection(),
            'shippingCosts' => new CalculatedPrice(
                1,
                1,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'trackingCodes' => ['CODE-3', 'CODE-4'],
            'shippingMethodId' => 'shipping-method-id',
            'stateId' => '',
        ]);

        return new OrderDeliveryCollection([$delivery1, $delivery2]);
    }

    private static function createTransactions(): OrderTransactionCollection
    {
        $transaction1 = (new OrderTransactionEntity())->assign([
            'id' => 'transaction-1',
            'amount' => new CalculatedPrice(
                42,
                42,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'stateMachineState' => new StateMachineStateEntity(),
            'stateId' => null,
        ]);

        $transaction2 = (new OrderTransactionEntity())->assign([
            'id' => 'transaction-2',
            'amount' => new CalculatedPrice(
                50.05,
                50.05,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
            ),
            'stateMachineState' => null,
            'stateId' => null,
        ]);

        return new OrderTransactionCollection([$transaction1, $transaction2]);
    }

    private static function createItemRounding(): CashRoundingConfig
    {
        return new CashRoundingConfig(2, 0.01, true);
    }

    private static function createTotalRounding(): CashRoundingConfig
    {
        return new CashRoundingConfig(2, 0.1, false);
    }
}
