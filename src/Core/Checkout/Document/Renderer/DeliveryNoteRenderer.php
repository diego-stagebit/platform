<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\Renderer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Document\DocumentException;
use Shopware\Core\Checkout\Document\Event\DeliveryNoteOrdersEvent;
use Shopware\Core\Checkout\Document\Event\DocumentOrderCriteriaEvent;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Package('after-sales')]
final class DeliveryNoteRenderer extends AbstractDocumentRenderer
{
    public const TYPE = 'delivery_note';

    /**
     * @internal
     *
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly DocumentConfigLoader $documentConfigLoader,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly Connection $connection,
        private readonly DocumentFileRendererRegistry $fileRendererRegistry,
    ) {
    }

    public function supports(): string
    {
        return self::TYPE;
    }

    public function render(array $operations, Context $context, DocumentRendererConfig $rendererConfig): RendererResult
    {
        $result = new RendererResult();

        $template = '@Framework/documents/delivery_note.html.twig';

        $ids = \array_map(fn (DocumentGenerateOperation $operation) => $operation->getOrderId(), $operations);

        if (empty($ids)) {
            return $result;
        }

        $languageIdChain = $context->getLanguageIdChain();

        $chunk = $this->getOrdersLanguageId(array_values($ids), $context->getVersionId(), $this->connection);

        foreach ($chunk as ['language_id' => $languageId, 'ids' => $ids]) {
            $criteria = OrderDocumentCriteriaFactory::create(\explode(',', (string) $ids), $rendererConfig->deepLinkCode, self::TYPE);
            $context = $context->assign([
                'languageIdChain' => \array_values(\array_unique(\array_filter([$languageId, ...$languageIdChain]))),
            ]);

            $this->eventDispatcher->dispatch(new DocumentOrderCriteriaEvent(
                $criteria,
                $context,
                $operations,
                $rendererConfig,
                self::TYPE,
            ));

            $orders = $this->orderRepository->search($criteria, $context)->getEntities();

            $this->eventDispatcher->dispatch(new DeliveryNoteOrdersEvent($orders, $context, $operations));

            foreach ($orders as $order) {
                $orderId = $order->getId();

                try {
                    if (!\array_key_exists($order->getId(), $operations)) {
                        continue;
                    }

                    /** @var DocumentGenerateOperation $operation */
                    $operation = $operations[$order->getId()];

                    $forceDocumentCreation = $operation->getConfig()['forceDocumentCreation'] ?? true;
                    if (!$forceDocumentCreation && $order->getDocuments()?->first()) {
                        continue;
                    }

                    $config = clone $this->documentConfigLoader->load(self::TYPE, $order->getSalesChannelId(), $context);

                    $config->merge($operation->getConfig());

                    $number = $config->getDocumentNumber() ?: $this->getNumber($context, $order, $operation);

                    $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
                    $customConfig = $operation->getConfig()['custom'] ?? [];

                    $config->merge([
                        'documentNumber' => $number,
                        'documentDate' => $operation->getConfig()['documentDate'] ?? $now,
                        'custom' => [
                            'deliveryNoteNumber' => $number,
                            'deliveryDate' => $customConfig['deliveryDate'] ?? $now,
                            'deliveryNoteDate' => $customConfig['deliveryNoteDate'] ?? $now,
                        ],
                    ]);

                    // create version of order to ensure the document stays the same even if the order changes
                    $operation->setOrderVersionId($this->orderRepository->createVersion($orderId, $context, 'document'));

                    if ($operation->isStatic()) {
                        $doc = new RenderedDocument($number, $config->buildName(), $operation->getFileType(), $config->jsonSerialize());
                        $result->addSuccess($orderId, $doc);

                        continue;
                    }

                    $deliveries = $order->getPrimaryOrderDelivery();

                    if ($order->getDeliveries()) {
                        if (!Feature::isActive('v6.8.0.0')) {
                            $deliveries = $order->getDeliveries()->first();
                        }
                    }

                    $language = $order->getLanguage();
                    if ($language === null) {
                        throw DocumentException::generationError('Can not generate credit note document because no language exists. OrderId: ' . $operation->getOrderId());
                    }

                    $doc = new RenderedDocument(
                        $number,
                        $config->buildName(),
                        $operation->getFileType(),
                        $config->jsonSerialize(),
                    );

                    $doc->setParameters(['orderDelivery' => $deliveries]);
                    $doc->setTemplate($template);
                    $doc->setOrder($order);
                    $doc->setContext($context);

                    $doc->setContent($this->fileRendererRegistry->render($doc));

                    $result->addSuccess($orderId, $doc);
                } catch (\Throwable $exception) {
                    $result->addError($orderId, $exception);
                }
            }
        }

        return $result;
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        throw new DecorationPatternException(self::class);
    }

    private function getNumber(Context $context, OrderEntity $order, DocumentGenerateOperation $operation): string
    {
        return $this->numberRangeValueGenerator->getValue(
            'document_' . self::TYPE,
            $context,
            $order->getSalesChannelId(),
            $operation->isPreview()
        );
    }
}
