<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\SalesChannel\Repository;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Util\AccessKeyHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelType\SalesChannelTypeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('discovery')]
class SalesChannelRepositoryTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntityRepository<SalesChannelCollection>
     */
    private EntityRepository $salesChannelRepository;

    /**
     * @var EntityRepository<CurrencyCollection>
     */
    private EntityRepository $currencyRepository;

    /**
     * @var EntityRepository<LanguageCollection>
     */
    private EntityRepository $languageRepository;

    /**
     * @var EntityRepository<PaymentMethodCollection>
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var EntityRepository<CountryCollection>
     */
    private EntityRepository $countryRepository;

    /**
     * @var EntityRepository<ShippingMethodCollection>
     */
    private EntityRepository $shippingMethodRepository;

    protected function setUp(): void
    {
        $this->salesChannelRepository = static::getContainer()->get('sales_channel.repository');
        $this->currencyRepository = static::getContainer()->get('currency.repository');
        $this->languageRepository = static::getContainer()->get('language.repository');
        $this->paymentMethodRepository = static::getContainer()->get('payment_method.repository');
        $this->countryRepository = static::getContainer()->get('country.repository');
        $this->shippingMethodRepository = static::getContainer()->get('shipping_method.repository');
    }

    public function testCreateSalesChannelTest(): void
    {
        $salesChannelId = Uuid::randomHex();
        $accessKey = AccessKeyHelper::generateAccessKey('sales-channel');
        $secretKey = AccessKeyHelper::generateSecretAccessKey();
        $context = Context::createDefaultContext();

        $name = 'Repository test';
        $cover = 'http://example.org/icon1.jpg';
        $icon = 'sw-icon';
        $screenshots = [
            'http://example.org/image.jpg',
            'http://example.org/image2.jpg',
            'http://example.org/image3.jpg',
        ];
        $typeName = 'test type';
        $manufacturer = 'shopware';
        $description = 'my description';
        $descriptionLong = 'an even longer description';

        $this->salesChannelRepository->upsert([[
            'id' => $salesChannelId,
            'name' => $name,
            'type' => [
                'coverUrl' => $cover,
                'iconName' => $icon,
                'screenshotUrls' => $screenshots,
                'name' => $typeName,
                'manufacturer' => $manufacturer,
                'description' => $description,
                'descriptionLong' => $descriptionLong,
            ],
            'accessKey' => $accessKey,
            'secretAccessKey' => $secretKey,
            'languageId' => Defaults::LANGUAGE_SYSTEM,
            'snippetSetId' => $this->getSnippetSetIdForLocale('en-GB'),
            'currencyId' => Defaults::CURRENCY,
            'paymentMethodId' => $this->getValidPaymentMethodId(),
            'shippingMethodId' => $this->getValidShippingMethodId(),
            'navigationCategoryId' => $this->getValidCategoryId(),
            'countryId' => $this->getValidCountryId(),
            'currencies' => [['id' => Defaults::CURRENCY]],
            'languages' => [['id' => Defaults::LANGUAGE_SYSTEM]],
            'paymentMethods' => [['id' => $this->getValidPaymentMethodId()]],
            'shippingMethods' => [['id' => $this->getValidShippingMethodId()]],
            'countries' => [['id' => $this->getValidCountryId()]],
            'customerGroupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
        ]], $context);

        $criteria1 = new Criteria([$salesChannelId]);
        $criteria1->addAssociation('type');

        $salesChannel = $this->salesChannelRepository->search($criteria1, $context)->get($salesChannelId);

        static::assertInstanceOf(SalesChannelEntity::class, $salesChannel);
        static::assertSame($name, $salesChannel->getName());
        static::assertSame($accessKey, $salesChannel->getAccessKey());

        static::assertInstanceOf(SalesChannelTypeEntity::class, $salesChannel->getType());
        static::assertSame($cover, $salesChannel->getType()->getCoverUrl());
        static::assertSame($icon, $salesChannel->getType()->getIconName());
        static::assertSame($screenshots, $salesChannel->getType()->getScreenshotUrls());
        static::assertSame($typeName, $salesChannel->getType()->getName());
        static::assertSame($manufacturer, $salesChannel->getType()->getManufacturer());
        static::assertSame($description, $salesChannel->getType()->getDescription());
        static::assertSame($descriptionLong, $salesChannel->getType()->getDescriptionLong());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('currency.salesChannels.id', $salesChannelId));
        $currency = $this->currencyRepository->search($criteria, $context);
        static::assertCount(1, $currency);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('language.salesChannels.id', $salesChannelId));
        $language = $this->languageRepository->search($criteria, $context);
        static::assertCount(1, $language);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('payment_method.salesChannels.id', $salesChannelId));
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context);
        static::assertCount(1, $paymentMethod);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('country.salesChannels.id', $salesChannelId));
        $country = $this->countryRepository->search($criteria, $context);
        static::assertCount(1, $country);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shipping_method.salesChannels.id', $salesChannelId));
        $shippingMethod = $this->shippingMethodRepository->search($criteria, $context);
        static::assertCount(1, $shippingMethod);
    }

    public function testTaxCalculationDefault(): void
    {
        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => 'test',
            'typeId' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
            'customerGroupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'currencyId' => Defaults::CURRENCY,
            'paymentMethodId' => $this->getValidPaymentMethodId(),
            'shippingMethodId' => $this->getValidShippingMethodId(),
            'navigationCategoryId' => $this->getValidCategoryId(),
            'countryId' => $this->getValidCountryId(),
            'accessKey' => $id,
            'languages' => [['id' => Defaults::LANGUAGE_SYSTEM]],
        ];

        $this->salesChannelRepository->create([$data], Context::createDefaultContext());

        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->salesChannelRepository
            ->search(new Criteria([$id]), Context::createDefaultContext())
            ->first();

        static::assertSame(SalesChannelDefinition::CALCULATION_TYPE_HORIZONTAL, $salesChannel->getTaxCalculationType());
    }
}
