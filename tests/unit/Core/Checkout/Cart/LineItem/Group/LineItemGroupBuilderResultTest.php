<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Cart\LineItem\Group;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\Group\LineItemGroup;
use Shopware\Core\Checkout\Cart\LineItem\Group\LineItemGroupBuilderResult;
use Shopware\Core\Checkout\Cart\LineItem\Group\LineItemGroupDefinition;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(LineItemGroupBuilderResult::class)]
class LineItemGroupBuilderResultTest extends TestCase
{
    /**
     * This test verifies that our functions does
     * correctly return false if we dont have any existing entries.
     */
    #[Group('lineitemgroup')]
    public function testHasItemsOnEmptyList(): void
    {
        $result = new LineItemGroupBuilderResult();

        static::assertFalse($result->hasFoundItems());
    }

    /**
     * This test verifies that we really search for items
     * in our hasFoundItems function.
     * If we have found groups, but no items in there, it should
     * also return FALSE.
     */
    #[Group('lineitemgroup')]
    public function testHasItemsOnGroupWithNoResults(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $group = new LineItemGroup();

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition, $group);

        static::assertFalse($result->hasFoundItems());
    }

    /**
     * This test verifies that we get TRUE
     * if we have existing entries.
     */
    #[Group('lineitemgroup')]
    public function testHasItemsIfExisting(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $group = new LineItemGroup();
        $group->addItem('ID1', 2);
        $group->addItem('ID2', 1);

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition, $group);

        static::assertTrue($result->hasFoundItems());
    }

    /**
     * This test verifies that our result of a
     * group definition uses the item IDs as keys in the array
     */
    #[Group('lineitemgroup')]
    public function testGroupTotalResultUsesKeys(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $group = new LineItemGroup();
        $group->addItem('ID1', 2);

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition, $group);

        static::assertArrayHasKey('ID1', $result->getGroupTotalResult($groupDefinition));
    }

    /**
     * This test verifies that we can add
     * a single line item for a definition and retrieve
     * all the aggregated data with our total result function
     */
    #[Group('lineitemgroup')]
    public function testGroupTotalContainsItem(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $group = new LineItemGroup();
        $group->addItem('ID1', 2);

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition, $group);

        $data = $result->getGroupTotalResult($groupDefinition);

        $itemQuantity = $result->getGroupTotalResult($groupDefinition)['ID1'];

        static::assertCount(1, $data);
        static::assertSame('ID1', $itemQuantity->getLineItemId());
        static::assertSame(2, $itemQuantity->getQuantity());
    }

    /**
     * This test verifies that we can add
     * a group of multiple line items for a definition and retrieve
     * all the aggregated data with our total result function
     */
    #[Group('lineitemgroup')]
    public function testGroupTotalContainsAllGroupItems(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $group = new LineItemGroup();
        $group->addItem('ID1', 2);
        $group->addItem('ID2', 1);

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition, $group);

        $data = $result->getGroupTotalResult($groupDefinition);

        static::assertCount(2, $data);

        static::assertSame('ID1', $result->getGroupTotalResult($groupDefinition)['ID1']->getLineItemId());
        static::assertSame(2, $result->getGroupTotalResult($groupDefinition)['ID1']->getQuantity());

        static::assertSame('ID2', $result->getGroupTotalResult($groupDefinition)['ID2']->getLineItemId());
        static::assertSame(1, $result->getGroupTotalResult($groupDefinition)['ID2']->getQuantity());
    }

    /**
     * This test verifies that our quantities are
     * increased if we already have the line items in
     * the result of our provided group definition.
     */
    #[Group('lineitemgroup')]
    public function testQuantityIncreasedOnExistingItems(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $result = new LineItemGroupBuilderResult();

        $group1 = new LineItemGroup();
        $group1->addItem('ID1', 2);

        $group2 = new LineItemGroup();
        $group2->addItem('ID1', 3);

        $result->addGroup($groupDefinition, $group1);
        $result->addGroup($groupDefinition, $group2);

        static::assertSame(5, $result->getGroupTotalResult($groupDefinition)['ID1']->getQuantity());
    }

    /**
     * This test verifies that we get an empty array
     * and no exception if we try to retrieve the result
     * of a group definition that has not even been found.
     */
    #[Group('lineitemgroup')]
    public function testUnknownGroupDefinitionReturnsEmptyArray(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'UNKNOWN123', 2, 'PRICE_ASC', new RuleCollection());

        $result = new LineItemGroupBuilderResult();

        static::assertCount(0, $result->getGroupTotalResult($groupDefinition));
    }

    /**
     * This test verifies that whenever we add a found group
     * to a group definition, the result increases the found-count.
     * In the end, we should not only have aggregated values, but also
     * know how many times a group has been found.
     */
    #[Group('lineitemgroup')]
    public function testGroupCountsAreAdded(): void
    {
        $groupDefinition1 = new LineItemGroupDefinition('ID1', 'UNKNOWN', 2, 'PRICE_ASC', new RuleCollection());
        $groupDefinition2 = new LineItemGroupDefinition('ID2', 'UNKNOWN2', 2, 'PRICE_ASC', new RuleCollection());

        $result = new LineItemGroupBuilderResult();

        $group1 = new LineItemGroup();
        $group1->addItem('ID1', 2);

        $group2 = new LineItemGroup();
        $group2->addItem('ID1', 3);

        $group3 = new LineItemGroup();
        $group3->addItem('ID2', 1);

        $result->addGroup($groupDefinition1, $group1);
        $result->addGroup($groupDefinition1, $group2);
        $result->addGroup($groupDefinition2, $group3);

        static::assertSame(2, $result->getGroupCount($groupDefinition1));
        static::assertSame(1, $result->getGroupCount($groupDefinition2));
    }

    /**
     * This test verifies that we get a result of 0
     * found groups if we search for a group definition on
     * an empty result object.
     */
    #[Group('lineitemgroup')]
    public function testGroupCountsOnEmptyData(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'UNKNOWN', 2, 'PRICE_ASC', new RuleCollection());

        $result = new LineItemGroupBuilderResult();

        static::assertSame(0, $result->getGroupCount($groupDefinition));
    }

    /**
     * This test verifies that our list of group results
     * for a group definition returns an empty list,
     * if no result itesm exist.
     */
    #[Group('lineitemgroup')]
    public function testGroupResultOnEmptyData(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'UNKNOWN', 2, 'PRICE_ASC', new RuleCollection());

        $result = new LineItemGroupBuilderResult();

        static::assertCount(0, $result->getGroupResult($groupDefinition));
    }

    /**
     * This test verifies that we get each single group result of a given group definition.
     * So our definition is being found 2 times with each 2 line items and their quantities.
     *
     * This is used to identify each group package later on and
     * allows us to e.g. only use the first valid group for discounts
     * instead of all found groups for a definition.
     */
    #[Group('lineitemgroup')]
    public function testGroupResultHasAllFoundGroupsOfDefinition(): void
    {
        $groupDefinition = new LineItemGroupDefinition('ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());

        $group1 = new LineItemGroup();
        $group1->addItem('ID1', 2);
        $group1->addItem('ID2', 1);

        $group2 = new LineItemGroup();
        $group2->addItem('ID1', 3);
        $group2->addItem('ID3', 2);

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition, $group1);
        $result->addGroup($groupDefinition, $group2);

        $data = $result->getGroupResult($groupDefinition);

        static::assertCount(2, $data);

        static::assertSame('ID1', $data[0]->getItems()[0]->getLineItemId());
        static::assertSame(2, $data[0]->getItems()[0]->getQuantity());
        static::assertSame('ID2', $data[0]->getItems()[1]->getLineItemId());
        static::assertSame(1, $data[0]->getItems()[1]->getQuantity());

        static::assertSame('ID1', $data[1]->getItems()[0]->getLineItemId());
        static::assertSame(3, $data[1]->getItems()[0]->getQuantity());
        static::assertSame('ID3', $data[1]->getItems()[1]->getLineItemId());
        static::assertSame(2, $data[1]->getItems()[1]->getQuantity());
    }

    /**
     * Similar to testGroupResultHasAllFoundGroupsOfDefinition, the method is used to get the result of given definition
     */
    #[Group('lineitemgroup')]
    public function testResultHasAllFoundGroupsOfDefinition(): void
    {
        $groupDefinition1 = new LineItemGroupDefinition('GROUP_ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());
        $groupDefinition2 = new LineItemGroupDefinition('GROUP_ID2', 'COUNT', 3, 'PRICE_ASC', new RuleCollection());

        $group1 = new LineItemGroup();
        $group1->addItem('ID1', 2);
        $group1->addItem('ID2', 1);

        $group2 = new LineItemGroup();
        $group2->addItem('ID1', 3);
        $group2->addItem('ID3', 2);

        $result = new LineItemGroupBuilderResult();
        $result->addGroup($groupDefinition1, $group1);
        $result->addGroup($groupDefinition2, $group2);

        $resultGroupNone = $result->getResult('IDFOO');

        static::assertNull($resultGroupNone);

        $resultGroup1 = $result->getResult($groupDefinition1->getId());
        $resultGroup2 = $result->getResult($groupDefinition2->getId());

        static::assertInstanceOf(LineItemGroupBuilderResult::class, $resultGroup1);
        static::assertInstanceOf(LineItemGroupBuilderResult::class, $resultGroup2);

        static::assertCount(1, $resultGroup1->getGroupResult($groupDefinition1));
        static::assertInstanceOf(LineItemGroup::class, $resultGroup1->getGroupResult($groupDefinition1)[0]);
        static::assertCount(2, $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems());
        static::assertSame('ID1', $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[0]->getLineItemId());
        static::assertSame(2, $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[0]->getQuantity());
        static::assertSame('ID2', $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[1]->getLineItemId());
        static::assertSame(1, $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[1]->getQuantity());

        static::assertCount(1, $resultGroup2->getGroupResult($groupDefinition2));
        static::assertInstanceOf(LineItemGroup::class, $resultGroup2->getGroupResult($groupDefinition2)[0]);
        static::assertCount(2, $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems());
        static::assertSame('ID1', $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[0]->getLineItemId());
        static::assertSame(3, $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[0]->getQuantity());
        static::assertSame('ID3', $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[1]->getLineItemId());
        static::assertSame(2, $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[1]->getQuantity());
    }

    public function testAddGroupResult(): void
    {
        $groupDefinition1 = new LineItemGroupDefinition('GROUP_ID1', 'COUNT', 2, 'PRICE_ASC', new RuleCollection());
        $groupDefinition2 = new LineItemGroupDefinition('GROUP_ID2', 'COUNT', 3, 'PRICE_ASC', new RuleCollection());

        $group1 = new LineItemGroup();
        $group1->addItem('ID1', 2);
        $group1->addItem('ID2', 1);

        $group2 = new LineItemGroup();
        $group2->addItem('ID1', 3);
        $group2->addItem('ID3', 2);

        $subResult1 = new LineItemGroupBuilderResult();
        $subResult1->addGroup($groupDefinition1, $group1);
        $subResult2 = new LineItemGroupBuilderResult();
        $subResult2->addGroup($groupDefinition2, $group2);

        $result = new LineItemGroupBuilderResult();
        $result->addGroupResult($groupDefinition1->getId(), $subResult1);
        $result->addGroupResult($groupDefinition2->getId(), $subResult2);

        $resultGroup1 = $result->getResult($groupDefinition1->getId());
        $resultGroup2 = $result->getResult($groupDefinition2->getId());

        static::assertInstanceOf(LineItemGroupBuilderResult::class, $resultGroup1);
        static::assertInstanceOf(LineItemGroupBuilderResult::class, $resultGroup2);

        static::assertCount(1, $resultGroup1->getGroupResult($groupDefinition1));
        static::assertInstanceOf(LineItemGroup::class, $resultGroup1->getGroupResult($groupDefinition1)[0]);
        static::assertCount(2, $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems());
        static::assertSame('ID1', $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[0]->getLineItemId());
        static::assertSame(2, $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[0]->getQuantity());
        static::assertSame('ID2', $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[1]->getLineItemId());
        static::assertSame(1, $resultGroup1->getGroupResult($groupDefinition1)[0]->getItems()[1]->getQuantity());

        static::assertCount(1, $resultGroup2->getGroupResult($groupDefinition2));
        static::assertInstanceOf(LineItemGroup::class, $resultGroup2->getGroupResult($groupDefinition2)[0]);
        static::assertCount(2, $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems());
        static::assertSame('ID1', $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[0]->getLineItemId());
        static::assertSame(3, $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[0]->getQuantity());
        static::assertSame('ID3', $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[1]->getLineItemId());
        static::assertSame(2, $resultGroup2->getGroupResult($groupDefinition2)[0]->getItems()[1]->getQuantity());
    }
}
