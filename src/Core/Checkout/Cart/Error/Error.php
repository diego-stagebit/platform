<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Error;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\AssignArrayTrait;
use Shopware\Core\Framework\Struct\CreateFromTrait;
use Shopware\Core\Framework\Struct\JsonSerializableTrait;

#[Package('checkout')]
abstract class Error extends \Exception implements \JsonSerializable
{
    // allows to assign array data to this object
    use AssignArrayTrait;

    // allows to create a new instance with all data of the provided object
    use CreateFromTrait;

    // allows json_encode and to decode object via json serializer
    use JsonSerializableTrait;

    final public const LEVEL_NOTICE = 0;

    final public const LEVEL_WARNING = 10;

    final public const LEVEL_ERROR = 20;

    /**
     * The trace has to be cleaned up to remove service references that are not serializable.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $ref = new \ReflectionClass($this);

        $data = [];
        foreach ($ref->getProperties() as $property) {
            $data[$property->getName()] = $property->getValue($this);
        }

        unset($data['trace']);

        return $data;
    }

    abstract public function getId(): string;

    abstract public function getMessageKey(): string;

    abstract public function getLevel(): int;

    abstract public function blockOrder(): bool;

    public function blockResubmit(): bool
    {
        return $this->blockOrder();
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function getParameters(): array;

    public function getRoute(): ?ErrorRoute
    {
        return null;
    }

    /**
     * Persistent Errors are passed between the shopping cart calculation processes and then displayed to the user.
     *
     * Such errors are used when a validation of the shopping cart takes place and a change is made to the shopping cart in the same step. This happens, for example, in the Product Processor.
     * If a product is invalid, an error is placed in the shopping cart and the product is removed.
     * The error therefore occurs only once and must remain persistent until it is displayed to the user.
     *
     * Non-persistent errors, on the other hand, do not make any changes to the shopping cart, so that this error occurs again and again during the calculation until the user has made the changes himself.
     */
    public function isPersistent(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = get_object_vars($this);
        $data['key'] = $this->getId();
        $data['level'] = $this->getLevel();
        $data['message'] = $this->getMessage();
        $data['messageKey'] = $this->getMessageKey();
        $data['parameters'] = $this->getParameters();
        $data['block'] = $this->blockOrder();
        $data['blockResubmit'] = $this->blockResubmit();

        if ($route = $this->getRoute()) {
            $data['route'] = [
                'key' => $route->getKey(),
                'params' => $route->getParams(),
            ];
        }

        unset($data['file'], $data['line']);

        return $data;
    }
}
