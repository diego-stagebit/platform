<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Order\Aggregate\OrderDelivery;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDeliveryPosition\OrderDeliveryPositionCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

#[Package('checkout')]
class OrderDeliveryEntity extends Entity
{
    use EntityCustomFieldsTrait;
    use EntityIdTrait;

    protected string $orderId;

    protected string $orderVersionId;

    protected string $shippingOrderAddressId;

    protected string $shippingOrderAddressVersionId;

    protected string $shippingMethodId;

    /**
     * @var array<string>
     */
    protected array $trackingCodes;

    protected \DateTimeInterface $shippingDateEarliest;

    protected \DateTimeInterface $shippingDateLatest;

    protected CalculatedPrice $shippingCosts;

    protected ?OrderAddressEntity $shippingOrderAddress = null;

    protected string $stateId;

    protected ?StateMachineStateEntity $stateMachineState = null;

    protected ?ShippingMethodEntity $shippingMethod = null;

    protected ?OrderEntity $order = null;

    protected ?OrderDeliveryPositionCollection $positions = null;

    protected ?OrderEntity $primaryOrder = null;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getShippingOrderAddressId(): string
    {
        return $this->shippingOrderAddressId;
    }

    public function setShippingOrderAddressId(string $shippingOrderAddressId): void
    {
        $this->shippingOrderAddressId = $shippingOrderAddressId;
    }

    public function getShippingMethodId(): string
    {
        return $this->shippingMethodId;
    }

    public function setShippingMethodId(string $shippingMethodId): void
    {
        $this->shippingMethodId = $shippingMethodId;
    }

    /**
     * @return array<string>
     */
    public function getTrackingCodes(): array
    {
        return $this->trackingCodes;
    }

    /**
     * @param array<string> $trackingCodes
     */
    public function setTrackingCodes(array $trackingCodes): void
    {
        $this->trackingCodes = $trackingCodes;
    }

    public function getShippingDateEarliest(): \DateTimeInterface
    {
        return $this->shippingDateEarliest;
    }

    public function setShippingDateEarliest(\DateTimeInterface $shippingDateEarliest): void
    {
        $this->shippingDateEarliest = $shippingDateEarliest;
    }

    public function getShippingDateLatest(): \DateTimeInterface
    {
        return $this->shippingDateLatest;
    }

    public function setShippingDateLatest(\DateTimeInterface $shippingDateLatest): void
    {
        $this->shippingDateLatest = $shippingDateLatest;
    }

    public function getShippingCosts(): CalculatedPrice
    {
        return $this->shippingCosts;
    }

    public function setShippingCosts(CalculatedPrice $shippingCosts): void
    {
        $this->shippingCosts = $shippingCosts;
    }

    public function getShippingOrderAddress(): ?OrderAddressEntity
    {
        return $this->shippingOrderAddress;
    }

    public function setShippingOrderAddress(OrderAddressEntity $shippingOrderAddress): void
    {
        $this->shippingOrderAddress = $shippingOrderAddress;
    }

    public function getShippingMethod(): ?ShippingMethodEntity
    {
        return $this->shippingMethod;
    }

    public function setShippingMethod(ShippingMethodEntity $shippingMethod): void
    {
        $this->shippingMethod = $shippingMethod;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }

    public function setOrder(OrderEntity $order): void
    {
        $this->order = $order;
    }

    public function getPositions(): ?OrderDeliveryPositionCollection
    {
        return $this->positions;
    }

    public function setPositions(OrderDeliveryPositionCollection $positions): void
    {
        $this->positions = $positions;
    }

    public function getStateId(): string
    {
        return $this->stateId;
    }

    public function setStateId(string $stateId): void
    {
        $this->stateId = $stateId;
    }

    public function getStateMachineState(): ?StateMachineStateEntity
    {
        return $this->stateMachineState;
    }

    public function setStateMachineState(StateMachineStateEntity $stateMachineState): void
    {
        $this->stateMachineState = $stateMachineState;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): void
    {
        $this->orderVersionId = $orderVersionId;
    }

    public function getShippingOrderAddressVersionId(): string
    {
        return $this->shippingOrderAddressVersionId;
    }

    public function setShippingOrderAddressVersionId(string $shippingOrderAddressVersionId): void
    {
        $this->shippingOrderAddressVersionId = $shippingOrderAddressVersionId;
    }

    public function getPrimaryOrder(): ?OrderEntity
    {
        return $this->primaryOrder;
    }

    public function setPrimaryOrder(?OrderEntity $primaryOrder): void
    {
        $this->primaryOrder = $primaryOrder;
    }
}
