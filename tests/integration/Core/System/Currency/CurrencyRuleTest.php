<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\System\Currency;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Rule\Aggregate\RuleCondition\RuleConditionCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\Constraint\ArrayOfUuid;
use Shopware\Core\System\Currency\Rule\CurrencyRule;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class CurrencyRuleTest extends TestCase
{
    use DatabaseTransactionBehaviour;
    use KernelTestBehaviour;

    /**
     * @var EntityRepository<RuleCollection>
     */
    private EntityRepository $ruleRepository;

    /**
     * @var EntityRepository<RuleConditionCollection>
     */
    private EntityRepository $conditionRepository;

    private Context $context;

    protected function setUp(): void
    {
        $this->ruleRepository = static::getContainer()->get('rule.repository');
        $this->conditionRepository = static::getContainer()->get('rule_condition.repository');
        $this->context = Context::createDefaultContext();
    }

    public function testValidateWithMissingValue(): void
    {
        try {
            $this->conditionRepository->create([
                [
                    'type' => (new CurrencyRule())->getName(),
                    'ruleId' => Uuid::randomHex(),
                ],
            ], $this->context);
            static::fail('Exception was not thrown');
        } catch (WriteException $stackException) {
            $errors = iterator_to_array($stackException->getErrors());
            static::assertCount(2, $errors);

            static::assertSame('/0/value/currencyIds', $errors[0]['source']['pointer']);
            static::assertSame(NotBlank::IS_BLANK_ERROR, $errors[0]['code']);

            static::assertSame('/0/value/operator', $errors[1]['source']['pointer']);
            static::assertSame(NotBlank::IS_BLANK_ERROR, $errors[1]['code']);
        }
    }

    public function testValidateWithEmptyCurrencyIds(): void
    {
        try {
            $this->conditionRepository->create([
                [
                    'type' => (new CurrencyRule())->getName(),
                    'ruleId' => Uuid::randomHex(),
                    'value' => [
                        'currencyIds' => [],
                        'operator' => CurrencyRule::OPERATOR_EQ,
                    ],
                ],
            ], $this->context);
            static::fail('Exception was not thrown');
        } catch (WriteException $stackException) {
            $errors = iterator_to_array($stackException->getErrors());
            static::assertCount(1, $errors);

            static::assertSame('/0/value/currencyIds', $errors[0]['source']['pointer']);
            static::assertSame(NotBlank::IS_BLANK_ERROR, $errors[0]['code']);
        }
    }

    public function testValidateWithStringCurrencyIds(): void
    {
        try {
            $this->conditionRepository->create([
                [
                    'type' => (new CurrencyRule())->getName(),
                    'ruleId' => Uuid::randomHex(),
                    'value' => [
                        'currencyIds' => '0915d54fbf80423c917c61ad5a391b48',
                        'operator' => CurrencyRule::OPERATOR_EQ,
                    ],
                ],
            ], $this->context);
            static::fail('Exception was not thrown');
        } catch (WriteException $stackException) {
            $errors = iterator_to_array($stackException->getErrors());
            static::assertCount(1, $errors);

            static::assertSame('/0/value/currencyIds', $errors[0]['source']['pointer']);
            static::assertSame(Type::INVALID_TYPE_ERROR, $errors[0]['code']);
        }
    }

    public function testValidateWithInvalidArrayCurrencyIds(): void
    {
        try {
            $this->conditionRepository->create([
                [
                    'type' => (new CurrencyRule())->getName(),
                    'ruleId' => Uuid::randomHex(),
                    'value' => [
                        'currencyIds' => [true, 3, null, '0915d54fbf80423c917c61ad5a391b48'],
                        'operator' => CurrencyRule::OPERATOR_EQ,
                    ],
                ],
            ], $this->context);
            static::fail('Exception was not thrown');
        } catch (WriteException $stackException) {
            $errors = iterator_to_array($stackException->getErrors());
            static::assertCount(3, $errors);

            static::assertSame('/0/value/currencyIds', $errors[0]['source']['pointer']);
            static::assertSame('/0/value/currencyIds', $errors[1]['source']['pointer']);
            static::assertSame('/0/value/currencyIds', $errors[2]['source']['pointer']);

            static::assertSame(ArrayOfUuid::INVALID_TYPE_CODE, $errors[0]['code']);
            static::assertSame(ArrayOfUuid::INVALID_TYPE_CODE, $errors[1]['code']);
            static::assertSame(ArrayOfUuid::INVALID_TYPE_CODE, $errors[2]['code']);
        }
    }

    public function testValidateWithInvalidCurrencyIdsUuid(): void
    {
        try {
            $this->conditionRepository->create([
                [
                    'type' => (new CurrencyRule())->getName(),
                    'ruleId' => Uuid::randomHex(),
                    'value' => [
                        'currencyIds' => ['Invalid', '1234abcd'],
                        'operator' => CurrencyRule::OPERATOR_EQ,
                    ],
                ],
            ], $this->context);
            static::fail('Exception was not thrown');
        } catch (WriteException $stackException) {
            $errors = iterator_to_array($stackException->getErrors());
            static::assertCount(2, $errors);

            static::assertSame('/0/value/currencyIds', $errors[0]['source']['pointer']);
            static::assertSame('/0/value/currencyIds', $errors[1]['source']['pointer']);

            static::assertSame(ArrayOfUuid::INVALID_TYPE_CODE, $errors[0]['code']);
            static::assertSame(ArrayOfUuid::INVALID_TYPE_CODE, $errors[1]['code']);
        }
    }

    public function testIfRuleIsConsistent(): void
    {
        $ruleId = Uuid::randomHex();
        $this->ruleRepository->create(
            [['id' => $ruleId, 'name' => 'Demo rule', 'priority' => 1]],
            Context::createDefaultContext()
        );

        $id = Uuid::randomHex();
        $this->conditionRepository->create([
            [
                'id' => $id,
                'type' => (new CurrencyRule())->getName(),
                'ruleId' => $ruleId,
                'value' => [
                    'currencyIds' => [Uuid::randomHex(), Uuid::randomHex()],
                    'operator' => CurrencyRule::OPERATOR_EQ,
                ],
            ],
        ], $this->context);

        static::assertNotNull($this->conditionRepository->search(new Criteria([$id]), $this->context)->get($id));
        $this->conditionRepository->delete([['id' => $id]], Context::createDefaultContext());
    }
}
