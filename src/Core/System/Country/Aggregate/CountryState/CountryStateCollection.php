<?php declare(strict_types=1);

namespace Shopware\Core\System\Country\Aggregate\CountryState;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;

/**
 * @extends EntityCollection<CountryStateEntity>
 */
#[Package('fundamentals@discovery')]
class CountryStateCollection extends EntityCollection
{
    /**
     * @return array<string>
     */
    public function getCountryIds(): array
    {
        return $this->fmap(fn (CountryStateEntity $countryState) => $countryState->getCountryId());
    }

    public function filterByCountryId(string $id): self
    {
        return $this->filter(fn (CountryStateEntity $countryState) => $countryState->getCountryId() === $id);
    }

    /**
     * @deprecated tag:v6.8.0 - will be removed, use sorting via SQL instead
     */
    public function sortByPositionAndName(): void
    {
        Feature::triggerDeprecationOrThrow('v6.8.0.0', 'Use sorting via SQL instead of this method.');

        uasort($this->elements, static function (CountryStateEntity $a, CountryStateEntity $b) {
            $aPosition = $a->getPosition();
            $bPosition = $b->getPosition();

            if ($aPosition !== $bPosition) {
                return $aPosition <=> $bPosition;
            }

            $aName = (string) $a->getTranslation('name');
            $bName = (string) $b->getTranslation('name');
            if ($aName !== $bName) {
                return strnatcasecmp($aName, $bName);
            }

            return 0;
        });
    }

    public function getApiAlias(): string
    {
        return 'country_state_collection';
    }

    protected function getExpectedClass(): string
    {
        return CountryStateEntity::class;
    }
}
