<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product;

use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerWishlistProduct\CustomerWishlistProductCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Product\Aggregate\ProductConfiguratorSetting\ProductConfiguratorSettingCollection;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSelling\ProductCrossSellingCollection;
use Shopware\Core\Content\Product\Aggregate\ProductCrossSellingAssignedProducts\ProductCrossSellingAssignedProductsCollection;
use Shopware\Core\Content\Product\Aggregate\ProductDownload\ProductDownloadCollection;
use Shopware\Core\Content\Product\Aggregate\ProductFeatureSet\ProductFeatureSetEntity;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceCollection;
use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewCollection;
use Shopware\Core\Content\Product\Aggregate\ProductSearchKeyword\ProductSearchKeywordCollection;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationCollection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityCollection;
use Shopware\Core\Content\Product\DataAbstractionLayer\VariantListingConfig;
use Shopware\Core\Content\ProductStream\ProductStreamCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Seo\MainCategory\MainCategoryCollection;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCustomFieldsTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\Tag\TagCollection;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\System\Unit\UnitEntity;

#[Package('inventory')]
class ProductEntity extends Entity implements \Stringable
{
    use EntityCustomFieldsTrait;
    use EntityIdTrait;

    protected ?string $parentId = null;

    protected int $childCount = 0;

    protected int $autoIncrement;

    protected ?string $taxId = null;

    protected ?string $manufacturerId = null;

    protected ?string $unitId = null;

    protected ?bool $active = null;

    protected ?string $displayGroup = null;

    protected ?PriceCollection $price = null;

    protected ?string $manufacturerNumber = null;

    protected ?string $ean = null;

    protected int $sales;

    protected string $productNumber;

    protected int $stock;

    protected ?int $availableStock = null;

    protected bool $available;

    protected ?string $deliveryTimeId = null;

    protected ?DeliveryTimeEntity $deliveryTime = null;

    protected ?int $restockTime = null;

    protected ?bool $isCloseout = null;

    protected ?int $purchaseSteps = null;

    protected ?int $maxPurchase = null;

    protected ?int $minPurchase = null;

    protected ?float $purchaseUnit = null;

    protected ?float $referenceUnit = null;

    protected ?bool $shippingFree = null;

    protected ?PriceCollection $purchasePrices = null;

    protected ?bool $markAsTopseller = null;

    protected ?float $weight = null;

    protected ?float $width = null;

    protected ?float $height = null;

    protected ?float $length = null;

    protected ?\DateTimeInterface $releaseDate = null;

    /**
     * @var array<string>|null
     */
    protected ?array $categoryTree = null;

    /**
     * @var array<string>|null
     */
    protected ?array $streamIds = null;

    /**
     * @var array<string>|null
     */
    protected ?array $optionIds = null;

    /**
     * @var array<string>|null
     */
    protected ?array $propertyIds = null;

    protected ?string $name = null;

    protected ?string $keywords = null;

    protected ?string $description = null;

    protected ?string $metaDescription = null;

    protected ?string $metaTitle = null;

    protected ?string $packUnit = null;

    protected ?string $packUnitPlural = null;

    /**
     * @var array<string>|null
     */
    protected ?array $variantRestrictions = null;

    protected ?VariantListingConfig $variantListingConfig = null;

    /**
     * @var array<array<string>>
     */
    protected array $variation = [];

    protected ?TaxEntity $tax = null;

    protected ?ProductManufacturerEntity $manufacturer = null;

    protected ?UnitEntity $unit = null;

    protected ProductPriceCollection $prices;

    protected ?ProductMediaEntity $cover = null;

    protected ?ProductEntity $parent = null;

    protected ?ProductCollection $children = null;

    protected ?ProductMediaCollection $media = null;

    protected ?string $cmsPageId = null;

    protected ?CmsPageEntity $cmsPage = null;

    /**
     * @var array<string, array<string, array<string, string>>>|null
     */
    protected ?array $slotConfig = null;

    protected ?ProductSearchKeywordCollection $searchKeywords = null;

    protected ?ProductTranslationCollection $translations = null;

    protected ?CategoryCollection $categories = null;

