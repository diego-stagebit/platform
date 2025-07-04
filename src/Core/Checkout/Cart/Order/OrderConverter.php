<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Order;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\Event\SalesChannelContextAssembledEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Order\Transformer\AddressTransformer;
use Shopware\Core\Checkout\Cart\Order\Transformer\CartTransformer;
use Shopware\Core\Checkout\Cart\Order\Transformer\CustomerTransformer;
use Shopware\Core\Checkout\Cart\Order\Transformer\DeliveryTransformer;
use Shopware\Core\Checkout\Cart\Order\Transformer\LineItemTransformer;
use Shopware\Core\Checkout\Cart\Order\Transformer\TransactionTransformer;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderException;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Checkout\Promotion\Cart\PromotionCollector;
use Shopware\Core\Content\Product\Cart\ProductCartProcessor;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[Package('checkout')]
class OrderConverter
{
    final public const CART_CONVERTED_TO_ORDER_EVENT = 'cart.convertedToOrder.event';

    final public const CART_TYPE = 'recalculation';

    final public const ORIGINAL_ID = 'originalId';

    final public const ORIGINAL_ADDRESS_ID = 'originalAddressId';

    final public const ORIGINAL_ADDRESS_VERSION_ID = 'originalAddressVersionId';

    final public const ORIGINAL_ORDER_NUMBER = 'originalOrderNumber';

    final public const ORIGINAL_DOWNLOADS = 'originalDownloads';

    final public const ADMIN_EDIT_ORDER_PERMISSIONS = [
        ProductCartProcessor::ALLOW_PRODUCT_PRICE_OVERWRITES => true,
        ProductCartProcessor::SKIP_PRODUCT_RECALCULATION => true,
        DeliveryProcessor::SKIP_DELIVERY_PRICE_RECALCULATION => true,
        DeliveryProcessor::SKIP_DELIVERY_TAX_RECALCULATION => true,
        ProductCartProcessor::SKIP_PRODUCT_STOCK_VALIDATION => true,
        ProductCartProcessor::KEEP_INACTIVE_PRODUCT => true,
        PromotionCollector::PIN_MANUAL_PROMOTIONS => true,
        PromotionCollector::PIN_AUTOMATIC_PROMOTIONS => true,
    ];

    /**
     * @internal
     *
     * @param EntityRepository<CustomerCollection> $customerRepository
     * @param EntityRepository<OrderAddressCollection> $orderAddressRepository
     * @param EntityRepository<RuleCollection> $ruleRepository
     */
    public function __construct(
        protected EntityRepository $customerRepository,
        protected AbstractSalesChannelContextFactory $salesChannelContextFactory,
        protected EventDispatcherInterface $eventDispatcher,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly EntityRepository $orderAddressRepository,
        private readonly InitialStateIdLoader $initialStateIdLoader,
        private readonly LineItemDownloadLoader $downloadLoader,
        private readonly EntityRepository $ruleRepository,
    ) {
    }

