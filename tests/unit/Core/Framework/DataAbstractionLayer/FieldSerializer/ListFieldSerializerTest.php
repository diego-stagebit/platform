<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\DataAbstractionLayer\FieldSerializer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ListField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\ListFieldSerializer;
use Shopware\Core\Framework\Util\Json;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(ListFieldSerializer::class)]
class ListFieldSerializerTest extends TestCase
{
    /**
     * @param array<mixed>|null $expected
     */
    #[DataProvider('decodeProvider')]
    public function testDecode(ListField $field, ?string $input, ?array $expected): void
    {
        $serializer = new ListFieldSerializer(
            $this->createMock(ValidatorInterface::class),
            $this->createMock(DefinitionInstanceRegistry::class)
        );

        $actual = $serializer->decode($field, $input);
        static::assertSame($expected, $actual);
    }

    /**
     * @return list<array{0: ListField, 1: string|null, 2: array<mixed>|null}>
     */
    public static function decodeProvider(): array
    {
        return [
            [new ListField('data', 'data'), Json::encode(['foo' => 'bar']), ['bar']],
            [new ListField('data', 'data'), Json::encode([0 => 'bar', 1 => 'foo']), ['bar', 'foo']],
            [new ListField('data', 'data'), Json::encode(['foo' => 1]), [1]],
            [new ListField('data', 'data'), Json::encode(['foo' => 5.3]), [5.3]],
            [new ListField('data', 'data'), Json::encode(['foo' => ['bar' => 'baz']]), [['bar' => 'baz']]],
            [new ListField('data', 'data'), null, null],
        ];
    }
}
