<?php
declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\Snippet;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use Shopware\Core\Framework\Test\TestCaseBase\BasicTestDataBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\Extension\StorefrontSnippetsExtension;
use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;
use Shopware\Core\System\Snippet\Files\SnippetFileCollection;
use Shopware\Core\System\Snippet\Filter\SnippetFilterFactory;
use Shopware\Core\System\Snippet\SnippetException;
use Shopware\Core\System\Snippet\SnippetService;
use Shopware\Storefront\Theme\DatabaseSalesChannelThemeLoader;
use Shopware\Tests\Integration\Core\System\Snippet\Mock\MockSnippetFile;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;

/**
 * @internal
 */
class SnippetServiceTest extends TestCase
{
    use BasicTestDataBehaviour;
    use DatabaseTransactionBehaviour;
    use KernelTestBehaviour;

    private const LONG_SNIPPET = 'Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te feugait nulla facilisi. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat. Ut wisi enim ad minim veniam, quis nostrud exerci tation ullamcorper suscipit lobortis nisl ut aliquip ex ea commodo consequat. Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te feugait nulla facilisi. Nam liber tempor cum soluta nobis eleifend option congue nihil imperdiet doming id quod mazim placerat facer possim assum. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat. Ut wisi enim ad minim veniam, quis nostrud exerci tation ullamcorper suscipit lobortis nisl ut aliquip ex ea commodo consequat. Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, At accusam aliquyam diam diam dolore dolores duo eirmod eos erat, et nonumy sed tempor et et invidunt justo labore Stet clita ea et gubergren, kasd magna no rebum. sanctus sea sed takimata ut vero voluptua. est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat. Consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et iusto';

    protected function tearDown(): void
    {
        MockSnippetFile::cleanup();
    }

    public function testStorefrontSnippetsExtensionPre(): void
    {
        $locale = 'en-GB';

        $service = $this->getSnippetService(
            new MockSnippetFile(
                $locale,
                $locale,
                (string) json_encode([
                    'foo' => [
                        'baz' => 'foo_baz_default0',
                        'bas' => 'foo_bas_default1',
                    ],
                    'bar' => 'bar_default2',
                ])
            )
        );

        $snippetSetId = $this->getSnippetSetIdForLocale($locale);
        static::assertNotNull($snippetSetId);

        $snippetRepository = static::getContainer()->get('snippet.repository');
        $snippetRepository->create([
            [
                'translationKey' => 'foo.bas',
                'value' => 'foo_bas_override_db',
                'author' => 'test',
                'setId' => $snippetSetId,
            ],
        ], Context::createDefaultContext());

        $listener = function (StorefrontSnippetsExtension $event): void {
            $event->snippets['foo.baz'] = 'foo_baz_override0';
            $event->snippets['foo.bas'] = 'foo_bas_override1';
        };

        $eventDispatcher = $this->getContainer()->get('event_dispatcher');

        $eventDispatcher->addListener(ExtensionDispatcher::pre(StorefrontSnippetsExtension::NAME), $listener);

        $snippets = $service->getStorefrontSnippets($this->getCatalog([], $locale), $snippetSetId);

        static::assertSame([
            'foo.baz' => 'foo_baz_override0',
            'foo.bas' => 'foo_bas_override_db',
            'bar' => 'bar_default2',
        ], $snippets);

        $eventDispatcher->removeListener(ExtensionDispatcher::pre(StorefrontSnippetsExtension::NAME), $listener);

        $snippetRepository->delete([
            ['setId' => $snippetSetId],
        ], Context::createDefaultContext());
    }

    public function testStorefrontSnippetsExtensionPost(): void
    {
        $locale = 'en-GB';
        $service = $this->getSnippetService(
            new MockSnippetFile(
                $locale,
                $locale,
                (string) json_encode([
                    'foo' => [
                        'bar' => 'foo_baz_default0',
                        'bas' => 'foo_bas_default1',
                    ],
                    'baz' => ['bar' => 'baz_bar_default2'],
                ])
            )
        );
        $snippetSetId = $this->getSnippetSetIdForLocale($locale);
        static::assertNotNull($snippetSetId);
        $listener = function (StorefrontSnippetsExtension $event): void {
            $event->result['foo.bar'] = 'foo_bar_override';
        };

        $eventDispatcher = $this->getContainer()->get('event_dispatcher');
        $eventDispatcher->addListener(ExtensionDispatcher::post(StorefrontSnippetsExtension::NAME), $listener);

        $snippets = $service->getStorefrontSnippets($this->getCatalog([], $locale), $snippetSetId);

        static::assertSame([
            'foo.bar' => 'foo_bar_override',
            'foo.bas' => 'foo_bas_default1',
            'baz.bar' => 'baz_bar_default2',
        ], $snippets);

        $eventDispatcher->removeListener(ExtensionDispatcher::post(StorefrontSnippetsExtension::NAME), $listener);
    }

