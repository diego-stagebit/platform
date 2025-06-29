<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\FieldSerializer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
class ReferenceVersionFieldSerializerTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testUpdateSerialize(): void
    {
        $ids = new IdsCollection();

        $product = (new ProductBuilder($ids, 'p1'))
            ->price(100)
            ->manufacturer('m1')
            ->build();

        static::getContainer()->get('product.repository')
            ->create([$product], Context::createDefaultContext());

        $connection = static::getContainer()->get(Connection::class);

        $value = $connection->fetchOne('SELECT LOWER(HEX(product_manufacturer_version_id)) FROM product WHERE id = :id', ['id' => $ids->getBytes('p1')]);
        static::assertSame(Defaults::LIVE_VERSION, $value);

        $connection->executeStatement('UPDATE product SET product_manufacturer_version_id = NULL WHERE id = :id', ['id' => $ids->getBytes('p1')]);

        $value = $connection->fetchOne('SELECT LOWER(HEX(product_manufacturer_version_id)) FROM product WHERE id = :id', ['id' => $ids->getBytes('p1')]);
        static::assertNull($value);

        $update = [
            'id' => $ids->get('p1'),
            'manufacturerId' => $ids->get('m1'),
        ];

        static::getContainer()->get('product.repository')
            ->update([$update], Context::createDefaultContext());

        $value = $connection->fetchOne('SELECT LOWER(HEX(product_manufacturer_version_id)) FROM product WHERE id = :id', ['id' => $ids->getBytes('p1')]);
        static::assertSame(Defaults::LIVE_VERSION, $value);
    }
}
