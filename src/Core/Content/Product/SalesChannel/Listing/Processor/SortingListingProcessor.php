<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\Listing\Processor;

use Shopware\Core\Content\Product\ProductException;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

#[Package('inventory')]
class SortingListingProcessor extends AbstractListingProcessor
{
    /**
     * @param EntityRepository<ProductSortingCollection> $sortingRepository
     *
     * @internal
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $sortingRepository
    ) {
    }

    public function getDecorated(): AbstractListingProcessor
    {
        throw new DecorationPatternException(self::class);
    }

    public function prepare(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        if (!$request->get('order')) {
            $key = $request->get('search') ? 'core.listing.defaultSearchResultSorting' : 'core.listing.defaultSorting';
            $request->request->set('order', $this->getDefaultSortingKey($key, $context));
        }

        /** @var ProductSortingCollection $sortings */
        $sortings = $criteria->getExtension('sortings') ?? new ProductSortingCollection();
        $sortings->merge($this->getAvailableSortings($request, $context->getContext()));

        $currentSorting = $this->getCurrentSorting($sortings, $request, $context->getSalesChannelId());

        if ($currentSorting !== null) {
            $criteria->addSorting(
                ...$currentSorting->createDalSorting()
            );
        }

        $criteria->addExtension('sortings', $sortings);
    }

    public function process(Request $request, ProductListingResult $result, SalesChannelContext $context): void
    {
        /** @var ProductSortingCollection $sortings */
        $sortings = $result->getCriteria()->getExtension('sortings');
        $currentSorting = $this->getCurrentSorting($sortings, $request, $context->getSalesChannelId());

        if ($currentSorting !== null) {
            $result->setSorting($currentSorting->getKey());
        }

        $result->setAvailableSortings($sortings);
    }

    private function getCurrentSorting(ProductSortingCollection $sortings, Request $request, string $salesChannelId): ?ProductSortingEntity
    {
        $key = $request->get('order');

        if (!\is_string($key)) {
            throw ProductException::sortingNotFoundException('');
        }

        $sorting = $sortings->getByKey($key);
        if ($sorting !== null) {
            return $sorting;
        }

        return $sortings->get($this->systemConfigService->getString('core.listing.defaultSorting', $salesChannelId));
    }

    private function getAvailableSortings(Request $request, Context $context): ProductSortingCollection
    {
        $criteria = new Criteria();
        $criteria->setTitle('product-listing::load-sortings');
        /** @var string[] $availableSortings */
        $availableSortings = $request->get('availableSortings');
        $availableSortingsById = [];

        if ($availableSortings) {
            arsort($availableSortings, \SORT_DESC | \SORT_NUMERIC);
            $availableSortingsFilter = array_keys($availableSortings);

            $availableSortingsById = array_filter($availableSortingsFilter, fn ($filter) => Uuid::isValid($filter));

            $filter = new EqualsAnyFilter('id', $availableSortingsById);

            $criteria->addFilter($filter);
        }

        $criteria
            ->addFilter(new EqualsFilter('active', true))
            ->addSorting(new FieldSorting('priority', 'DESC'));

        $sortings = $this->sortingRepository->search($criteria, $context)->getEntities();

        if ($availableSortingsById) {
            $sortings->sortByIdArray($availableSortingsById);
        }

        return $sortings;
    }

    private function getDefaultSortingKey(string $key, SalesChannelContext $context): ?string
    {
        $id = $this->systemConfigService->getString($key, $context->getSalesChannelId());

        if (!Uuid::isValid($id)) {
            return $id;
        }

        $criteria = new Criteria([$id]);

        return $this->sortingRepository->search($criteria, $context->getContext())->first()?->get('key');
    }
}
