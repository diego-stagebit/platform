<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Write;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationCollection;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationEntity;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotDefinition;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\FieldConfig;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturerTranslation\ProductManufacturerTranslationDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\MissingTranslationLanguageException;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\Aggregate\CurrencyTranslation\CurrencyTranslationDefinition;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\System\Language\LanguageDefinition;
use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class TranslationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $productRepository;

    private EntityRepository $currencyRepository;

    private EntityRepository $languageRepository;

    /**
     * @var EntityRepository<CategoryCollection>
     */
    private EntityRepository $categoryRepository;

    private EntityRepository $pageRepository;

    /**
     * @var EntityRepository<CmsSlotCollection>
     */
    private EntityRepository $slotRepository;

    private Context $context;

    private IdsCollection $ids;

    private string $deLanguageId;

    protected function setUp(): void
    {
        $this->productRepository = static::getContainer()->get('product.repository');
        $this->currencyRepository = static::getContainer()->get('currency.repository');
        $this->languageRepository = static::getContainer()->get('language.repository');
        $this->categoryRepository = static::getContainer()->get('category.repository');
        $this->pageRepository = static::getContainer()->get('cms_page.repository');
        $this->slotRepository = static::getContainer()->get('cms_slot.repository');

        $this->context = Context::createDefaultContext();
        $this->ids = new IdsCollection();

        $this->deLanguageId = $this->getDeDeLanguageId();
    }

    public function testCurrencyWithTranslationViaLocale(): void
    {
        $name = 'US Dollar';
        $shortName = 'FOO';

        $data = [
            'factor' => 1,
            'symbol' => '$',
            'decimalPrecision' => 2,
            'isoCode' => 'FOO',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                'en-GB' => [
                    'name' => 'US Dollar',
                    'shortName' => 'FOO',
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(1, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $payload = $translations->getPayloads()[0];
        static::assertArrayHasKey('name', $payload);
        static::assertArrayHasKey('shortName', $payload);
        static::assertSame($name, $payload['name']);
        static::assertSame($shortName, $payload['shortName']);
    }

    public function testCurrencyWithTranslationViaLanguageIdSimpleNotation(): void
    {
        $name = 'US Dollar';
        $shortName = 'FOO';

        $data = [
            'factor' => 1,
            'decimalPrecision' => 2,
            'symbol' => '$',
            'isoCode' => 'FOO',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'name' => 'US Dollar',
                    'shortName' => 'FOO',
                    'isoCode' => 'FOO',
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(1, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $payload = $translations->getPayloads()[0];

        static::assertArrayHasKey('name', $payload);
        static::assertArrayHasKey('shortName', $payload);
        static::assertSame($name, $payload['name']);
        static::assertSame($shortName, $payload['shortName']);
    }

    public function testCurrencyWithTranslationMergeViaLocaleAndLanguageId(): void
    {
        $name = 'US Dollar';
        $shortName = 'FOO';

        $data = [
            'factor' => 1,
            'decimalPrecision' => 2,
            'symbol' => '$',
            'isoCode' => 'FOO',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                'en-GB' => [
                    'name' => $name,
                ],

                Defaults::LANGUAGE_SYSTEM => [
                    'shortName' => $shortName,
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(1, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $payload = $translations->getPayloads()[0];

        static::assertArrayHasKey('name', $payload);
        static::assertArrayHasKey('shortName', $payload);
        static::assertSame($name, $payload['name']);
        static::assertSame($shortName, $payload['shortName']);
    }

    public function testCurrencyWithTranslationMergeOverwriteViaLocaleAndLanguageId(): void
    {
        $name = 'US Dollar';
        $shortName = 'FOO';

        $data = [
            'factor' => 1,
            'decimalPrecision' => 2,
            'symbol' => '$',
            'isoCode' => 'FOO',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                'en-GB' => [
                    'shortName' => $shortName,
                ],

                Defaults::LANGUAGE_SYSTEM => [
                    'name' => $name,
                    'shortName' => 'should be overwritten',
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(1, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $payload = $translations->getPayloads()[0];
        static::assertArrayHasKey('name', $payload);
        static::assertArrayHasKey('shortName', $payload);
        static::assertSame($name, $payload['name']);
        static::assertSame($shortName, $payload['shortName']);
    }

    public function testCurrencyWithTranslationViaLocaleAndLanguageId(): void
    {
        $germanLanguageId = Uuid::randomHex();
        $germanName = 'Amerikanischer Dollar';
        $germanShortName = 'US Dollar Deutsch';
        $englishName = 'US Dollar';
        $englishShortName = 'FOO';

        $this->languageRepository->create(
            [[
                'id' => $germanLanguageId,
                'name' => 'de-DE',
                'locale' => [
                    'id' => Uuid::randomHex(),
                    'code' => 'x-tst_DE2',
                    'name' => 'test name',
                    'territory' => 'test territory',
                ],
                'translationCode' => [
                    'id' => Uuid::randomHex(),
                    'code' => 'x-tst_DE3',
                    'name' => 'test name',
                    'territory' => 'test territory',
                ],
            ]],
            $this->context
        );

        $data = [
            'factor' => 1,
            'decimalPrecision' => 2,
            'symbol' => '$',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'isoCode' => 'FOO',
            'translations' => [
                'en-GB' => [
                    'name' => $englishName,
                    'shortName' => $englishShortName,
                ],

                $germanLanguageId => [
                    'name' => $germanName,
                    'shortName' => $germanShortName,
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(2, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains($germanLanguageId, $languageIds);
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $payload1 = $translations->getPayloads()[0];
        $payload2 = $translations->getPayloads()[1];

        static::assertArrayHasKey('name', $payload1);
        static::assertArrayHasKey('shortName', $payload1);
        static::assertSame($germanName, $payload1['name']);
        static::assertSame($germanShortName, $payload1['shortName']);

        static::assertArrayHasKey('name', $payload2);
        static::assertArrayHasKey('shortName', $payload2);
        static::assertSame($englishName, $payload2['name']);
        static::assertSame($englishShortName, $payload2['shortName']);
    }

    public function testCurrencyTranslationWithCachingAndInvalidation(): void
    {
        $englishName = 'US Dollar';
        $englishShortName = 'FOO';

        $data = [
            'factor' => 1,
            'symbol' => '$',
            'decimalPrecision' => 2,
            'isoCode' => 'FOO',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                'en-GB' => [
                    'name' => $englishName,
                    'shortName' => $englishShortName,
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(1, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $payload = $translations->getPayloads()[0];
        static::assertArrayHasKey('name', $payload);
        static::assertArrayHasKey('shortName', $payload);
        static::assertSame($englishName, $payload['name']);
        static::assertSame($englishShortName, $payload['shortName']);

        $germanLanguageId = Uuid::randomHex();
        $data = [
            'id' => $germanLanguageId,
            'translationCode' => [
                'name' => 'Niederländisch',
                'code' => 'x-nl_NL',
                'territory' => 'Niederlande',
            ],
            'localeId' => $this->getLocaleIdOfSystemLanguage(),
            'name' => 'nl-NL',
        ];

        $this->languageRepository->create([$data], $this->context);

        $nlName = 'Amerikaans Dollar';
        $nlShortName = 'US Dollar German';

        $data = [
            'factor' => 1,
            'symbol' => '$',
            'decimalPrecision' => 2,
            'isoCode' => 'BAR',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'default',
                    'shortName' => 'def',
                ],
                'x-nl_NL' => [
                    'name' => $nlName,
                    'shortName' => $nlShortName,
                ],
            ],
        ];

        $result = $this->currencyRepository->create([$data], $this->context);

        $currencies = $result->getEventByEntityName(CurrencyDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $currencies);
        static::assertCount(1, $currencies->getIds());

        $translations = $result->getEventByEntityName(CurrencyTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(2, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains($germanLanguageId, $languageIds);

        $payload = $translations->getPayloads();

        static::assertArrayHasKey('name', $payload[0]);
        static::assertArrayHasKey('shortName', $payload[0]);
        static::assertSame('default', $payload[0]['name']);
        static::assertSame('def', $payload[0]['shortName']);

        static::assertArrayHasKey('name', $payload[1]);
        static::assertArrayHasKey('shortName', $payload[1]);
        static::assertSame($nlName, $payload[1]['name']);
        static::assertSame($nlShortName, $payload[1]['shortName']);
    }

    public function testTranslationsOfUnknownLanguageCodesAreSkipped(): void
    {
        $data = [
            'factor' => 1,
            'symbol' => '$',
            'decimalPrecision' => 2,
            'isoCode' => 'TST',
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'US Dollar',
                    'shortName' => 'DEF',
                ],
                'en-US' => [
                    'name' => 'US Dollar',
                    'shortName' => 'FOO',
                ],
            ],
        ];

        $events = $this->currencyRepository->create([$data], $this->context);
        $writtenCurrencies = $events->getEventByEntityName('currency');
        $writtenCurrencyTranslations = $events->getEventByEntityName('currency_translation');

        static::assertInstanceOf(EntityWrittenEvent::class, $writtenCurrencies);
        static::assertInstanceOf(EntityWrittenEvent::class, $writtenCurrencyTranslations);
        static::assertCount(1, $writtenCurrencies->getIds());
        static::assertCount(1, $writtenCurrencyTranslations->getIds());
    }

    public function testProductWithDifferentTranslations(): void
    {
        $germanLanguageId = Uuid::randomHex();

        $result = $this->languageRepository->create(
            [[
                'id' => $germanLanguageId,
                'name' => 'de-DE',
                'locale' => [
                    'id' => Uuid::randomHex(),
                    'code' => 'x-de_DE',
                    'name' => 'locale',
                    'territory' => 'territory',
                ],
                'translationCode' => [
                    'id' => Uuid::randomHex(),
                    'code' => 'x-de_DE2',
                    'name' => 'test name',
                    'territory' => 'test territory',
                ],
            ]],
            $this->context
        );

        $languages = $result->getEventByEntityName(LanguageDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $languages);
        static::assertCount(1, array_unique($languages->getIds()));
        static::assertContains($germanLanguageId, $languages->getIds());

        $data = [
            'id' => '79dc5e0b5bd1404a9dec7841f6254c7e',
            'productNumber' => Uuid::randomHex(),
            'manufacturer' => [
                'id' => 'e4e8988334a34bb48d397b41a611084f',
                'name' => 'Das blaue Haus',
                'link' => 'http://www.blaueshaus-shop.de',
            ],
            'tax' => [
                'id' => 'fe4eb0fd92a7417ebf8720a5148aae64',
                'taxRate' => 19,
                'name' => '19%',
            ],
            'price' => [
                [
                    'currencyId' => Defaults::CURRENCY, 'gross' => 7.9899999999999824,
                    'net' => 6.7142857142857,
                    'linked' => false,
                ],
            ],
            'translations' => [
                $germanLanguageId => [
                    'id' => '4f1bcf3bc0fb4e62989e88b3bd37d1a2',
                    'productId' => '79dc5e0b5bd1404a9dec7841f6254c7e',
                    'name' => 'Backform gelb',
                    'description' => 'inflo decertatio. His Manus dilabor do, eia lumen, sed Desisto qua evello sono hinc, ars his misericordite.',
                ],
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'Test En',
                ],
            ],
            'cover' => [
                'id' => 'd610dccf27754a7faa5c22d7368e6d8f',
                'productId' => '79dc5e0b5bd1404a9dec7841f6254c7e',
                'position' => 1,
                'media' => [
                    'id' => '4b2252d11baa49f3a62e292888f5e439',
                    'title' => 'Backform-gelb',
                ],
            ],
            'active' => true,
            'markAsTopseller' => false,
            'stock' => 45,
            'weight' => 0,
            'minPurchase' => 1,
            'shippingFree' => false,
            'purchasePrices' => [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => 0,
                    'net' => 0,
                    'linked' => true,
                ],
            ],
        ];

        $result = $this->productRepository->create([$data], $this->context);

        $products = $result->getEventByEntityName(ProductDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $products);
        static::assertCount(1, $products->getIds());

        $translations = $result->getEventByEntityName(ProductManufacturerTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(1, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);

        $translations = $result->getEventByEntityName(ProductTranslationDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $translations);
        static::assertCount(2, $translations->getIds());
        $languageIds = array_column($translations->getPayloads(), 'languageId');
        static::assertContains(Defaults::LANGUAGE_SYSTEM, $languageIds);
        static::assertContains($germanLanguageId, $languageIds);
    }

    public function testTranslationsAssociationOfMissingRoot(): void
    {
        $category = [
            'id' => Uuid::randomHex(),
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => ['name' => 'system'],
            ],
        ];
        $this->categoryRepository->create([$category], $this->context);
        $catSystem = $this->categoryRepository
            ->search(new Criteria([$category['id']]), $this->context)
            ->getEntities()
            ->first();

        static::assertInstanceOf(CategoryEntity::class, $catSystem);
        static::assertSame('system', $catSystem->getName());
        static::assertSame('system', $catSystem->getTranslated()['name']);

        $deDeContext = new Context(new SystemSource(), [], Defaults::CURRENCY, [$this->deLanguageId, Defaults::LANGUAGE_SYSTEM]);
        $catDeDe = $this->categoryRepository
            ->search(new Criteria([$category['id']]), $deDeContext)
            ->getEntities()
            ->first();

        static::assertNotNull($catDeDe);
        static::assertInstanceOf(CategoryEntity::class, $catDeDe);
        static::assertNull($catDeDe->getName());
        static::assertSame('system', $catDeDe->getTranslated()['name']);
    }

    public function testUpsert(): void
    {
        $data = [
            'id' => '79dc5e0b5bd1404a9dec7841f6254c7e',
            'productNumber' => Uuid::randomHex(),
            'manufacturer' => [
                'id' => 'e4e8988334a34bb48d397b41a611084f',
                'name' => 'Das blaue Haus',
                'link' => 'http://www.blaueshaus-shop.de',
            ],
            'tax' => [
                'id' => 'fe4eb0fd92a7417ebf8720a5148aae64',
                'taxRate' => 19,
                'name' => '19%',
            ],
            'price' => [
                [
                    'currencyId' => Defaults::CURRENCY, 'gross' => 7.9899999999999824,
                    'net' => 6.7142857142857,
                    'linked' => false,
                ],
            ],
            'translations' => [
                [
                    'productId' => '79dc5e0b5bd1404a9dec7841f6254c7e',
                    'name' => 'Backform gelb',
                    'description' => 'inflo decertatio. His Manus dilabor do, eia lumen, sed Desisto qua evello sono hinc, ars his misericordite.',
                    'descriptionLong' => '
sors capulus se Quies, mox qui Sentus dum confirmo do iam. Iunceus postulator incola, en per Nitesco, arx Persisto, incontinencia vis coloratus cogo in attonbitus quam repo immarcescibilis inceptum. Ego Vena series sudo ac Nitidus. Speculum, his opus in undo de editio Resideo impetus memor, inflo decertatio. His Manus dilabor do, eia lumen, sed Desisto qua evello sono hinc, ars his misericordite.
',
                    'language' => [
                        'id' => Defaults::LANGUAGE_SYSTEM,
                        'name' => 'system',
                    ],
                ],
            ],
            'media' => [
                [
                    'id' => 'd610dccf27754a7faa5c22d7368e6d8f',
                    'productId' => '79dc5e0b5bd1404a9dec7841f6254c7e',
                    'isCover' => true,
                    'position' => 1,
                    'media' => [
                        'id' => '4b2252d11baa49f3a62e292888f5e439',
                        'name' => 'Backform-gelb',
                        'album' => [
                            'id' => 'a7104eb19fc649fa86cf6fe6c26ad65a',
                            'name' => 'Artikel',
                            'position' => 2,
                            'createThumbnails' => false,
                            'thumbnailSize' => '200x200;600x600;1280x1280',
                            'icon' => 'sprite-inbox',
                            'thumbnailHighDpi' => true,
                            'thumbnailQuality' => 90,
                            'thumbnailHighDpiQuality' => 60,
                        ],
                    ],
                ],
            ],
            'active' => true,
            'markAsTopseller' => false,
            'stock' => 45,
            'weight' => 0,
            'minPurchase' => 1,
            'shippingFree' => false,
            'purchasePrices' => [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => 0,
                    'net' => 0,
                    'linked' => true,
                ],
            ],
        ];

        $productRepo = static::getContainer()->get('product.repository');
        $affected = $productRepo->upsert([$data], Context::createDefaultContext());

        static::assertNotNull($affected->getEventByEntityName(LanguageDefinition::ENTITY_NAME));

        static::assertNotNull($affected->getEventByEntityName(ProductDefinition::ENTITY_NAME));
        static::assertNotNull($affected->getEventByEntityName(ProductTranslationDefinition::ENTITY_NAME));

        static::assertNotNull($affected->getEventByEntityName(TaxDefinition::ENTITY_NAME));

        static::assertNotNull($affected->getEventByEntityName(ProductManufacturerDefinition::ENTITY_NAME));
        static::assertNotNull($affected->getEventByEntityName(ProductManufacturerTranslationDefinition::ENTITY_NAME));

        static::assertNotNull($affected->getEventByEntityName(ProductMediaDefinition::ENTITY_NAME));
        static::assertNotNull($affected->getEventByEntityName(MediaDefinition::ENTITY_NAME));
    }

    public function testMissingTranslationLanguageViolation(): void
    {
        $cat = [
            'name' => 'foo',
            'translations' => [
                ['name' => 'translation without a language or languageId'],
            ],
        ];

        $exception = null;

        try {
            $this->categoryRepository->create([$cat], $this->context);
        } catch (WriteException $e) {
            $exception = $e;
        }

        static::assertInstanceOf(WriteException::class, $exception);
        $innerExceptions = $exception->getExceptions();
        static::assertInstanceOf(MissingTranslationLanguageException::class, $innerExceptions[0]);
    }

    public function testJsonFieldOnRootEntity(): void
    {
        $page = [
            'type' => 'landing_page',
            'sections' => [
                [
                    'type' => 'default',
                    'position' => 0,
                    'blocks' => [
                        [
                            'type' => 'foo',
                            'position' => 1,
                            'slots' => [
                                [
                                    'id' => Uuid::randomHex(),
                                    'type' => 'foo',
                                    'slot' => 'bar',
                                    'config' => [],
                                ],
                                [
                                    'id' => Uuid::randomHex(),
                                    'type' => 'foo',
                                    'slot' => 'bar',
                                    'config' => [
                                        'var1' => [
                                            'source' => FieldConfig::SOURCE_MAPPED,
                                            'value' => 'foo',
                                        ],
                                    ],
                                ],
                                [
                                    'id' => Uuid::randomHex(),
                                    'type' => 'foo',
                                    'slot' => 'bar',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->pageRepository->create([$page], $this->context);

        $events = $result->getEventByEntityName(CmsSlotDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $events);
        $ids = $events->getIds();

        static::assertCount(3, $ids);

        $searchResult = $this->slotRepository->search(new Criteria($ids), $this->context);

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][0]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertSame([], $slot->getConfig());

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][1]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertEquals(['var1' => ['source' => FieldConfig::SOURCE_MAPPED, 'value' => 'foo']], $slot->getConfig());

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][2]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertNull($slot->getConfig());
    }

    public function testJsonFieldWithDifferentLanguages(): void
    {
        $page = [
            'type' => 'landing_page',
            'sections' => [
                [
                    'type' => 'default',
                    'position' => 0,
                    'blocks' => [
                        [
                            'type' => 'foo',
                            'position' => 1,
                            'slots' => [
                                [
                                    'id' => Uuid::randomHex(),
                                    'type' => 'foo',
                                    'slot' => 'bar',
                                    'translations' => [
                                        Defaults::LANGUAGE_SYSTEM => ['config' => []],
                                        $this->deLanguageId => ['config' => []],
                                    ],
                                ],
                                [
                                    'id' => Uuid::randomHex(),
                                    'type' => 'foo',
                                    'slot' => 'bar',
                                    'translations' => [
                                        Defaults::LANGUAGE_SYSTEM => ['config' => ['var1' => ['source' => FieldConfig::SOURCE_MAPPED, 'value' => 'en']]],
                                        $this->deLanguageId => ['config' => ['var1' => ['source' => FieldConfig::SOURCE_MAPPED, 'value' => 'de']]],
                                    ],
                                ],
                                [
                                    'id' => Uuid::randomHex(),
                                    'type' => 'foo',
                                    'slot' => 'bar',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->pageRepository->create([$page], $this->context);

        $events = $result->getEventByEntityName(CmsSlotDefinition::ENTITY_NAME);
        static::assertInstanceOf(EntityWrittenEvent::class, $events);
        $ids = $events->getIds();

        static::assertCount(3, $ids);

        // validate english translations

        $searchResult = $this->slotRepository->search(new Criteria($ids), $this->context);

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][0]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertSame([], $slot->getConfig());

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][1]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertEquals(['var1' => ['source' => FieldConfig::SOURCE_MAPPED, 'value' => 'en']], $slot->getConfig());

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][2]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertNull($slot->getConfig());

        // validate german translations

        $germanContext = new Context(new SystemSource(), [], Defaults::CURRENCY, [$this->deLanguageId, Defaults::LANGUAGE_SYSTEM]);
        $searchResult = $this->slotRepository->search(new Criteria($ids), $germanContext);

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][0]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertSame([], $slot->getConfig());

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][1]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertEquals(['var1' => ['source' => FieldConfig::SOURCE_MAPPED, 'value' => 'de']], $slot->getConfig());

        $slot = $searchResult->getEntities()->get($page['sections'][0]['blocks'][0]['slots'][2]['id']);
        static::assertInstanceOf(CmsSlotEntity::class, $slot);
        static::assertNull($slot->getConfig());
    }

    public function testTranslationValuesHavePriorityOverDefaultValueWithIsoCodes(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => 'default',
            'translations' => [
                'en-GB' => [
                    'name' => 'en translation',
                ],
                'de-DE' => [
                    'name' => 'de übersetzung',
                ],
            ],
        ];

        $this->categoryRepository->create([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $category = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();

        static::assertInstanceOf(CategoryEntity::class, $category);
        static::assertInstanceOf(CategoryTranslationCollection::class, $category->getTranslations());

        $enTranslation = $category->getTranslations()->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $enTranslation);
        static::assertSame('en translation', $enTranslation->getName());

        $deTranslation = $category->getTranslations()->filterByLanguageId($this->getDeDeLanguageId())->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $deTranslation);
        static::assertSame('de übersetzung', $deTranslation->getName());
    }

    public function testTranslationValuesHavePriorityOverDefaultValueWithIds(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => 'default',
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'en translation',
                ],
                $this->getDeDeLanguageId() => [
                    'name' => 'de übersetzung',
                ],
            ],
        ];

        $this->categoryRepository->create([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $category = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $category);

        static::assertInstanceOf(CategoryTranslationCollection::class, $category->getTranslations());

        $enTranslation = $category->getTranslations()->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $enTranslation);
        static::assertSame('en translation', $enTranslation->getName());

        $deTranslation = $category->getTranslations()->filterByLanguageId($this->getDeDeLanguageId())->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $deTranslation);
        static::assertSame('de übersetzung', $deTranslation->getName());
    }

    public function testTranslationValuesHavePriorityOverDefaultValuesWithIds(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => [
                Defaults::LANGUAGE_SYSTEM => 'default',
            ],
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'en translation',
                ],
                $this->getDeDeLanguageId() => [
                    'name' => 'de übersetzung',
                ],
            ],
        ];

        $this->categoryRepository->create([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $category = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $category);

        static::assertInstanceOf(CategoryTranslationCollection::class, $category->getTranslations());

        $enTranslation = $category->getTranslations()->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $enTranslation);
        static::assertSame('en translation', $enTranslation->getName());

        $deTranslation = $category->getTranslations()->filterByLanguageId($this->getDeDeLanguageId())->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $deTranslation);
        static::assertSame('de übersetzung', $deTranslation->getName());
    }

    public function testDefaultValueWithLocaleHasPriorityOverTranslationValueWithId(): void
    {
        $context = Context::createDefaultContext();

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => [
                'en-GB' => 'default',
            ],
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'name' => 'en translation',
                ],
                $this->getDeDeLanguageId() => [
                    'name' => 'de übersetzung',
                ],
            ],
        ];

        $this->categoryRepository->create([$data], $context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $category = $this->categoryRepository
            ->search($criteria, $context)
            ->getEntities()
            ->first();
        static::assertInstanceOf(CategoryEntity::class, $category);

        static::assertInstanceOf(CategoryTranslationCollection::class, $category->getTranslations());

        $enTranslation = $category->getTranslations()->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $enTranslation);
        static::assertSame('default', $enTranslation->getName());

        $deTranslation = $category->getTranslations()->filterByLanguageId($this->getDeDeLanguageId())->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $deTranslation);
        static::assertSame('de übersetzung', $deTranslation->getName());
    }

    public function testWriteWithInheritedTranslationCode(): void
    {
        $this->languageRepository->upsert([
            [
                'id' => $this->ids->get('language-parent'),
                'name' => 'parent',
                'locale' => [
                    'id' => $this->ids->get('language-locale'),
                    'code' => 'language-locale',
                    'name' => 'language-locale',
                    'territory' => 'language-locale',
                ],
                'translationCodeId' => $this->ids->get('language-locale'),
            ],
            [
                'id' => $this->ids->get('language-child'),
                'name' => 'child',
                'parentId' => $this->ids->get('language-parent'),
                'localeId' => $this->ids->get('language-locale'),
                'translationCodeId' => null,
            ],
        ], $this->context);

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => [
                'en-GB' => 'default',
                'language-locale' => 'parent language',
            ],
        ];

        $this->categoryRepository->create([$data], $this->context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $category = $this->categoryRepository
            ->search($criteria, $this->context)
            ->getEntities()
            ->first();

        static::assertInstanceOf(CategoryEntity::class, $category);

        $translations = $category->getTranslations();
        static::assertInstanceOf(CategoryTranslationCollection::class, $translations);

        $enTranslation = $translations->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $enTranslation);
        static::assertSame('default', $enTranslation->getName());

        $childTranslation = $translations->filterByLanguageId($this->ids->get('language-parent'))->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $childTranslation);
        static::assertSame('parent language', $childTranslation->getName());

        $childTranslation = $translations->filterByLanguageId($this->ids->get('language-child'))->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $childTranslation);
        static::assertNull($childTranslation->getName());
    }

    public function testWriteWithInheritedTranslationCodeAndChildLanguage(): void
    {
        $this->languageRepository->upsert([
            [
                'id' => $this->ids->get('language-parent'),
                'name' => 'parent',
                'locale' => [
                    'id' => $this->ids->get('language-locale'),
                    'code' => 'language-locale',
                    'name' => 'language-locale',
                    'territory' => 'language-locale',
                ],
                'translationCodeId' => $this->ids->get('language-locale'),
            ],
            [
                'id' => $this->ids->get('language-child'),
                'name' => 'child',
                'parentId' => $this->ids->get('language-parent'),
                'localeId' => $this->ids->get('language-locale'),
                'translationCodeId' => null,
            ],
        ], $this->context);

        $id = Uuid::randomHex();
        $data = [
            'id' => $id,
            'name' => [
                'en-GB' => 'default',
                'language-locale' => 'parent language',
                $this->ids->get('language-child') => 'child language',
            ],
        ];

        $this->categoryRepository->create([$data], $this->context);

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('translations');

        $category = $this->categoryRepository
            ->search($criteria, $this->context)
            ->getEntities()
            ->first();

        static::assertInstanceOf(CategoryEntity::class, $category);

        $translations = $category->getTranslations();
        static::assertInstanceOf(CategoryTranslationCollection::class, $translations);

        $enTranslation = $translations->filterByLanguageId(Defaults::LANGUAGE_SYSTEM)->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $enTranslation);
        static::assertSame('default', $enTranslation->getName());

        $childTranslation = $translations->filterByLanguageId($this->ids->get('language-parent'))->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $childTranslation);
        static::assertSame('parent language', $childTranslation->getName());

        $childTranslation = $translations->filterByLanguageId($this->ids->get('language-child'))->first();
        static::assertInstanceOf(CategoryTranslationEntity::class, $childTranslation);
        static::assertSame('child language', $childTranslation->getName());
    }
}
