<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Document\Renderer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Document\Aggregate\DocumentBaseConfig\DocumentBaseConfigCollection;
use Shopware\Core\Checkout\Document\Aggregate\DocumentBaseConfig\DocumentBaseConfigDefinition;
use Shopware\Core\Checkout\Document\Aggregate\DocumentBaseConfig\DocumentBaseConfigEntity;
use Shopware\Core\Checkout\Document\Aggregate\DocumentBaseConfigSalesChannel\DocumentBaseConfigSalesChannelCollection;
use Shopware\Core\Checkout\Document\Aggregate\DocumentBaseConfigSalesChannel\DocumentBaseConfigSalesChannelEntity;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Document\FileGenerator\FileTypes;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\InvoiceRenderer;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\TaxFreeConfig;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 *
 * @phpstan-type OrderSettings array{accountType: string, isCountryCompanyTaxFree: bool, setOrderDelivery: bool, setShippingCountry: bool, setEuCountry: bool, shouldCheckVatIdPattern?: bool, validVat?: bool}
 * @phpstan-type InvoiceConfig array{displayAdditionalNoteDelivery: bool, deliveryCountries: array<string>}
 */
#[Package('after-sales')]
#[CoversClass(InvoiceRenderer::class)]
class InvoiceRendererTest extends TestCase
{
    private const COUNTRY_ID = 'country-id';

    /**
     * @param OrderSettings $orderSettings
     * @param InvoiceConfig $config
     */
    #[DataProvider('configDataProvider')]
    public function testRenderIsAllowIntraCommunityDelivery(
        array $orderSettings,
        array $config,
        bool $expectedResult
    ): void {
        $context = Context::createDefaultContext();

        $order = $this->createOrder($orderSettings);
        $orderId = $order->getId();
        $orderCollection = new OrderCollection([$order]);
        $orderSearchResult = new EntitySearchResult(OrderDefinition::ENTITY_NAME, 1, $orderCollection, null, new Criteria(), $context);

        $documentConfigSearchResult = $this->createDocumentConfigSearchResult($config, $context);

        $documentConfigRepository = $this->createMock(EntityRepository::class);
        $documentConfigRepository->method('search')->willReturn($documentConfigSearchResult);

        $documentConfigLoaderMock = new DocumentConfigLoader($documentConfigRepository, $this->createMock(EntityRepository::class));

        $ordersLanguageId = [
            [
                'language_id' => Defaults::LANGUAGE_SYSTEM,
                'ids' => $orderId,
            ],
        ];
        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('fetchAllAssociative')->willReturn($ordersLanguageId);

        $orderRepositoryMock = $this->createMock(EntityRepository::class);
        $orderRepositoryMock->method('search')->willReturn($orderSearchResult);

        $validator = $this->createMock(ValidatorInterface::class);
        if (isset($orderSettings['shouldCheckVatIdPattern']) && $orderSettings['shouldCheckVatIdPattern']) {
            $validator->method('validate')->willReturnCallback(function () use ($orderSettings) {
                if ($orderSettings['validVat'] ?? false) {
                    return new ConstraintViolationList();
                }

                return new ConstraintViolationList(
                    [
                        new ConstraintViolation(
                            'VAT ID is invalid',
                            null,
                            [],
                            'vat',
                            'vatId',
                            'invalid'
                        ),
                    ],
                );
            });
        }

        $invoiceRenderer = new InvoiceRenderer(
            $orderRepositoryMock,
            $documentConfigLoaderMock,
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(NumberRangeValueGeneratorInterface::class),
            $connectionMock,
            $this->createMock(DocumentFileRendererRegistry::class),
            $validator,
        );

        $operations = [
            $orderId => new DocumentGenerateOperation(
                $orderId
            ),
        ];

        $result = $invoiceRenderer->render($operations, $context, new DocumentRendererConfig());

        $successResults = $result->getSuccess();
        static::assertCount(1, $successResults);
        static::assertCount(0, $result->getErrors());
        static::assertArrayHasKey($orderId, $successResults);
        static::assertInstanceOf(RenderedDocument::class, $successResults[$orderId]);

        static::assertNotNull($successResults[$orderId]->getOrder());
        static::assertNotNull($successResults[$orderId]->getContext());
        static::assertSame($successResults[$orderId]->getTemplate(), '@Framework/documents/invoice.html.twig');

        if ($expectedResult) {
            static::assertTrue($successResults[$orderId]->getConfig()['intraCommunityDelivery']);
        } else {
            static::assertFalse($successResults[$orderId]->getConfig()['intraCommunityDelivery']);
        }
    }

