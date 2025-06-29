<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Cart\Promotion\Aggregate\PromotionSetGroup;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionSetGroup\PromotionSetGroupEntity;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[CoversClass(PromotionSetGroupEntity::class)]
#[Package('checkout')]
class PromotionSetGroupEntityTest extends TestCase
{
    private const KEY_PACKAGER_COUNT = 'PACKAGER_COUNT';

    /**
     * This test verifies that our assignment and
     * getter work correctly for the property.
     */
    #[Group('promotions')]
    public function testPropertyPackagerKey(): void
    {
        $group = new PromotionSetGroupEntity();
        $group->setPackagerKey(self::KEY_PACKAGER_COUNT);
        $group->setValue(9);
        $group->setSorterKey('');
        $group->setSetGroupRules(new RuleCollection());

        static::assertSame(self::KEY_PACKAGER_COUNT, $group->getPackagerKey());
    }

    /**
     * This test verifies that our assignment and
     * getter work correctly for the property.
     */
    #[Group('promotions')]
    public function testPropertyValue(): void
    {
        $group = new PromotionSetGroupEntity();
        $group->setPackagerKey('0');
        $group->setValue(1);
        $group->setSorterKey('');
        $group->setSetGroupRules(new RuleCollection());

        static::assertSame(1.0, $group->getValue());
    }

    /**
     * This test verifies that our assignment and
     * getter work correctly for the property.
     */
    #[Group('promotions')]
    public function testPropertySorterKey(): void
    {
        $group = new PromotionSetGroupEntity();
        $group->setPackagerKey('0');
        $group->setValue(9);
        $group->setSorterKey('PRICE_DESC');
        $group->setSetGroupRules(new RuleCollection());

        static::assertSame('PRICE_DESC', $group->getSorterKey());
    }

    /**
     * This test verifies that our assignment and
     * getter work correctly for the property.
     */
    #[Group('promotions')]
    public function testPropertyRules(): void
    {
        $ruleEntity = new RuleEntity();
        $ruleEntity->setId('R1');

        $group = new PromotionSetGroupEntity();
        $group->setPackagerKey('0');
        $group->setValue(9);
        $group->setSorterKey('');
        $group->setSetGroupRules(new RuleCollection([$ruleEntity]));

        static::assertInstanceOf(RuleCollection::class, $group->getSetGroupRules());
        static::assertCount(1, $group->getSetGroupRules());
    }
}
