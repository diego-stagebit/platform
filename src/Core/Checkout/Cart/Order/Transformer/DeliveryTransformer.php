<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Order\Transformer;

use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Order\IdStruct;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

/**
 * @phpstan-type DeliveryArray  array{id?: string, shippingDateEarliest: string, shippingDateLatest: string, shippingOrderAddress?: mixed, shippingCosts: CalculatedPrice, positions: array<array{price: CalculatedPrice}>, stateId: string}
 */
#[Package('checkout')]
class DeliveryTransformer
{
    /**
     * @param array<string, array<string, mixed>> $lineItems
     * @param array<int|string, array<string, string|array<mixed>>> $addresses
     *
     * @return array<int, DeliveryArray>
     */
    public static function transformCollection(
        DeliveryCollection $deliveries,
        array $lineItems,
        string $stateId,
        Context $context,
        array $addresses = []
    ): array {
        $output = [];
        foreach ($deliveries as $delivery) {
            $output[] = self::transform($delivery, $lineItems, $stateId, $context, $addresses);
        }

        return $output;
    }

    /**
     * @param array<string, array<string, mixed>> $lineItems
     * @param array<int|string, array<string, string|array<mixed>>> $addresses
     *
     * @return DeliveryArray
     */
    public static function transform(
        Delivery $delivery,
        array $lineItems,
        string $stateId,
        Context $context,
        array $addresses = []
    ): array {
        $addressId = $delivery->getLocation()->getAddress()?->getId();
        $shippingAddress = null;

        if ($addressId !== null && \array_key_exists($addressId, $addresses)) {
            $shippingAddress = $addresses[$addressId];
        } elseif ($delivery->getLocation()->getAddress() !== null) {
            $shippingAddress = AddressTransformer::transform($delivery->getLocation()->getAddress());
        }

        $originalAddressId = $delivery->getExtensionOfType(
            OrderConverter::ORIGINAL_ADDRESS_ID,
            IdStruct::class
        )?->getId();

        $originalAddressVersionId = $delivery->getExtensionOfType(
            OrderConverter::ORIGINAL_ADDRESS_VERSION_ID,
            IdStruct::class
        )?->getId();

        $deliveryData = [
            'id' => self::getId($delivery),
            'shippingDateEarliest' => $delivery->getDeliveryDate()->getEarliest()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'shippingDateLatest' => $delivery->getDeliveryDate()->getLatest()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'shippingMethodId' => $delivery->getShippingMethod()->getId(),
            'shippingOrderAddress' => $shippingAddress,
            'shippingCosts' => $delivery->getShippingCosts(),
            'positions' => [],
            'stateId' => $stateId,
        ];

        if ($originalAddressId !== null && $originalAddressVersionId !== null) {
            $deliveryData['shippingOrderAddressId'] = $originalAddressId;
            $deliveryData['shippingOrderAddressVersionId'] = $originalAddressVersionId;
        }

        $deliveryData = array_filter($deliveryData, fn ($item) => $item !== null);

        foreach ($delivery->getPositions() as $position) {
            if (!isset($lineItems[$position->getIdentifier()])) {
                continue;
            }

            $deliveryData['positions'][] = [
                'id' => self::getId($position),
                'price' => $position->getPrice(),
                'orderLineItemId' => $lineItems[$position->getIdentifier()]['id'],
                'orderLineItemVersionId' => $context->getVersionId(),
            ];
        }

        return $deliveryData;
    }

    private static function getId(Struct $struct): ?string
    {
        return $struct->getExtensionOfType(
            OrderConverter::ORIGINAL_ID,
            IdStruct::class
        )?->getId();
    }
}