    public function testLanguageIdChainAssignedCorrectly(): void
    {
        $context = Context::createDefaultContext();

        $order = $this->createOrder([
            'accountType' => CustomerEntity::ACCOUNT_TYPE_PRIVATE,
            'isCountryCompanyTaxFree' => true,
            'setOrderDelivery' => true,
            'setShippingCountry' => true,
            'setEuCountry' => true,
        ]);

        $orderId = $order->getId();
        $orderCollection = new OrderCollection([$order]);
        $orderSearchResult = new EntitySearchResult(OrderDefinition::ENTITY_NAME, 1, $orderCollection, null, new Criteria(), $context);

        $DELanguageId = Uuid::randomHex();

        $ordersLanguageId = [
            [
                'language_id' => $DELanguageId,
                'ids' => $orderId,
            ],
            [
                'language_id' => Defaults::LANGUAGE_SYSTEM,
                'ids' => $orderId,
            ],
        ];

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('fetchAllAssociative')->willReturn($ordersLanguageId);

        $orderRepositoryMock = $this->createMock(EntityRepository::class);
        $orderRepositoryMock->method('search')->willReturnCallback(function (Criteria $criteria, Context $context) use (&$userCallCount, $DELanguageId, $orderSearchResult) {
            ++$userCallCount;

            switch ($userCallCount) {
                case 1:
                    static::assertCount(2, $context->getLanguageIdChain());
                    static::assertContains(Defaults::LANGUAGE_SYSTEM, $context->getLanguageIdChain());
                    static::assertContains($DELanguageId, $context->getLanguageIdChain());

                    break;
                case 2:
                    static::assertCount(1, $context->getLanguageIdChain());
                    static::assertContains(Defaults::LANGUAGE_SYSTEM, $context->getLanguageIdChain());
            }

            return $orderSearchResult;
        });

        $invoiceRenderer = new InvoiceRenderer(
            $orderRepositoryMock,
            new DocumentConfigLoader($this->createMock(EntityRepository::class), $this->createMock(EntityRepository::class)),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(NumberRangeValueGeneratorInterface::class),
            $connectionMock,
            $this->createMock(DocumentFileRendererRegistry::class),
            $this->createMock(ValidatorInterface::class),
        );

        $operations = [
            $orderId => new DocumentGenerateOperation(
                $orderId
            ),
        ];

        $invoiceRenderer->render($operations, $context, new DocumentRendererConfig());
    }

    public function testDoNotForceDocumentCreation(): void
    {
        $context = Context::createDefaultContext();

        $document = new DocumentEntity();
        $document->setId(Uuid::randomHex());

        $order = $this->createOrder([
            'accountType' => CustomerEntity::ACCOUNT_TYPE_PRIVATE,
            'isCountryCompanyTaxFree' => true,
            'setOrderDelivery' => true,
            'setShippingCountry' => true,
            'setEuCountry' => true,
        ]);

        $order->setDocuments(new DocumentCollection([$document]));

        $orderId = $order->getId();
        $orderCollection = new OrderCollection([$order]);
        $orderSearchResult = new EntitySearchResult(OrderDefinition::ENTITY_NAME, 1, $orderCollection, null, new Criteria(), $context);

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('fetchAllAssociative')->willReturn([
            [
                'language_id' => Defaults::LANGUAGE_SYSTEM,
                'ids' => $orderId,
            ],
        ]);

        $orderRepositoryMock = $this->createMock(EntityRepository::class);
        $orderRepositoryMock->method('search')->willReturn($orderSearchResult);

        $documentConfigLoaderMock = new DocumentConfigLoader($this->createMock(EntityRepository::class), $this->createMock(EntityRepository::class));

        $invoiceRenderer = new InvoiceRenderer(
            $orderRepositoryMock,
            $documentConfigLoaderMock,
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(NumberRangeValueGeneratorInterface::class),
            $connectionMock,
            $this->createMock(DocumentFileRendererRegistry::class),
            $this->createMock(ValidatorInterface::class),
        );

        $operations = [
            $orderId => new DocumentGenerateOperation(
                $orderId,
                FileTypes::PDF,
                ['forceDocumentCreation' => false],
            ),
        ];

        $result = $invoiceRenderer->render($operations, $context, new DocumentRendererConfig());

        $successResults = $result->getSuccess();

        static::assertCount(0, $successResults);
    }