    /**
     * @throws OrderException
     *
     * @return array<string, mixed|float|string|array<int, array<string, string|int|bool|mixed>>|null>
     */
    public function convertToOrder(Cart $cart, SalesChannelContext $context, OrderConversionContext $conversionContext): array
    {
        if ($conversionContext->shouldIncludeDeliveries()) {
            foreach ($cart->getDeliveries() as $delivery) {
                if ($delivery->hasExtensionOfType(self::ORIGINAL_ADDRESS_ID, IdStruct::class) || $delivery->getLocation()->getAddress() !== null || $delivery->hasExtensionOfType(self::ORIGINAL_ID, IdStruct::class)) {
                    continue;
                }

                throw OrderException::deliveryWithoutAddress();
            }
        }

        $data = CartTransformer::transform(
            $cart,
            $context,
            $this->initialStateIdLoader->get(OrderStates::STATE_MACHINE),
            $conversionContext->shouldIncludePersistentData(),
        );

        if ($conversionContext->shouldIncludeCustomer()) {
            $customer = $context->getCustomer();
            if ($customer === null) {
                throw CartException::customerNotLoggedIn();
            }

            $data['orderCustomer'] = CustomerTransformer::transform($customer);
            $data['orderCustomer']['customer'] = [
                'id' => $customer->getId(),
                'lastPaymentMethodId' => $context->getPaymentMethod()->getId(),
            ];
            unset($data['orderCustomer']['customerId']);
        }

        $data['languageId'] = $context->getLanguageId();

        $convertedLineItems = LineItemTransformer::transformCollection($cart->getLineItems());
        $shippingAddresses = [];

        if ($conversionContext->shouldIncludeDeliveries()) {
            $shippingAddresses = AddressTransformer::transformCollection($cart->getDeliveries()->getAddresses(), true);
            $data['deliveries'] = DeliveryTransformer::transformCollection(
                $cart->getDeliveries(),
                $convertedLineItems,
                $this->initialStateIdLoader->get(OrderDeliveryStates::STATE_MACHINE),
                $context->getContext(),
                $shippingAddresses
            );

            // In order to reference the primary order delivery we need to set ids. The primary order delivery is the
            // order delivery with the highest shipping costs (i.e. _not_ a shipping discount).
            if (!$cart->getBehavior()?->isRecalculation() && $cart->getDeliveries()->count() > 0) {
                usort(
                    $data['deliveries'],
                    function (array $deliveryA, array $deliveryB) {
                        return $deliveryB['shippingCosts']->getTotalPrice() <=> $deliveryA['shippingCosts']->getTotalPrice();
                    }
                );
                $data['deliveries'][0]['id'] ??= Uuid::randomHex();
                $data['primaryOrderDeliveryId'] = $data['deliveries'][0]['id'];
            }
        }

        if ($conversionContext->shouldIncludeBillingAddress()) {
            $customer = $context->getCustomer();
            if ($customer === null) {
                throw CartException::customerNotLoggedIn();
            }

            $activeBillingAddress = $customer->getActiveBillingAddress();
            if ($activeBillingAddress === null) {
                throw CartException::addressNotFound('');
            }
            $customerAddressId = $activeBillingAddress->getId();

            if (\array_key_exists($customerAddressId, $shippingAddresses)) {
                $billingAddressId = $shippingAddresses[$customerAddressId]['id'];
            } else {
                $billingAddress = AddressTransformer::transform($activeBillingAddress);
                $data['addresses'] = [$billingAddress];
                $billingAddressId = $billingAddress['id'];
            }
            $data['billingAddressId'] = $billingAddressId;
        }

        if ($conversionContext->shouldIncludeTransactions()) {
            $data['transactions'] = TransactionTransformer::transformCollection(
                $cart->getTransactions(),
                $this->initialStateIdLoader->get(OrderTransactionStates::STATE_MACHINE),
                $context->getContext()
            );

            if (!$cart->getBehavior()?->isRecalculation() && $cart->getTransactions()->count() > 0) {
                $data['transactions'][0]['id'] ??= Uuid::randomHex();
                $data['primaryOrderTransactionId'] = $data['transactions'][0]['id'];
            }
        }

        $data['lineItems'] = array_values($convertedLineItems);

        foreach ($this->downloadLoader->load($data['lineItems'], $context->getContext()) as $key => $downloads) {
            if (!\array_key_exists($key, $data['lineItems'])) {
                continue;
            }

            $data['lineItems'][$key]['downloads'] = $downloads;
        }

        $idStruct = $cart->getExtensionOfType(self::ORIGINAL_ID, IdStruct::class);
        $data['id'] = $idStruct ? $idStruct->getId() : Uuid::randomHex();

        $orderNumberStruct = $cart->getExtensionOfType(self::ORIGINAL_ORDER_NUMBER, IdStruct::class);
        if ($orderNumberStruct !== null) {
            $data['orderNumber'] = $orderNumberStruct->getId();
        } else {
            $data['orderNumber'] = $this->numberRangeValueGenerator->getValue(
                OrderDefinition::ENTITY_NAME,
                $context->getContext(),
                $context->getSalesChannelId()
            );
        }

        $data['ruleIds'] = $context->getRuleIds();
        $data['taxCalculationType'] = $context->getTaxCalculationType();

        $event = new CartConvertedEvent($cart, $data, $context, $conversionContext);
        $this->eventDispatcher->dispatch($event);

        return $event->getConvertedCart();
    }

