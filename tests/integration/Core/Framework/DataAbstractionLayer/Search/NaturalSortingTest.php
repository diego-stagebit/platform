<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\DataAbstractionLayer\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class NaturalSortingTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var EntityRepository<PropertyGroupCollection>
     */
    private EntityRepository $groupRepository;

    /**
     * @var EntityRepository<PropertyGroupOptionCollection>
     */
    private EntityRepository $optionRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groupRepository = static::getContainer()->get('property_group.repository');
        $this->optionRepository = static::getContainer()->get('property_group_option.repository');
    }

    /**
     * @param array<string> $naturalOrder
     * @param array<string> $rawOrder
     */
    #[DataProvider('sortingFixtures')]
    public function testSorting(array $naturalOrder, array $rawOrder): void
    {
        $groupId = Uuid::randomHex();
        // created group with provided options
        $data = [
            'id' => $groupId,
            'name' => 'Content',
            'options' => array_map(static fn ($name) => ['name' => $name], $naturalOrder),
        ];

        $context = Context::createDefaultContext();
        $this->groupRepository->create([$data], $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('property_group_option.groupId', $groupId));

        // add sorting for none natural
        $criteria->addSorting(
            new FieldSorting('property_group_option.name', FieldSorting::ASCENDING, false)
        );

        $options = $this->optionRepository->search($criteria, $context);
        // check all options generated
        static::assertCount(\count($naturalOrder), $options);

        // extract names to compare them
        $actual = $options->map(static fn (PropertyGroupOptionEntity $option) => $option->getName());

        static::assertSame($rawOrder, array_values($actual));

        // check natural sorting
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('property_group_option.groupId', $groupId));
        $criteria->addSorting(new FieldSorting('property_group_option.name', FieldSorting::ASCENDING, true));

        $options = $this->optionRepository->search($criteria, $context);
        $actual = $options->map(static fn (PropertyGroupOptionEntity $option) => $option->getName());

        static::assertSame($naturalOrder, array_values($actual));
    }

    /**
     * @return array<array<array<string>>>
     */
    public static function sortingFixtures(): array
    {
        return [
            [
                ['1,0 liter', '2,0 liter', '3,0 liter', '4,0 liter', '10,0 liter'], // natural sorting
                ['1,0 liter', '10,0 liter', '2,0 liter', '3,0 liter', '4,0 liter'], // none nat
            ],
            [
                ['1,0', '2,0', '3,0', '4,0', '10,0'], // natural sorting
                ['1,0', '10,0', '2,0', '3,0', '4,0'], // none natural
            ],
            [
                ['1', '2', '3', '4', '5', '6', '100', '1000', '2000', '3100'], // natural sorting
                ['1', '100', '1000', '2', '2000', '3', '3100', '4', '5', '6'], // none natural
            ],
            [
                ['0.1', '0.2', '0.3', '1.0', '1.2', '1.4', '1.4', '1.6', '2.0', '2.0', '2.3'], // natural sorting
                ['0.1', '0.2', '0.3', '1.0', '1.2', '1.4', '1.4', '1.6', '2.0', '2.0', '2.3'], // none natural
            ],
            [
                ['test1', 'test2', 'test3', 'test4', 'test10'], // natural sorting
                ['test1', 'test10', 'test2', 'test3', 'test4'], // none natural
            ],
            [
                ['1', '10', '1.0', '1.1', '1.3', '1.5', '2.22222'], // natural sorting
                ['1', '1.0', '1.1', '1.3', '1.5', '10', '2.22222'], // none natural
            ],
        ];
    }
}