    public static function configDataProvider(): \Generator
    {
        yield 'will return true because all necessary configs are made' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => true,
        ];

        yield 'will return false because customer is no B2B customer' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_PRIVATE,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return false because country setting "CompanyTaxFree" is not activated' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => false,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return false because customer address is not part of "Member countries"' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => false,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return false because "intra-Community delivery" label is not activated' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => false,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return false because no order-deliveries exist' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => false,
                'setShippingCountry' => false,
                'setEuCountry' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return false because no shipping-country is set' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => false,
                'setEuCountry' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return false because VAT is invalid' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => true,
                'shouldCheckVatIdPattern' => true,
                'validVat' => false,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => false,
        ];

        yield 'will return true because VAT is valid' => [
            'orderSettings' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'isCountryCompanyTaxFree' => true,
                'setOrderDelivery' => true,
                'setShippingCountry' => true,
                'setEuCountry' => true,
                'shouldCheckVatIdPattern' => true,
                'validVat' => true,
            ],
            'config' => [
                'displayAdditionalNoteDelivery' => true,
                'fileTypes' => ['pdf', 'html'],
            ],
            'expectedResult' => true,
        ];
    }

    /**
     * @param OrderSettings $orderSettings
     */
    private function createOrder(array $orderSettings): OrderEntity
    {
        $orderDeliverId = Uuid::randomHex();

        $salesChannelId = Uuid::randomHex();
        $salesChannelEntity = new SalesChannelEntity();
        $salesChannelEntity->setId($salesChannelId);

        $language = new LanguageEntity();
        $language->setId('language-test-id');
        $localeEntity = new LocaleEntity();
        $localeEntity->setCode('en-GB');
        $language->setLocale($localeEntity);

        $orderId = Uuid::randomHex();
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setVersionId(Defaults::LIVE_VERSION);
        $order->setSalesChannelId($salesChannelId);
        $order->setLanguage($language);
        $order->setLanguageId('language-test-id');

        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());
        $customer->setAccountType($orderSettings['accountType']);
        $orderCustomer = new OrderCustomerEntity();
        $orderCustomer->setOrder($order);
        $orderCustomer->setCustomer($customer);
        $order->setOrderCustomer($orderCustomer);
        $order->setPrimaryOrderDeliveryId($orderDeliverId);

        if ($orderSettings['setOrderDelivery']) {
            $delivery = new OrderDeliveryEntity();
            $delivery->setId($orderDeliverId);
            $deliveries = new OrderDeliveryCollection([$delivery]);
            $order->setDeliveries($deliveries);
            $order->setPrimaryOrderDelivery($delivery);
        }

        if ($orderSettings['setShippingCountry'] && $orderSettings['setOrderDelivery']) {
            $country = new CountryEntity();
            $country->setId(self::COUNTRY_ID);
            if ($orderSettings['setEuCountry']) {
                $country->setIsEu(true);
            } else {
                $country->setIsEu(false);
            }
            $country->setCompanyTax(new TaxFreeConfig($orderSettings['isCountryCompanyTaxFree'], Defaults::CURRENCY, 0));
            $address = new OrderAddressEntity();
            $address->setCountry($country);
            $country->setCheckVatIdPattern($orderSettings['shouldCheckVatIdPattern'] ?? false);
            $address->setVatId('VAT123');
            $delivery->setShippingOrderAddress($address);
        }

        return $order;
    }

    /**
     * @param InvoiceConfig $config
     *
     * @return EntitySearchResult<DocumentBaseConfigCollection>
     */
    private function createDocumentConfigSearchResult(array $config, Context $context): EntitySearchResult
    {
        $documentBaseConfigEntity = new DocumentBaseConfigEntity();
        $documentBaseConfigEntity->setId(Uuid::randomHex());

        $documentBaseConfigSalesChannel = new DocumentBaseConfigSalesChannelEntity();
        $documentBaseConfigSalesChannel->setId(Uuid::randomHex());

        $documentBaseConfigEntity->setSalesChannels(new DocumentBaseConfigSalesChannelCollection([$documentBaseConfigSalesChannel]));
        $documentBaseConfigEntity->setConfig($config);
        $documentBaseConfigCollection = new DocumentBaseConfigCollection([$documentBaseConfigEntity]);

        return new EntitySearchResult(
            DocumentBaseConfigDefinition::ENTITY_NAME,
            1,
            $documentBaseConfigCollection,
            null,
            new Criteria(),
            $context
        );
    }
}