    /**
     * @throws CartException
     */
    public function convertToCart(OrderEntity $order, Context $context): Cart
    {
        if ($order->getLineItems() === null) {
            throw OrderException::missingAssociation('lineItems');
        }

        if ($order->getDeliveries() === null) {
            throw OrderException::missingAssociation('deliveries');
        }

        $cart = new Cart(Uuid::randomHex());
        $cart->setPrice($order->getPrice());
        $cart->setCustomerComment($order->getCustomerComment());
        $cart->setAffiliateCode($order->getAffiliateCode());
        $cart->setCampaignCode($order->getCampaignCode());
        $cart->setSource($order->getSource());
        $cart->addExtension(self::ORIGINAL_ID, new IdStruct($order->getId()));
        $orderNumber = $order->getOrderNumber();
        if ($orderNumber === null) {
            throw OrderException::missingOrderNumber($order->getId());
        }

        $cart->addExtension(self::ORIGINAL_ORDER_NUMBER, new IdStruct($orderNumber));
        /* NEXT-708 support:
            - transactions
        */

        $lineItems = LineItemTransformer::transformFlatToNested($order->getLineItems());

        $cart->addLineItems($lineItems);
        $cart->setDeliveries(
            $this->convertDeliveries($order->getDeliveries(), $lineItems)
        );

        $event = new OrderConvertedEvent($order, $cart, $context);
        $this->eventDispatcher->dispatch($event);

        return $event->getConvertedCart();
    }

    /**
     * @param array<string, array<string, bool>|string> $overrideOptions
     *
     * @throws InconsistentCriteriaIdsException
     */
    public function assembleSalesChannelContext(OrderEntity $order, Context $context, array $overrideOptions = []): SalesChannelContext
    {
        if ($order->getTransactions() === null) {
            throw OrderException::missingAssociation('transactions');
        }
        if ($order->getOrderCustomer() === null) {
            throw OrderException::missingAssociation('orderCustomer');
        }

        $customerId = $order->getOrderCustomer()->getCustomerId();
        $customer = null;

        if ($customerId) {
            $customerCriteria = (new Criteria([$customerId]))
                ->addAssociation('addresses');

            $customer = $this->customerRepository->search($customerCriteria, $context)->getEntities()->first();
        }

        $orderBillingAddressId = $order->getBillingAddressId();

        $orderShippingAddressId = $order->getPrimaryOrderDelivery()?->getShippingOrderAddressId();

        if (!Feature::isActive('v6.8.0.0')) {
            $orderShippingAddressId = $order->getDeliveries()?->first()?->getShippingOrderAddressId() ?? '';
        }

        $orderAddresses = $this->orderAddressRepository->search(new Criteria(\array_filter([$orderBillingAddressId, $orderShippingAddressId])), $context)->getEntities();
        $orderBillingAddress = $orderAddresses->get($orderBillingAddressId);
        $orderShippingAddress = $orderShippingAddressId ? $orderAddresses->get($orderShippingAddressId) : null;

        if ($orderBillingAddress === null) {
            throw CartException::addressNotFound($orderBillingAddressId);
        }

        $billingAddressId = null;
        $shippingAddressId = null;
        foreach ($customer?->getAddresses() ?? [] as $address) {
            if ($address->getHash() === $orderBillingAddress->getHash()) {
                $billingAddressId = $address->getId();
            }

            if ($address->getHash() === $orderShippingAddress?->getHash()) {
                $shippingAddressId = $address->getId();
            }
        }

        $options = [
            SalesChannelContextService::CURRENCY_ID => $order->getCurrencyId(),
            SalesChannelContextService::LANGUAGE_ID => $order->getLanguageId(),
            SalesChannelContextService::CUSTOMER_ID => $customerId,
            SalesChannelContextService::COUNTRY_STATE_ID => $orderBillingAddress->getCountryStateId(),
            SalesChannelContextService::CUSTOMER_GROUP_ID => $customer?->getGroupId(),
            SalesChannelContextService::PERMISSIONS => self::ADMIN_EDIT_ORDER_PERMISSIONS,
            SalesChannelContextService::VERSION_ID => $context->getVersionId(),
        ];

        if ($billingAddressId) {
            $options[SalesChannelContextService::BILLING_ADDRESS_ID] = $billingAddressId;
        }

        if ($shippingAddressId) {
            $options[SalesChannelContextService::SHIPPING_ADDRESS_ID] = $shippingAddressId;
        }

        $shippingMethodId = $order->getPrimaryOrderDelivery()?->getShippingMethodId();

        if (!Feature::isActive('v6.8.0.0')) {
            $shippingMethodId = $order->getDeliveries()?->first()?->getShippingMethodId();
        }

        if ($shippingMethodId !== null) {
            $options[SalesChannelContextService::SHIPPING_METHOD_ID] = $shippingMethodId;
        }

        foreach ($order->getTransactions() as $transaction) {
            $options[SalesChannelContextService::PAYMENT_METHOD_ID] = $transaction->getPaymentMethodId();
            if (
                $transaction->getStateMachineState() !== null
                && $transaction->getStateMachineState()->getTechnicalName() !== OrderTransactionStates::STATE_PAID
                && $transaction->getStateMachineState()->getTechnicalName() !== OrderTransactionStates::STATE_CANCELLED
                && $transaction->getStateMachineState()->getTechnicalName() !== OrderTransactionStates::STATE_FAILED
            ) {
                break;
            }
        }

        $options = array_merge($options, $overrideOptions);

        $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $order->getSalesChannelId(), $options);
        $salesChannelContext->getContext()->addExtensions($context->getExtensions());
        $salesChannelContext->addState(...$context->getStates());
        $salesChannelContext->setTaxState($order->getTaxStatus() ?? $order->getPrice()->getTaxStatus());