    protected ?CustomFieldSetCollection $customFieldSets = null;

    protected ?TagCollection $tags = null;

    protected ?PropertyGroupOptionCollection $properties = null;

    protected ?PropertyGroupOptionCollection $options = null;

    protected ?ProductConfiguratorSettingCollection $configuratorSettings = null;

    protected ?CategoryCollection $categoriesRo = null;

    protected ?string $coverId = null;

    protected ?ProductVisibilityCollection $visibilities = null;

    /**
     * @var array<string>|null
     */
    protected ?array $tagIds = null;

    /**
     * @var array<string>|null
     */
    protected ?array $categoryIds = null;

    protected ?ProductReviewCollection $productReviews = null;

    protected ?float $ratingAverage = null;

    protected ?MainCategoryCollection $mainCategories = null;

    protected ?SeoUrlCollection $seoUrls = null;

    protected ?OrderLineItemCollection $orderLineItems = null;

    protected ?ProductCrossSellingCollection $crossSellings = null;

    protected ?ProductCrossSellingAssignedProductsCollection $crossSellingAssignedProducts = null;

    protected ?string $featureSetId = null;

    protected ?ProductFeatureSetEntity $featureSet = null;

    protected ?bool $customFieldSetSelectionActive = null;

    /**
     * @var array<string>|null
     */
    protected ?array $customSearchKeywords = null;

    protected ?CustomerWishlistProductCollection $wishlists = null;

    protected ?string $canonicalProductId = null;

    protected ?ProductEntity $canonicalProduct = null;

    protected ?ProductStreamCollection $streams = null;

    protected ?ProductDownloadCollection $downloads = null;

    /**
     * @var array<int, string>
     */
    protected array $states = [];

    public function __construct()
    {
        $this->prices = new ProductPriceCollection();
    }

    public function __toString(): string
    {
        return (string) ($this->getTranslation('name') ?? $this->getName());
    }

    public function getProductReviews(): ?ProductReviewCollection
    {
        return $this->productReviews;
    }

    public function setProductReviews(ProductReviewCollection $productReviews): void
    {
        $this->productReviews = $productReviews;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function setParentId(?string $parentId): void
    {
        $this->parentId = $parentId;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): void
    {
        $this->taxId = $taxId;
    }

    public function getManufacturerId(): ?string
    {
        return $this->manufacturerId;
    }

    public function setManufacturerId(?string $manufacturerId): void
    {
        $this->manufacturerId = $manufacturerId;
    }

    public function getUnitId(): ?string
    {
        return $this->unitId;
    }

    public function setUnitId(?string $unitId): void
    {
        $this->unitId = $unitId;
    }

    public function getActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): void
    {
        $this->active = $active;
    }

    public function getPrice(): ?PriceCollection
    {
        return $this->price;
    }

    public function setPrice(PriceCollection $price): void
    {
        $this->price = $price;
    }

    public function getCurrencyPrice(string $currencyId): ?Price
    {
        return $this->price?->getCurrencyPrice($currencyId);
    }

    public function getManufacturerNumber(): ?string
    {
        return $this->manufacturerNumber;
    }

    public function setManufacturerNumber(?string $manufacturerNumber): void
    {
        $this->manufacturerNumber = $manufacturerNumber;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): void
    {
        $this->ean = $ean;
    }

    public function getSales(): int
    {
        return $this->sales;
    }

    public function setSales(int $sales): void
    {
        $this->sales = $sales;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }

    public function getIsCloseout(): bool
    {
        return (bool) $this->isCloseout;
    }

    public function setIsCloseout(?bool $isCloseout): void
    {
        $this->isCloseout = $isCloseout;
    }

    public function getPurchaseSteps(): ?int
    {
        return $this->purchaseSteps;
    }

    public function setPurchaseSteps(?int $purchaseSteps): void
    {
        $this->purchaseSteps = $purchaseSteps;
    }

    public function getMaxPurchase(): ?int
    {
        return $this->maxPurchase;
    }

    public function setMaxPurchase(?int $maxPurchase): void
    {
        $this->maxPurchase = $maxPurchase;
    }

