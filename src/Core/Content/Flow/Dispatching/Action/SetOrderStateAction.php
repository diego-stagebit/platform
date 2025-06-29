<?php declare(strict_types=1);

namespace Shopware\Core\Content\Flow\Dispatching\Action;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Content\Flow\Dispatching\DelayableAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Content\Flow\Dispatching\TransactionalAction;
use Shopware\Core\Content\Flow\Dispatching\TransactionFailedException;
use Shopware\Core\Content\Flow\FlowException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @internal
 */
#[Package('after-sales')]
class SetOrderStateAction extends FlowAction implements DelayableAction, TransactionalAction
{
    final public const FORCE_TRANSITION = 'force_transition';

    private const ORDER = 'order';

    private const ORDER_DELIVERY = 'order_delivery';

    private const ORDER_TRANSACTION = 'order_transaction';

    /**
     * @internal
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderService $orderService
    ) {
    }

    public static function getName(): string
    {
        return 'action.set.order.state';
    }

    /**
     * @return array<int, string>
     */
    public function requirements(): array
    {
        return [OrderAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        if (!$flow->hasData(OrderAware::ORDER_ID)) {
            return;
        }

        $this->update($flow->getContext(), $flow->getConfig(), $flow->getData(OrderAware::ORDER_ID));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function update(Context $context, array $config, string $orderId): void
    {
        if (empty($config)) {
            return;
        }

        if ($config[self::FORCE_TRANSITION] ?? false) {
            $context->addState(self::FORCE_TRANSITION);
        }

        $transitions = array_filter([
            self::ORDER => $config[self::ORDER] ?? null,
            self::ORDER_DELIVERY => $config[self::ORDER_DELIVERY] ?? null,
            self::ORDER_TRANSACTION => $config[self::ORDER_TRANSACTION] ?? null,
        ]);

        try {
            foreach ($transitions as $machine => $toPlace) {
                $this->transitState((string) $machine, $orderId, (string) $toPlace, $context);
            }
        } catch (FlowException $e) {
            throw TransactionFailedException::because($e);
        } finally {
            $context->removeState(self::FORCE_TRANSITION);
        }
    }

    /**
     * @throws IllegalTransitionException
     * @throws FlowException
     */
    private function transitState(string $machine, string $orderId, string $toPlace, Context $context): void
    {
        if (!$toPlace) {
            return;
        }

        $data = new ParameterBag();

        if (!Feature::isActive('v6.8.0.0')) {
            if ($machine === self::ORDER) {
                $machineId = $orderId;
            } elseif ($machine === self::ORDER_DELIVERY) {
                $machineId = $this->getMachineIdFromOrderDelivery($orderId, $context);
            } else {
                $machineId = $this->getMachineId($machine, $orderId);
            }
        } else {
            $machineId = match ($machine) {
                self::ORDER => $orderId,
                self::ORDER_DELIVERY => $this->getMachineIdFromOrderDelivery($orderId, $context),
                self::ORDER_TRANSACTION => $this->getMachineIdFromOrderTransaction($orderId, $context),
                default => $this->getMachineId($machine, $orderId),
            };
        }

        if (!$machineId) {
            throw FlowException::stateMachineNotFound($machine);
        }

        $actionName = $this->getAvailableActionName($machine, $machineId, $toPlace);
        if (!$actionName) {
            $actionName = $toPlace;
        }

        switch ($machine) {
            case self::ORDER:
                $this->orderService->orderStateTransition($orderId, $actionName, $data, $context);

                return;
            case self::ORDER_DELIVERY:
                $this->orderService->orderDeliveryStateTransition($machineId, $actionName, $data, $context);

                return;
            case self::ORDER_TRANSACTION:
                $this->orderService->orderTransactionStateTransition($machineId, $actionName, $data, $context);

                return;
            default:
                throw FlowException::stateMachineNotFound($machine);
        }
    }

    private function getMachineId(string $machine, string $orderId): ?string
    {
        return $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM ' . $machine . ' WHERE order_id = :id AND version_id = :version ORDER BY created_at DESC',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        ) ?: null;
    }

    private function getMachineIdFromOrderDelivery(string $orderId, Context $context): ?string
    {
        return $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM ' . self::ORDER_DELIVERY . ' WHERE order_id = :id AND version_id = :version ORDER BY JSON_EXTRACT(shipping_costs, \'$.totalPrice\') DESC',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'version' => Uuid::fromHexToBytes($context->getVersionId()),
            ],
        ) ?: null;
    }

    private function getAvailableActionName(string $machine, string $machineId, string $toPlace): ?string
    {
        $actionName = $this->connection->fetchOne(
            'SELECT action_name FROM state_machine_transition WHERE from_state_id = :fromStateId AND to_state_id = :toPlaceId',
            [
                'fromStateId' => $this->getFromPlaceId($machine, $machineId),
                'toPlaceId' => $this->getToPlaceId($toPlace, $machine),
            ]
        );

        return $actionName ?: null;
    }

    private function getToPlaceId(string $toPlace, string $machine): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM state_machine_state WHERE technical_name = :toPlace AND state_machine_id = :stateMachineId',
            [
                'toPlace' => $toPlace,
                'stateMachineId' => $this->getStateMachineId($machine),
            ]
        );

        return $id ?: null;
    }

    private function getFromPlaceId(string $machine, string $machineId): ?string
    {
        $escaped = EntityDefinitionQueryHelper::escape($machine);
        $id = $this->connection->fetchOne(
            'SELECT state_id FROM ' . $escaped . 'WHERE id = :id AND version_id = :version',
            [
                'id' => Uuid::fromHexToBytes($machineId),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]
        );

        return $id ?: null;
    }

    private function getStateMachineId(string $machine): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM state_machine WHERE technical_name = :technicalName',
            [
                'technicalName' => $machine . '.state',
            ]
        );

        return $id ?: null;
    }

    private function getMachineIdFromOrderTransaction(string $orderId, Context $context): ?string
    {
        return $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM ' . self::ORDER_TRANSACTION . ' WHERE order_id = :id AND version_id = :version ORDER BY created_at DESC',
            [
                'id' => Uuid::fromHexToBytes($orderId),
                'version' => Uuid::fromHexToBytes($context->getVersionId()),
            ]
        ) ?: null;
    }
}
