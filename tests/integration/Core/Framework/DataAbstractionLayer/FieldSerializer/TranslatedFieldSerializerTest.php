<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\FieldSerializer;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\TranslatedFieldSerializer;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommandQueue;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteParameterBag;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * @internal
 */
class TranslatedFieldSerializerTest extends TestCase
{
    use KernelTestBehaviour;

    protected TranslatedFieldSerializer $serializer;

    protected WriteContext $writeContext;

    protected function setUp(): void
    {
        $serializer = static::getContainer()->get(TranslatedFieldSerializer::class);
        static::assertInstanceOf(TranslatedFieldSerializer::class, $serializer);
        $this->serializer = $serializer;
        $this->writeContext = WriteContext::createFromContext(Context::createDefaultContext());
    }

    public function testNormalizeNullData(): void
    {
        $data = $this->normalize(['description' => null]);

        static::assertSame([
            'description' => null,
            'translations' => [
                $this->writeContext->getContext()->getLanguageId() => [
                    'description' => null,
                ],
            ],
        ], $data);
    }

    public function testNormalizeStringData(): void
    {
        $data = $this->normalize(['description' => 'abc']);

        static::assertSame([
            'description' => 'abc',
            'translations' => [
                $this->writeContext->getContext()->getLanguageId() => [
                    'description' => 'abc',
                ],
            ],
        ], $data);
    }

    public function testNormalizeArrayData(): void
    {
        $languageId = $this->writeContext->getContext()->getLanguageId();

        $data = $this->normalize([
            'description' => [
                $languageId => 'abc',
            ],
        ]);

        static::assertSame([
            'description' => [
                $languageId => 'abc',
            ],
            'translations' => [
                $languageId => [
                    'description' => 'abc',
                ],
            ],
        ], $data);
    }

    /**
     * @param array<string, string|array<string, string>|null> $data
     *
     * @return array<string, string|array<string, string>|null>
     */
    private function normalize(array $data): array
    {
        $field = new TranslatedField('description');
        $bag = new WriteParameterBag(
            static::getContainer()->get(ProductDefinition::class),
            $this->writeContext,
            '',
            new WriteCommandQueue()
        );

        return $this->serializer->normalize($field, $data, $bag);
    }
}
