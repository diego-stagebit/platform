<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\SalutationSerializer;
use Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\SerializerRegistry;
use Shopware\Core\Content\ImportExport\Struct\Config;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationDefinition;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class SalutationSerializerTest extends TestCase
{
    use KernelTestBehaviour;

    /**
     * @var EntityRepository<SalutationCollection>
     */
    private EntityRepository $salutationRepository;

    private SalutationSerializer $serializer;

    protected function setUp(): void
    {
        $this->salutationRepository = static::getContainer()->get('salutation.repository');
        $serializerRegistry = static::getContainer()->get(SerializerRegistry::class);

        $this->serializer = new SalutationSerializer($this->salutationRepository);
        $this->serializer->setRegistry($serializerRegistry);
    }

    public function testSimple(): void
    {
        $config = new Config([], [], []);

        $salutation = [
            'id' => Uuid::randomHex(),
            'salutationKey' => 'mrs',
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'displayName' => 'Mrs.',
                    'letterName' => 'Dear Mrs.',
                ],
            ],
        ];

        $serialized = iterator_to_array($this->serializer->serialize($config, $this->salutationRepository->getDefinition(), $salutation));

        $deserialized = iterator_to_array($this->serializer->deserialize($config, $this->salutationRepository->getDefinition(), $serialized));

        $expectedTranslations = $salutation['translations'][Defaults::LANGUAGE_SYSTEM];
        $actualTranslations = $deserialized['translations'][Defaults::LANGUAGE_SYSTEM];
        unset($salutation['translations'], $deserialized['translations']);

        static::assertSame($salutation, $deserialized);
        static::assertSame($expectedTranslations, $actualTranslations);
    }

    public function testDeserializeOnlySalutationKey(): void
    {
        $config = new Config([], [], []);

        $salutation = [
            'salutationKey' => 'mrs',
        ];

        $deserialized = iterator_to_array($this->serializer->deserialize($config, $this->salutationRepository->getDefinition(), $salutation));

        static::assertSame($salutation['salutationKey'], $deserialized['salutationKey']);
        static::assertArrayHasKey('id', $deserialized);

        $criteria = (new Criteria())->addFilter(new EqualsFilter('salutationKey', 'mrs'));
        $salutationId = $this->salutationRepository->searchIds($criteria, Context::createDefaultContext())->firstId();

        static::assertSame($salutationId, $deserialized['id']);
    }

    public function testUsesNotSpecifiedAsFallback(): void
    {
        $config = new Config([], [], []);

        $salutation = [
            'salutationKey' => 'unknown',
        ];

        $deserialized = iterator_to_array($this->serializer->deserialize($config, $this->salutationRepository->getDefinition(), $salutation));

        static::assertArrayNotHasKey('salutationKey', $deserialized);
        static::assertArrayHasKey('id', $deserialized);

        $criteria = (new Criteria())->addFilter(new EqualsFilter('salutationKey', 'not_specified'));
        $salutationId = $this->salutationRepository->searchIds($criteria, Context::createDefaultContext())->firstId();

        static::assertSame($salutationId, $deserialized['id']);
    }

    public function testSupportsOnlySalutation(): void
    {
        $serializer = new SalutationSerializer(static::getContainer()->get('salutation.repository'));

        $definitionRegistry = static::getContainer()->get(DefinitionInstanceRegistry::class);
        foreach ($definitionRegistry->getDefinitions() as $definition) {
            $entity = $definition->getEntityName();

            if ($entity === SalutationDefinition::ENTITY_NAME) {
                static::assertTrue($serializer->supports($entity));
            } else {
                static::assertFalse(
                    $serializer->supports($entity),
                    SalutationDefinition::class . ' should not support ' . $entity
                );
            }
        }
    }
}
