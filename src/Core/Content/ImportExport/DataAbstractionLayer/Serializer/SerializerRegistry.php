<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer;

use Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Entity\AbstractEntitySerializer;
use Shopware\Core\Content\ImportExport\DataAbstractionLayer\Serializer\Field\AbstractFieldSerializer;
use Shopware\Core\Content\ImportExport\ImportExportException;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\Log\Package;

#[Package('fundamentals@after-sales')]
class SerializerRegistry
{
    /**
     * @var array<AbstractEntitySerializer>
     */
    private readonly array $entitySerializers;

    /**
     * @var array<AbstractFieldSerializer>
     */
    private readonly array $fieldSerializers;

    /**
     * @internal
     *
     * @param iterable<AbstractEntitySerializer> $entitySerializers
     * @param iterable<AbstractFieldSerializer> $fieldSerializers
     */
    public function __construct(
        iterable $entitySerializers,
        iterable $fieldSerializers
    ) {
        $this->entitySerializers = \is_array($entitySerializers) ? $entitySerializers : iterator_to_array($entitySerializers);
        $this->fieldSerializers = \is_array($fieldSerializers) ? $fieldSerializers : iterator_to_array($fieldSerializers);
    }

    public function getEntity(string $entity): AbstractEntitySerializer
    {
        foreach ($this->entitySerializers as $serializer) {
            if ($serializer->supports($entity)) {
                $serializer->setRegistry($this);

                return $serializer;
            }
        }

        throw ImportExportException::serializerNotFound($entity);
    }

    public function getFieldSerializer(Field $field): AbstractFieldSerializer
    {
        foreach ($this->fieldSerializers as $serializer) {
            if ($serializer->supports($field)) {
                $serializer->setRegistry($this);

                return $serializer;
            }
        }

        throw ImportExportException::serializerNotFound($field::class);
    }

    /**
     * @return array<AbstractEntitySerializer>
     */
    public function getAllEntitySerializers(): array
    {
        return $this->entitySerializers;
    }

    /**
     * @return array<AbstractFieldSerializer>
     */
    public function getAllFieldSerializers(): array
    {
        return $this->fieldSerializers;
    }
}