        if ($context->hasState(Context::SKIP_TRIGGER_FLOW)) {
            $salesChannelContext->getContext()->addState(Context::SKIP_TRIGGER_FLOW);
        }

        if ($order->getItemRounding() !== null) {
            $salesChannelContext->setItemRounding($order->getItemRounding());
        }

        if ($order->getTotalRounding() !== null) {
            $salesChannelContext->setTotalRounding($order->getTotalRounding());
        }

        if ($order->getRuleIds() !== null) {
            $salesChannelContext->setRuleIds($order->getRuleIds());
            $salesChannelContext->setAreaRuleIds($this->fetchRuleAreas($order->getRuleIds(), $context));
        }

        $event = new SalesChannelContextAssembledEvent($order, $salesChannelContext);
        $this->eventDispatcher->dispatch($event);

        return $salesChannelContext;
    }

    private function convertDeliveries(OrderDeliveryCollection $orderDeliveries, LineItemCollection $lineItems): DeliveryCollection
    {
        $cartDeliveries = new DeliveryCollection();
        foreach ($orderDeliveries as $orderDelivery) {
            $deliveryDate = new DeliveryDate(
                $orderDelivery->getShippingDateEarliest(),
                $orderDelivery->getShippingDateLatest()
            );

            $deliveryPositions = new DeliveryPositionCollection();

            if ($orderDelivery->getPositions() === null) {
                continue;
            }

            foreach ($orderDelivery->getPositions() as $position) {
                if ($position->getOrderLineItem() === null) {
                    continue;
                }

                $identifier = $position->getOrderLineItem()->getIdentifier();

                // line item has been removed and will not be added to delivery
                if ($lineItems->get($identifier) === null) {
                    continue;
                }

                if ($position->getPrice() === null) {
                    continue;
                }

                $deliveryPosition = new DeliveryPosition(
                    $identifier,
                    $lineItems->get($identifier),
                    $position->getPrice()->getQuantity(),
                    $position->getPrice(),
                    $deliveryDate
                );
                $deliveryPosition->addExtension(self::ORIGINAL_ID, new IdStruct($position->getId()));

                $deliveryPositions->add($deliveryPosition);
            }

            if ($orderDelivery->getShippingMethod() === null
                || $orderDelivery->getShippingOrderAddress() === null
                || $orderDelivery->getShippingOrderAddress()->getCountry() === null
            ) {
                continue;
            }

            $cartDelivery = new Delivery(
                $deliveryPositions,
                $deliveryDate,
                $orderDelivery->getShippingMethod(),
                new ShippingLocation(
                    $orderDelivery->getShippingOrderAddress()->getCountry(),
                    $orderDelivery->getShippingOrderAddress()->getCountryState(),
                    null
                ),
                $orderDelivery->getShippingCosts()
            );
            $cartDelivery->addExtension(self::ORIGINAL_ID, new IdStruct($orderDelivery->getId()));
            $cartDelivery->addExtension(self::ORIGINAL_ADDRESS_ID, new IdStruct($orderDelivery->getShippingOrderAddressId()));
            $cartDelivery->addExtension(self::ORIGINAL_ADDRESS_VERSION_ID, new IdStruct($orderDelivery->getShippingOrderAddressVersionId()));

            $cartDeliveries->add($cartDelivery);
        }

        return $cartDeliveries;
    }

    /**
     * @param string[] $ruleIds
     *
     * @return array<string, string[]>
     */
    private function fetchRuleAreas(array $ruleIds, Context $context): array
    {
        if (!$ruleIds) {
            return [];
        }

        $criteria = new Criteria($ruleIds);
        $rules = $this->ruleRepository->search($criteria, $context)->getEntities();

        return $rules->getIdsByArea();
    }
}