    public function testGetStorefrontSnippetsForNotExistingSnippetSet(): void
    {
        $snippetSetId = Uuid::randomHex();
        $this->expectException(SnippetException::class);
        $this->expectExceptionMessage(\sprintf('Snippet set with ID "%s" not found.', $snippetSetId));

        $this->getSnippetService()->getStorefrontSnippets($this->getCatalog([], 'en-GB'), $snippetSetId);
    }

    public function testGetRegionFilterItems(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'test.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getRegionFilterItems(Context::createDefaultContext());

        static::assertSame([
            'bar',
            'foo',
            'test',
        ], $result);
    }

    public function testGetAuthors(): void
    {
        $snippetFile = new MockSnippetFile('foo', '{}');
        $snippetFile2 = new MockSnippetFile('Admin', '{}');

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.123',
            'value' => 'foo_123',
            'author' => 'test',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile, $snippetFile2);
        $result = $service->getAuthors(Context::createDefaultContext());

        static::assertCount(4, $result);

        static::assertContains('shopware', $result);
        static::assertContains('test', $result);
        static::assertContains('foo', $result);
        static::assertContains('Admin', $result);
    }

    /**
     * @param array<int, array<int, MessageCatalogue|array<int|string, string>>> $expectedResult
     */
    #[DataProvider('dataProviderForTestGetStoreFrontSnippets')]
    public function testGetStoreFrontSnippets(MessageCatalogueInterface $catalog, array $expectedResult): void
    {
        $service = $this->getSnippetService(new MockSnippetFile('de-DE'), new MockSnippetFile('en-GB'));

        static::assertNotNull($this->getSnippetSetIdForLocale('en-GB'));
        $result = $service->getStorefrontSnippets($catalog, $this->getSnippetSetIdForLocale('en-GB'));

        static::assertSame($expectedResult, $result);
    }

    public function testGetStoreFrontSnippetsOverriddenFromDB(): void
    {
        $service = $this->getSnippetService(new MockSnippetFile('de-DE'), new MockSnippetFile('en-GB'));

        $snippetSetId = $this->getSnippetSetIdForLocale('en-GB');
        static::assertNotNull($snippetSetId);

        $snippetRepository = static::getContainer()->get('snippet.repository');
        $snippetRepository->create([
            [
                'translationKey' => 'a',
                'value' => 'test',
                'author' => 'test',
                'setId' => $snippetSetId,
            ],
            [
                'translationKey' => 'b',
                'value' => '',
                'author' => 'test',
                'setId' => $snippetSetId,
            ],
        ], Context::createDefaultContext());

        $result = $service->getStorefrontSnippets(
            new MessageCatalogue('en-GB', ['messages' => ['a' => 'a', 'b' => 'b']]),
            $snippetSetId
        );

        static::assertSame(['a' => 'test', 'b' => ''], $result);
    }

    public function testStorefrontSnippetFallback(): void
    {
        $service = $this->getSnippetService(
            new MockSnippetFile('test-fallback-en', 'en-GB', (string) json_encode([
                'foo' => 'en-foo',
                'not-exists' => 'en-bar',
                'storefront' => [
                    'account' => [
                        'overview' => 'Overview',
                    ],
                    'checkout' => [
                        'item' => 'Item',
                    ],
                ],
            ])),
            new MockSnippetFile('test-fallback-de', 'de-DE', (string) json_encode([
                'storefront' => [
                    'account' => [
                        'overview' => 'Übersicht',
                    ],
                    'home' => [
                        'title' => 'Home',
                    ],
                ],
            ]))
        );

        $catalog = new MessageCatalogue(
            'en-GB',
            [
                'messages' => [
                    'foo' => 'catalog',
                    'bar' => 'catalog',
                ],
            ]
        );

        $snippetSetId = $this->getSnippetSetIdForLocale('de-DE');

        static::assertNotNull($snippetSetId);
        $result = $service->getStorefrontSnippets($catalog, $snippetSetId, 'en-GB');

        static::assertEquals(
            [
                'foo' => 'catalog',
                'bar' => 'catalog',
                'not-exists' => 'en-bar',
                'storefront.account.overview' => 'Übersicht',
                'storefront.checkout.item' => 'Item',
                'storefront.home.title' => 'Home',
            ],
            $result
        );
    }

    /**
     * @return array<int, array<int, MessageCatalogue|array<int|string, string>>>
     */
    public static function dataProviderForTestGetStoreFrontSnippets(): array
    {
        return [
            [new MessageCatalogue('en-GB', []), []],
            [new MessageCatalogue('en-GB', ['messages' => ['a' => 'a']]), ['a' => 'a']],
            [new MessageCatalogue('en-GB', ['messages' => ['a' => 'a', 'b' => 'b']]), ['a' => 'a', 'b' => 'b']],
        ];
    }

    public function testGetAuthorsWithoutDBAuthors(): void
    {
        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.123',
            'value' => 'foo_123',
            'author' => 'test',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService();
        $result = $service->getAuthors(Context::createDefaultContext());

        static::assertCount(2, $result);

        static::assertContains('shopware', $result);
        static::assertContains('test', $result);
    }

    public function testGetAuthorsFileAuthors(): void
    {
        $snippetFile = new MockSnippetFile('foo', '{}');
        $snippetFile2 = new MockSnippetFile('Admin', '{}');

        $service = $this->getSnippetService($snippetFile, $snippetFile2);
        $result = $service->getAuthors(Context::createDefaultContext());

        static::assertCount(2, $result);

        static::assertContains('foo', $result);
        static::assertContains('Admin', $result);
    }

    public function testGetListMergesFromFileAndDb(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "bar": "foo_bar"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.baz',
            'value' => 'foo_baz',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], []);

        static::assertSame(2, $result['total']);
        $this->assertSnippetResult($result, 'foo.bar', $fooId, 'foo_bar', 'foo_bar', 'foo_bar');
        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', '', 'foo_baz');
    }

    public function testGetListDbOverwritesFile(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "bar": "foo_bar"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.bar',
            'value' => 'foo_baz',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], []);

        static::assertSame(1, $result['total']);
        $this->assertSnippetResult($result, 'foo.bar', $fooId, 'foo_baz', '', 'foo_bar');
    }

    public function testGetListWithMultipleSets(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "bar": "foo_bar"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $barId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $connection->insert('snippet_set', [
            'id' => $barId,
            'name' => 'bar',
            'base_file' => 'bar',
            'iso' => 'bar',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'bar.baz',
            'value' => 'bar_baz',
            'author' => 'shopware',
            'snippet_set_id' => $barId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], []);

        static::assertSame(2, $result['total']);
        $this->assertSnippetResult($result, 'foo.bar', $fooId, 'foo_bar', 'foo_bar', 'foo_bar');
        $this->assertSnippetResult($result, 'bar.baz', $barId, 'bar_baz', '', 'bar_baz');
    }

    public function testGetListWithSameTranslationKeyInMultipleSets(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "bar": "foo_bar"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $barId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $connection->insert('snippet_set', [
            'id' => $barId,
            'name' => 'bar',
            'base_file' => 'bar',
            'iso' => 'bar',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.bar',
            'value' => 'bar_baz',
            'author' => 'shopware',
            'snippet_set_id' => $barId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], []);

        static::assertSame(1, $result['total']);
        foreach ($result['data']['foo.bar'] as $snippetSetData) {
            if ($snippetSetData['setId'] === Uuid::fromBytesToHex($fooId)) {
                static::assertSame('foo_bar', $snippetSetData['value']);

                continue;
            }
            if ($snippetSetData['setId'] === Uuid::fromBytesToHex($barId)) {
                static::assertSame('bar_baz', $snippetSetData['value']);

                continue;
            }

            static::assertEmpty($snippetSetData['value']);
        }
    }

    public function testGetListWithPagination(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "bar": "foo_bar",
        "foo": "foo_foo",
        "baz": "foo_baz",
        "bas": "foo_bas"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.test',
            'value' => 'foo_test',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 3, Context::createDefaultContext(), [], []);

        static::assertSame(5, $result['total']);
        static::assertCount(3, $result['data']);
        $data = $result['data'];

        $result = $service->getList(2, 3, Context::createDefaultContext(), [], []);
        static::assertSame(5, $result['total']);
        static::assertCount(2, $result['data']);
        $data = [...$data, ...$result['data']];

        $result = $service->getList(4, 3, Context::createDefaultContext(), [], []);
        static::assertSame(5, $result['total']);
        static::assertCount(0, $result['data']);

        $this->assertSnippetResult(['data' => $data], 'foo.bar', $fooId, 'foo_bar', 'foo_bar', 'foo_bar');
        $this->assertSnippetResult(['data' => $data], 'foo.foo', $fooId, 'foo_foo', 'foo_foo', 'foo_foo');
        $this->assertSnippetResult(['data' => $data], 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult(['data' => $data], 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult(['data' => $data], 'foo.test', $fooId, 'foo_test', '', 'foo_test');
    }

    public function testGetListSortsByTranslationKey(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], [
            'sortBy' => 'translationKey',
            'sortDirection' => 'ASC',
        ]);

        static::assertSame(4, $result['total']);

        $this->assertSnippetResult($result, 'bar.zz', $fooId, 'bar_zz', 'bar_zz', 'bar_zz');
        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult($result, 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult($result, 'foo.ab', $fooId, 'foo_ab', '', 'foo_ab');

        static::assertSame([
            'bar.zz',
            'foo.ab',
            'foo.bas',
            'foo.baz',
        ], array_keys($result['data']));
    }

    public function testGetListSortsByTranslationKeyDESC(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], [
            'sortBy' => 'translationKey',
            'sortDirection' => 'DESC',
        ]);

        static::assertSame(4, $result['total']);

        $this->assertSnippetResult($result, 'bar.zz', $fooId, 'bar_zz', 'bar_zz', 'bar_zz');
        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult($result, 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult($result, 'foo.ab', $fooId, 'foo_ab', '', 'foo_ab');

        static::assertSame([
            'foo.baz',
            'foo.bas',
            'foo.ab',
            'bar.zz',
        ], array_keys($result['data']));
    }

    public function testGetListSortsBySnippetSetId(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], [
            'sortBy' => Uuid::fromBytesToHex($fooId),
            'sortDirection' => 'ASC',
        ]);

        static::assertSame(4, $result['total']);

        $this->assertSnippetResult($result, 'bar.zz', $fooId, 'bar_zz', 'bar_zz', 'bar_zz');
        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult($result, 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult($result, 'foo.ab', $fooId, 'foo_ab', '', 'foo_ab');

        $this->assertFirstSnippetSetIdEquals($result, $fooId);

        static::assertSame([
            'bar.zz',
            'foo.ab',
            'foo.bas',
            'foo.baz',
        ], array_keys($result['data']));
    }

    public function testGetListSortsBySnippetSetIdDESC(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), [], [
            'sortBy' => Uuid::fromBytesToHex($fooId),
            'sortDirection' => 'DESC',
        ]);

        static::assertSame(4, $result['total']);

        $this->assertFirstSnippetSetIdEquals($result, $fooId);

        $this->assertSnippetResult($result, 'bar.zz', $fooId, 'bar_zz', 'bar_zz', 'bar_zz');
        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult($result, 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult($result, 'foo.ab', $fooId, 'foo_ab', '', 'foo_ab');

        static::assertSame([
            'foo.baz',
            'foo.bas',
            'foo.ab',
            'bar.zz',
        ], array_keys($result['data']));
    }

    public function testGetListIgnoresSortingForNotExistingSnippetSetId(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $result = $this->getSnippetService($snippetFile)->getList(1, 25, Context::createDefaultContext(), [], [
            'sortBy' => Uuid::randomHex(),
            'sortDirection' => 'ASC',
        ]);

        static::assertSame(4, $result['total']);

        $this->assertSnippetResult($result, 'bar.zz', $fooId, 'bar_zz', 'bar_zz', 'bar_zz');
        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult($result, 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult($result, 'foo.ab', $fooId, 'foo_ab', '', 'foo_ab');

        static::assertSame([
            'bar.zz',
            'foo.ab',
            'foo.bas',
            'foo.baz',
        ], array_keys($result['data']));
    }

    public function testGetListFilters(): void
    {
        $snippetFile = new MockSnippetFile(
            'foo',
            'foo',
            <<<json
{
    "foo": {
        "baz": "foo_baz",
        "bas": "foo_bas"
    },
    "bar": {
        "zz": "bar_zz"
    }
}
json
        );

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => 'foo_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'bar.ab',
            'value' => 'bar_ab',
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $service = $this->getSnippetService($snippetFile);
        $result = $service->getList(1, 25, Context::createDefaultContext(), ['namespace' => ['foo']], []);

        static::assertSame(3, $result['total']);

        $this->assertSnippetResult($result, 'foo.baz', $fooId, 'foo_baz', 'foo_baz', 'foo_baz');
        $this->assertSnippetResult($result, 'foo.bas', $fooId, 'foo_bas', 'foo_bas', 'foo_bas');
        $this->assertSnippetResult($result, 'foo.ab', $fooId, 'foo_ab', '', 'foo_ab');
    }

    public function testGetEmptyList(): void
    {
        $service = $this->getSnippetService(new MockSnippetFile('foo'));

        $result = $service->getList(0, 25, Context::createDefaultContext(), [], []);

        static::assertSame(['total' => 0, 'data' => []], $result);
    }

    public function testTermFilterLargeSnippetNoMatch(): void
    {
        $snippetFile = new MockSnippetFile('foo');

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => self::LONG_SNIPPET,
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $result = $this->getSnippetService($snippetFile)->getList(1, 25, Context::createDefaultContext(), ['term' => 'asdf'], []);

        static::assertSame(0, $result['total']);
        static::assertEmpty($result['data']);
    }

    public function testTermFilterLargeSnippetMatches(): void
    {
        $snippetFile = new MockSnippetFile('foo');

        $fooId = Uuid::randomBytes();
        $connection = static::getContainer()->get(Connection::class);

        $connection->insert('snippet_set', [
            'id' => $fooId,
            'name' => 'foo',
            'base_file' => 'foo',
            'iso' => 'foo',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $connection->insert('snippet', [
            'id' => Uuid::randomBytes(),
            'translation_key' => 'foo.ab',
            'value' => self::LONG_SNIPPET,
            'author' => 'shopware',
            'snippet_set_id' => $fooId,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $result = $this->getSnippetService($snippetFile)->getList(1, 25, Context::createDefaultContext(), ['term' => 'consetetur'], []);

        static::assertSame(1, $result['total']);
        static::assertSame(self::LONG_SNIPPET, $result['data']['foo.ab'][0]['value']);
    }

    /**
     * @param array<array<string>> $messages
     */
    private function getCatalog(array $messages, string $local): MessageCatalogueInterface
    {
        return new MessageCatalogue($local, $messages);
    }

    /**
     * @param array<mixed> $result
     */
    private function assertSnippetResult(
        array $result,
        string $translationKey,
        string $snippetSetId,
        string $value,
        string $originValue,
        string $resetValue
    ): void {
        foreach ($result['data'][$translationKey] ?? [] as $snippetSetData) {
            if ($snippetSetData['setId'] !== Uuid::fromBytesToHex($snippetSetId)) {
                static::assertEmpty($snippetSetData['value']);
            } else {
                static::assertSame($value, $snippetSetData['value']);
                static::assertSame($originValue, $snippetSetData['origin']);
                static::assertSame($resetValue, $snippetSetData['resetTo']);
            }
        }
    }

    private function getSnippetService(AbstractSnippetFile ...$snippetFiles): SnippetService
    {
        $collection = new SnippetFileCollection();
        foreach ($snippetFiles as $file) {
            $collection->add($file);
        }

        return new SnippetService(
            static::getContainer()->get(Connection::class),
            $collection,
            static::getContainer()->get('snippet.repository'),
            static::getContainer()->get('snippet_set.repository'),
            static::getContainer()->get(SnippetFilterFactory::class),
            static::getContainer(),
            static::getContainer()->get(ExtensionDispatcher::class),
            static::getContainer()->has(DatabaseSalesChannelThemeLoader::class) ? static::getContainer()->get(
                DatabaseSalesChannelThemeLoader::class
            ) : null
        );
    }

    /**
     * @param array<mixed> $result
     */
    private function assertFirstSnippetSetIdEquals(array $result, string $fooId): void
    {
        foreach ($result['data'] as $data) {
            static::assertSame(Uuid::fromBytesToHex($fooId), $data[0]['setId']);
        }
    }
}