    public function getMinPurchase(): ?int
    {
        return $this->minPurchase;
    }

    public function setMinPurchase(?int $minPurchase): void
    {
        $this->minPurchase = $minPurchase;
    }

    public function getPurchaseUnit(): ?float
    {
        return $this->purchaseUnit;
    }

    public function setPurchaseUnit(?float $purchaseUnit): void
    {
        $this->purchaseUnit = $purchaseUnit;
    }

    public function getReferenceUnit(): ?float
    {
        return $this->referenceUnit;
    }

    public function setReferenceUnit(?float $referenceUnit): void
    {
        $this->referenceUnit = $referenceUnit;
    }

    public function getShippingFree(): ?bool
    {
        return $this->shippingFree;
    }

    public function setShippingFree(?bool $shippingFree): void
    {
        $this->shippingFree = $shippingFree;
    }

    public function getPurchasePrices(): ?PriceCollection
    {
        return $this->purchasePrices;
    }

    public function setPurchasePrices(?PriceCollection $purchasePrices): void
    {
        $this->purchasePrices = $purchasePrices;
    }

    public function getMarkAsTopseller(): ?bool
    {
        return $this->markAsTopseller;
    }

    public function setMarkAsTopseller(?bool $markAsTopseller): void
    {
        $this->markAsTopseller = $markAsTopseller;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(?float $weight): void
    {
        $this->weight = $weight;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function setWidth(?float $width): void
    {
        $this->width = $width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function setHeight(?float $height): void
    {
        $this->height = $height;
    }

    public function getLength(): ?float
    {
        return $this->length;
    }

    public function setLength(?float $length): void
    {
        $this->length = $length;
    }

    public function getReleaseDate(): ?\DateTimeInterface
    {
        return $this->releaseDate;
    }

    public function setReleaseDate(?\DateTimeInterface $releaseDate): void
    {
        $this->releaseDate = $releaseDate;
    }

    /**
     * @return array<string>|null
     */
    public function getCategoryTree(): ?array
    {
        return $this->categoryTree;
    }

    /**
     * @param array<string>|null $categoryTree
     */
    public function setCategoryTree(?array $categoryTree): void
    {
        $this->categoryTree = $categoryTree;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getKeywords(): ?string
    {
        return $this->keywords;
    }

    public function setKeywords(?string $keywords): void
    {
        $this->keywords = $keywords;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): void
    {
        $this->metaTitle = $metaTitle;
    }

    public function getPackUnit(): ?string
    {
        return $this->packUnit;
    }

    public function setPackUnit(?string $packUnit): void
    {
        $this->packUnit = $packUnit;
    }

    public function getPackUnitPlural(): ?string
    {
        return $this->packUnitPlural;
    }

    public function setPackUnitPlural(?string $packUnitPlural): void
    {
        $this->packUnitPlural = $packUnitPlural;
    }

    public function getTax(): ?TaxEntity
    {
        return $this->tax;
    }

    public function setTax(TaxEntity $tax): void
    {
        $this->tax = $tax;
    }

    public function getManufacturer(): ?ProductManufacturerEntity
    {
        return $this->manufacturer;
    }

    public function setManufacturer(ProductManufacturerEntity $manufacturer): void
    {
        $this->manufacturer = $manufacturer;
    }

    public function getUnit(): ?UnitEntity
    {
        return $this->unit;
    }

    public function setUnit(UnitEntity $unit): void
    {
        $this->unit = $unit;
    }

    public function getPrices(): ?ProductPriceCollection
    {
        return $this->prices;
    }

    public function setPrices(ProductPriceCollection $prices): void
    {
        $this->prices = $prices;
    }

    public function getRestockTime(): ?int
    {
        return $this->restockTime;
    }

    public function setRestockTime(?int $restockTime): void
    {
        $this->restockTime = $restockTime;
    }

    public function getDeliveryDate(): DeliveryDate
    {
        return new DeliveryDate(
            (new \DateTime())
                ->add(new \DateInterval('P' . 1 . 'D')),
            (new \DateTime())
                ->add(new \DateInterval('P' . 1 . 'D'))
                ->add(new \DateInterval('P' . 1 . 'D'))
        );
    }

    public function getRestockDeliveryDate(): DeliveryDate
    {
        $deliveryDate = $this->getDeliveryDate();

        return $deliveryDate->add(new \DateInterval('P' . $this->getRestockTime() . 'D'));
    }

    public function isReleased(): bool
    {
        if (!$this->getReleaseDate()) {
            return true;
        }

        return $this->releaseDate < new \DateTime();
    }

    /**
     * @return array<string>|null
     */
    public function getStreamIds(): ?array
    {
        return $this->streamIds;
    }

    /**
     * @param array<string>|null $streamIds
     */
    public function setStreamIds(?array $streamIds): void
    {
        $this->streamIds = $streamIds;
    }

    /**
     * @return array<string>|null
     */
    public function getOptionIds(): ?array
    {
        return $this->optionIds;
    }

    /**
     * @param array<string>|null $optionIds
     */
    public function setOptionIds(?array $optionIds): void
    {
        $this->optionIds = $optionIds;
    }

    /**
     * @return array<string>|null
     */
    public function getPropertyIds(): ?array
    {
        return $this->propertyIds;
    }

    /**
     * @param array<string>|null $propertyIds
     */
    public function setPropertyIds(?array $propertyIds): void
    {
        $this->propertyIds = $propertyIds;
    }

    public function getCover(): ?ProductMediaEntity
    {
        return $this->cover;
    }

    public function setCover(ProductMediaEntity $cover): void
    {
        $this->cover = $cover;
    }

    public function getCmsPage(): ?CmsPageEntity
    {
        return $this->cmsPage;
    }

    public function setCmsPage(CmsPageEntity $cmsPage): void
    {
        $this->cmsPage = $cmsPage;
    }

    public function getCmsPageId(): ?string
    {
        return $this->cmsPageId;
    }

    public function setCmsPageId(string $cmsPageId): void
    {
        $this->cmsPageId = $cmsPageId;
    }

    /**
     * @return array<string, array<string, array<string, string>>>|null
     */
    public function getSlotConfig(): ?array
    {
        return $this->slotConfig;
    }

    /**
     * @param array<string, array<string, array<string, string>>> $slotConfig
     */
    public function setSlotConfig(array $slotConfig): void
    {
        $this->slotConfig = $slotConfig;
    }

    public function getParent(): ?ProductEntity
    {
        return $this->parent;
    }

    public function setParent(ProductEntity $parent): void
    {
        $this->parent = $parent;
    }

    public function getChildren(): ?ProductCollection
    {
        return $this->children;
    }

    public function setChildren(ProductCollection $children): void
    {
        $this->children = $children;
    }

    public function getMedia(): ?ProductMediaCollection
    {
        return $this->media;
    }

    public function setMedia(ProductMediaCollection $media): void
    {
        $this->media = $media;
    }

    public function getSearchKeywords(): ?ProductSearchKeywordCollection
    {
        return $this->searchKeywords;
    }

    public function setSearchKeywords(ProductSearchKeywordCollection $searchKeywords): void
    {
        $this->searchKeywords = $searchKeywords;
    }

    public function getTranslations(): ?ProductTranslationCollection
    {
        return $this->translations;
    }

    public function setTranslations(ProductTranslationCollection $translations): void
    {
        $this->translations = $translations;
    }

    public function getCategories(): ?CategoryCollection
    {
        return $this->categories;
    }

    public function setCategories(CategoryCollection $categories): void
    {
        $this->categories = $categories;
    }

    public function getCustomFieldSets(): ?CustomFieldSetCollection
    {
        return $this->customFieldSets;
    }

    public function setCustomFieldSets(CustomFieldSetCollection $customFieldSets): void
    {
        $this->customFieldSets = $customFieldSets;
    }

    public function getTags(): ?TagCollection
    {
        return $this->tags;
    }

    public function setTags(TagCollection $tags): void
    {
        $this->tags = $tags;
    }

    public function getProperties(): ?PropertyGroupOptionCollection
    {
        return $this->properties;
    }

    public function setProperties(PropertyGroupOptionCollection $properties): void
    {
        $this->properties = $properties;
    }

    public function getOptions(): ?PropertyGroupOptionCollection
    {
        return $this->options;
    }

    public function setOptions(PropertyGroupOptionCollection $options): void
    {
        $this->options = $options;
    }

    public function getConfiguratorSettings(): ?ProductConfiguratorSettingCollection
    {
        return $this->configuratorSettings;
    }

    public function setConfiguratorSettings(ProductConfiguratorSettingCollection $configuratorSettings): void
    {
        $this->configuratorSettings = $configuratorSettings;
    }

    public function getCategoriesRo(): ?CategoryCollection
    {
        return $this->categoriesRo;
    }

    public function setCategoriesRo(CategoryCollection $categoriesRo): void
    {
        $this->categoriesRo = $categoriesRo;
    }

    public function getAutoIncrement(): int
    {
        return $this->autoIncrement;
    }

    public function setAutoIncrement(int $autoIncrement): void
    {
        $this->autoIncrement = $autoIncrement;
    }

    public function getCoverId(): ?string
    {
        return $this->coverId;
    }

    public function setCoverId(string $coverId): void
    {
        $this->coverId = $coverId;
    }

    public function getVisibilities(): ?ProductVisibilityCollection
    {
        return $this->visibilities;
    }

    public function setVisibilities(ProductVisibilityCollection $visibilities): void
    {
        $this->visibilities = $visibilities;
    }

    public function getProductNumber(): string
    {
        return $this->productNumber;
    }

    public function setProductNumber(string $productNumber): void
    {
        $this->productNumber = $productNumber;
    }

    /**
     * @return array<string>|null
     */
    public function getTagIds(): ?array
    {
        return $this->tagIds;
    }

    /**
     * @param array<string> $tagIds
     */
    public function setTagIds(array $tagIds): void
    {
        $this->tagIds = $tagIds;
    }

    /**
     * @return array<string>|null
     */
    public function getVariantRestrictions(): ?array
    {
        return $this->variantRestrictions;
    }

    /**
     * @param array<string>|null $variantRestrictions
     */
    public function setVariantRestrictions(?array $variantRestrictions): void
    {
        $this->variantRestrictions = $variantRestrictions;
    }

    public function getVariantListingConfig(): ?VariantListingConfig
    {
        return $this->variantListingConfig;
    }

    public function setVariantListingConfig(?VariantListingConfig $variantListingConfig): void
    {
        $this->variantListingConfig = $variantListingConfig;
    }

    /**
     * @return array<array<string>>
     */
    public function getVariation(): array
    {
        return $this->variation;
    }

    /**
     * @param array<array<string>> $variation
     */
    public function setVariation(array $variation): void
    {
        $this->variation = $variation;
    }

    public function getAvailableStock(): ?int
    {
        return $this->availableStock;
    }

    public function setAvailableStock(int $availableStock): void
    {
        $this->availableStock = $availableStock;
    }

    public function getAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function getDeliveryTimeId(): ?string
    {
        return $this->deliveryTimeId;
    }

    public function setDeliveryTimeId(?string $deliveryTimeId): void
    {
        $this->deliveryTimeId = $deliveryTimeId;
    }

    public function getDeliveryTime(): ?DeliveryTimeEntity
    {
        return $this->deliveryTime;
    }

    public function setDeliveryTime(?DeliveryTimeEntity $deliveryTime): void
    {
        $this->deliveryTime = $deliveryTime;
    }

    public function getChildCount(): ?int
    {
        return $this->childCount;
    }

    public function setChildCount(int $childCount): void
    {
        $this->childCount = $childCount;
    }

    public function getRatingAverage(): ?float
    {
        return $this->ratingAverage;
    }

    public function setRatingAverage(?float $ratingAverage): void
    {
        $this->ratingAverage = $ratingAverage;
    }

    public function getDisplayGroup(): ?string
    {
        return $this->displayGroup;
    }

    public function setDisplayGroup(?string $displayGroup): void
    {
        $this->displayGroup = $displayGroup;
    }

    public function getMainCategories(): ?MainCategoryCollection
    {
        return $this->mainCategories;
    }

    public function setMainCategories(MainCategoryCollection $mainCategories): void
    {
        $this->mainCategories = $mainCategories;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): void
    {
        $this->metaDescription = $metaDescription;
    }

    public function getSeoUrls(): ?SeoUrlCollection
    {
        return $this->seoUrls;
    }

    public function setSeoUrls(SeoUrlCollection $seoUrls): void
    {
        $this->seoUrls = $seoUrls;
    }

    public function getOrderLineItems(): ?OrderLineItemCollection
    {
        return $this->orderLineItems;
    }

    public function setOrderLineItems(OrderLineItemCollection $orderLineItems): void
    {
        $this->orderLineItems = $orderLineItems;
    }

    public function getCrossSellings(): ?ProductCrossSellingCollection
    {
        return $this->crossSellings;
    }

    public function setCrossSellings(ProductCrossSellingCollection $crossSellings): void
    {
        $this->crossSellings = $crossSellings;
    }

    public function getCrossSellingAssignedProducts(): ?ProductCrossSellingAssignedProductsCollection
    {
        return $this->crossSellingAssignedProducts;
    }

    public function setCrossSellingAssignedProducts(ProductCrossSellingAssignedProductsCollection $crossSellingAssignedProducts): void
    {
        $this->crossSellingAssignedProducts = $crossSellingAssignedProducts;
    }

    public function getFeatureSetId(): ?string
    {
        return $this->featureSetId;
    }

    public function setFeatureSetId(?string $featureSetId): void
    {
        $this->featureSetId = $featureSetId;
    }

    public function getFeatureSet(): ?ProductFeatureSetEntity
    {
        return $this->featureSet;
    }

    public function setFeatureSet(ProductFeatureSetEntity $featureSet): void
    {
        $this->featureSet = $featureSet;
    }

    public function getCustomFieldSetSelectionActive(): ?bool
    {
        return $this->customFieldSetSelectionActive;
    }

    public function setCustomFieldSetSelectionActive(?bool $customFieldSetSelectionActive): void
    {
        $this->customFieldSetSelectionActive = $customFieldSetSelectionActive;
    }

    /**
     * @return array<string>|null
     */
    public function getCustomSearchKeywords(): ?array
    {
        return $this->customSearchKeywords;
    }

    /**
     * @param array<string>|null $customSearchKeywords
     */
    public function setCustomSearchKeywords(?array $customSearchKeywords): void
    {
        $this->customSearchKeywords = $customSearchKeywords;
    }

    public function getWishlists(): ?CustomerWishlistProductCollection
    {
        return $this->wishlists;
    }

    public function setWishlists(CustomerWishlistProductCollection $wishlists): void
    {
        $this->wishlists = $wishlists;
    }

    public function getCanonicalProductId(): ?string
    {
        return $this->canonicalProductId;
    }

    public function setCanonicalProductId(string $canonicalProductId): void
    {
        $this->canonicalProductId = $canonicalProductId;
    }

    public function getCanonicalProduct(): ?ProductEntity
    {
        return $this->canonicalProduct;
    }

    public function setCanonicalProduct(ProductEntity $product): void
    {
        $this->canonicalProduct = $product;
    }

    public function getStreams(): ?ProductStreamCollection
    {
        return $this->streams;
    }

    public function setStreams(ProductStreamCollection $streams): void
    {
        $this->streams = $streams;
    }

    /**
     * @return array<string>|null
     */
    public function getCategoryIds(): ?array
    {
        return $this->categoryIds;
    }

    /**
     * @param array<string>|null $categoryIds
     */
    public function setCategoryIds(?array $categoryIds): void
    {
        $this->categoryIds = $categoryIds;
    }

    public function getDownloads(): ?ProductDownloadCollection
    {
        return $this->downloads;
    }

    public function setDownloads(ProductDownloadCollection $downloads): void
    {
        $this->downloads = $downloads;
    }

    /**
     * @return array<int, string>
     */
    public function getStates(): array
    {
        return $this->states;
    }

    /**
     * @param array<int, string> $states
     */
    public function setStates(array $states): void
    {
        $this->states = $states;
    }
}
