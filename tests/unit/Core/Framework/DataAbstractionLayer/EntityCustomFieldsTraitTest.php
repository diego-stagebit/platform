<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DataAbstractionLayer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(EntityCustomFieldsTrait::class)]
class EntityCustomFieldsTraitTest extends TestCase
{
    public function testGetCustomFieldValues(): void
    {
        $entity = new MyTraitEntity('id', ['foo' => 'bar', 'bar' => 'foo', 'baz' => 'baz']);

        static::assertSame(['foo' => 'bar'], $entity->getCustomFieldsValues('foo'));

        static::assertSame([], $entity->getCustomFieldsValues('not-exists'));

        static::assertSame(['foo' => 'bar', 'bar' => 'foo'], $entity->getCustomFieldsValues('foo', 'bar'));
    }

    public function testGetCustomFieldValue(): void
    {
        $entity = new MyTraitEntity('id', ['foo' => 'bar', 'bar' => 'foo', 'baz' => 'baz']);

        static::assertSame('bar', $entity->getCustomFieldsValue('foo'));

        static::assertNull($entity->getCustomFieldsValue('not-exists'));
    }

    public function testGetCustomFieldsValue(): void
    {
        $entity = new MyTraitEntity('id', ['foo' => 'bar', 'bar' => 'foo', 'baz' => 'baz']);

        static::assertSame(['foo' => 'bar'], $entity->getCustomFieldsValues('foo'));

        static::assertNull($entity->getCustomFieldsValue('not-exists'));

        static::assertSame(['foo' => 'bar', 'bar' => 'foo'], $entity->getCustomFieldsValues('foo', 'bar'));
    }

    public function testChangeCustomFields(): void
    {
        $entity = new MyTraitEntity('id', [
            'foo' => 'bar',
            'bar' => ['foo' => 'bar'],
        ]);

        $entity->changeCustomFields(['foo' => 'baz']);
        static::assertSame('baz', $entity->getCustomFieldsValue('foo'));
        static::assertSame(['foo' => 'bar'], $entity->getCustomFieldsValue('bar'));

        $entity->changeCustomFields(['bar' => ['foo' => 'baz']]);
        static::assertSame(['foo' => 'baz'], $entity->getCustomFieldsValue('bar'));

        $entity->changeCustomFields(['foo' => 'baz', 'bar' => ['foo' => 'foo'], 'baz' => 'baz']);
        static::assertSame(['foo' => 'baz', 'bar' => ['foo' => 'foo'], 'baz' => 'baz'], $entity->getCustomFields());
    }

    public function testGetCustomFieldsValueWithTranslatedFlag(): void
    {
        $entity = new MyTraitEntity(
            'id',
            ['foo' => 'bar', 'baz' => 'orig', 'null-value' => 'should-be-overwritten'],
            ['customFields' => ['foo' => 'translated-bar', 'baz' => 'translated-baz', 'null-value' => null]]
        );

        static::assertSame('translated-bar', $entity->getTranslatedCustomFieldsValue('foo'));
        static::assertSame('translated-baz', $entity->getTranslatedCustomFieldsValue('baz'));
        static::assertNull($entity->getTranslatedCustomFieldsValue('null-value'));
        static::assertNull($entity->getTranslatedCustomFieldsValue('not-exists'));

        $entity = new MyTraitEntity(
            'id',
            ['foo' => 'bar'],
            ['customFields' => []]
        );
        static::assertNull($entity->getTranslatedCustomFieldsValue('foo'));
        static::assertNull($entity->getTranslatedCustomFieldsValue('not-exists'));
    }
}

/**
 * @internal
 */
class MyTraitEntity extends Entity
{
    use EntityCustomFieldsTrait;

    /**
     * @param array<string, mixed>|null $customFields
     * @param array<string, mixed> $translated
     */
    public function __construct(
        string $_uniqueIdentifier,
        ?array $customFields = [],
        array $translated = [],
    ) {
        $this->_uniqueIdentifier = $_uniqueIdentifier;
        $this->customFields = $customFields;
        $this->translated = $translated;
    }
}
