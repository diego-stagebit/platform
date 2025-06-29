<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Store\Struct;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\FrameworkException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Store\Struct\ExtensionStruct;
use Shopware\Core\Framework\Store\Struct\PermissionCollection;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(ExtensionStruct::class)]
class ExtensionStructTest extends TestCase
{
    public function testFromArray(): void
    {
        $detailData = $this->getDetailFixture();
        $struct = ExtensionStruct::fromArray($detailData);

        static::assertSame('Tes12SWCloudApp1', $struct->getName());
    }

    /**
     * @param array<string, string> $badValues
     */
    #[DataProvider('badValuesProvider')]
    public function testItThrowsOnMissingData(array $badValues): void
    {
        static::expectException(FrameworkException::class);
        ExtensionStruct::fromArray($badValues);
    }

    public function testItCategorizesThePermissionCollectionWhenStructIsSerialized(): void
    {
        $detailData = $this->getDetailFixture();
        $detailData['permissions'] = new PermissionCollection($detailData['permissions']);

        $extension = ExtensionStruct::fromArray($detailData);

        static::assertInstanceOf(PermissionCollection::class, $extension->getPermissions());

        $serializedExtension = json_decode(json_encode($extension, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $categorizedPermissions = $serializedExtension['permissions'];

        static::assertCount(3, $categorizedPermissions);
        static::assertSame([
            'product',
            'promotion',
            'other',
        ], array_keys($categorizedPermissions));
    }

    /**
     * @return iterable<list<array<string, string>>>
     */
    public static function badValuesProvider(): iterable
    {
        yield [[]];
        yield [['name' => 'foo']];
        yield [['type' => 'foo']];
        yield [['name' => 'foo', 'label' => 'bar']];
        yield [['label' => 'bar', 'type' => 'foobar']];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDetailFixture(): array
    {
        $content = file_get_contents(__DIR__ . '/../_fixtures/responses/extension-detail.json');
        static::assertIsString($content);

        return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
