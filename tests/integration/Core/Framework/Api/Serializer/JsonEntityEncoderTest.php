<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Api\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Rule\RuleDefinition;
use Shopware\Core\Framework\Api\ApiException;
use Shopware\Core\Framework\Api\Serializer\JsonEntityEncoder;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\Api\Serializer\AssertValuesTrait;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\DataAbstractionLayerFieldTestBehaviour;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\AssociationExtension;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\CustomFieldTestDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ExtendableDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ExtendedDefinition;
use Shopware\Core\Framework\Test\DataAbstractionLayer\Field\TestDefinition\ScalarRuntimeExtension;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\User\UserDefinition;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\SerializationFixture;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestBasicStruct;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestBasicWithExtension;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestBasicWithToManyRelationships;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestBasicWithToOneRelationship;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestCollectionWithSelfReference;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestCollectionWithToOneRelationship;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestInternalFieldsAreFiltered;
use Shopware\Tests\Integration\Core\Framework\Api\Serializer\fixtures\TestMainResourceShouldNotBeInIncluded;

/**
 * @internal
 */
class JsonEntityEncoderTest extends TestCase
{
    use AssertValuesTrait;
    use DataAbstractionLayerFieldTestBehaviour;
    use KernelTestBehaviour;

    /**
     * @return array<array<mixed>>
     */
    public static function emptyInputProvider(): array
    {
        return [
            [null],
            ['string'],
            [1],
            [false],
            [new \DateTime()],
            [1.1],
        ];
    }

    #[DataProvider('emptyInputProvider')]
    public function testEncodeWithEmptyInput(mixed $input): void
    {
        $this->expectExceptionObject(ApiException::unsupportedEncoderInput());

        $encoder = static::getContainer()->get(JsonEntityEncoder::class);
        $encoder->encode(new Criteria(), static::getContainer()->get(ProductDefinition::class), $input, SerializationFixture::API_BASE_URL);
    }

    /**
     * @return list<array{class-string<EntityDefinition>, SerializationFixture}>
     */
    public static function complexStructsProvider(): array
    {
        return [
            [MediaDefinition::class, new TestBasicStruct()],
            [UserDefinition::class, new TestBasicWithToManyRelationships()],
            [MediaDefinition::class, new TestBasicWithToOneRelationship()],
            [MediaFolderDefinition::class, new TestCollectionWithSelfReference()],
            [MediaDefinition::class, new TestCollectionWithToOneRelationship()],
            [RuleDefinition::class, new TestInternalFieldsAreFiltered()],
            [UserDefinition::class, new TestMainResourceShouldNotBeInIncluded()],
        ];
    }

    /**
     * @param class-string<EntityDefinition> $definitionClass
     */
    #[DataProvider('complexStructsProvider')]
    public function testEncodeComplexStructs(string $definitionClass, SerializationFixture $fixture): void
    {
        $definition = static::getContainer()->get($definitionClass);
        static::assertInstanceOf(EntityDefinition::class, $definition);
        $encoder = static::getContainer()->get(JsonEntityEncoder::class);
        $actual = $encoder->encode(new Criteria(), $definition, $fixture->getInput(), SerializationFixture::API_BASE_URL);

        $this->assertValues($fixture->getAdminJsonFixtures(), $actual);
    }

    /**
     * Not possible with data provider as we have to manipulate the container, but the data provider run before all tests
     */
    public function testEncodeStructWithExtension(): void
    {
        $this->registerDefinition(ExtendableDefinition::class, ExtendedDefinition::class);
        $extendableDefinition = new ExtendableDefinition();
        $extendableDefinition->addExtension(new AssociationExtension());
        $extendableDefinition->addExtension(new ScalarRuntimeExtension());

        $extendableDefinition->compile(static::getContainer()->get(DefinitionInstanceRegistry::class));
        $fixture = new TestBasicWithExtension();

        $encoder = static::getContainer()->get(JsonEntityEncoder::class);
        $actual = $encoder->encode(new Criteria(), $extendableDefinition, $fixture->getInput(), SerializationFixture::API_BASE_URL);

        unset($actual['apiAlias']);
        static::assertEquals($fixture->getAdminJsonFixtures(), $actual);
        $this->assertValues($fixture->getAdminJsonFixtures(), $actual);
    }

    /**
     * Not possible with data provider as we have to manipulate the container, but the data provider run before all tests
     */
    public function testEncodeStructWithToManyExtension(): void
    {
        $this->registerDefinition(ExtendableDefinition::class, ExtendedDefinition::class);
        $extendableDefinition = new ExtendableDefinition();
        $extendableDefinition->addExtension(new AssociationExtension());

        $extendableDefinition->compile(static::getContainer()->get(DefinitionInstanceRegistry::class));
        $fixture = new TestBasicWithExtension();

        $encoder = static::getContainer()->get(JsonEntityEncoder::class);
        $actual = $encoder->encode(new Criteria(), $extendableDefinition, $fixture->getInput(), SerializationFixture::API_BASE_URL);

        unset($actual['apiAlias']);
        static::assertEquals($fixture->getAdminJsonFixtures(), $actual);
    }

    /**
     * @param array{customFields: mixed}|array{translated: array{customFields: mixed}} $input
     * @param array{customFields: mixed}|array{translated: array{customFields: mixed}} $output
     */
    #[DataProvider('customFieldsProvider')]
    public function testCustomFields(array $input, array $output): void
    {
        $encoder = static::getContainer()->get(JsonEntityEncoder::class);

        $definition = new CustomFieldTestDefinition();
        $definition->compile(static::getContainer()->get(DefinitionInstanceRegistry::class));
        $struct = new class extends Entity {
            use EntityCustomFieldsTrait;
        };
        $struct->assign($input);

        $actual = $encoder->encode(new Criteria(), $definition, $struct, SerializationFixture::API_BASE_URL);

        static::assertSame($output, array_intersect_key($output, $actual));
    }

    /**
     * @return \Generator<string, array{0: array{customFields: mixed}, 1: array{customFields: mixed}}|array{0: array{translated: array{customFields: mixed}}, 1: array{translated: array{customFields: mixed}}}>
     */
    public static function customFieldsProvider(): \Generator
    {
        yield 'Custom field null' => [
            [
                'customFields' => null,
            ],
            [
                'customFields' => null,
            ],
        ];

        yield 'Custom field with empty array' => [
            [
                'customFields' => [],
            ],
            [
                'customFields' => new \stdClass(),
            ],
        ];

        yield 'Custom field with values' => [
            [
                'customFields' => ['bla'],
            ],
            [
                'customFields' => ['bla'],
            ],
        ];

        // translated

        yield 'Custom field translated null' => [
            [
                'translated' => [
                    'customFields' => null,
                ],
            ],
            [
                'translated' => [
                    'customFields' => null,
                ],
            ],
        ];

        yield 'Custom field translated with empty array' => [
            [
                'translated' => [
                    'customFields' => [],
                ],
            ],
            [
                'translated' => [
                    'customFields' => new \stdClass(),
                ],
            ],
        ];

        yield 'Custom field translated with values' => [
            [
                'translated' => [
                    'customFields' => ['bla'],
                ],
            ],
            [
                'translated' => [
                    'customFields' => ['bla'],
                ],
            ],
        ];
    }
}
