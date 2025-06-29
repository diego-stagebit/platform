<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Flow\Dispatching;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\FlowBuilder;
use Shopware\Core\Content\Flow\Dispatching\Struct\ActionSequence;
use Shopware\Core\Content\Flow\Dispatching\Struct\IfSequence;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[Package('after-sales')]
class FlowBuilderTest extends TestCase
{
    use IntegrationTestBehaviour;

    private FlowBuilder $flowBuilder;

    protected function setUp(): void
    {
        $this->flowBuilder = static::getContainer()->get(FlowBuilder::class);
    }

    public function testBuildOnlyAction(): void
    {
        $flowId = Uuid::randomHex();
        $flowSequences = [
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => null,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '0',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => null,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.remove.order.tag',
                'config' => '',
                'true_case' => '0',
            ],
        ];

        $flow = $this->flowBuilder->build($flowId, $flowSequences);
        $firstAction = $flow->getSequences()[0];
        static::assertInstanceOf(ActionSequence::class, $firstAction);
        static::assertSame('action.add.order.tag', $firstAction->action);
        $secondAction = $flow->getSequences()[1];
        static::assertInstanceOf(ActionSequence::class, $secondAction);
        static::assertSame('action.remove.order.tag', $secondAction->action);
    }

    public function testBuildWithActionBeforeAction(): void
    {
        $flowId = Uuid::randomHex();
        $parentId = Uuid::randomHex();
        $flowSequences = [
            [
                'flow_id' => $flowId,
                'sequence_id' => $parentId,
                'parent_id' => null,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.delay',
                'config' => '',
                'true_case' => '0',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => $parentId,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '0',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => $parentId,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '0',
            ],
        ];

        $flow = $this->flowBuilder->build($flowId, $flowSequences);
        $firstAction = $flow->getSequences()[0];
        static::assertInstanceOf(ActionSequence::class, $firstAction);
        static::assertSame('action.delay', $firstAction->action);
        $nextAction = $firstAction->nextAction;
        static::assertInstanceOf(ActionSequence::class, $nextAction);
        static::assertSame('action.add.order.tag', $nextAction->action);
        static::assertInstanceOf(ActionSequence::class, $nextAction->nextAction);
        static::assertSame('action.add.order.tag', $nextAction->nextAction->action);
    }

    public function testBuildWithActionBeforeIf(): void
    {
        $flowId = Uuid::randomHex();
        $parentId = Uuid::randomHex();
        $fatherId = Uuid::randomHex();
        $flowSequences = [
            [
                'flow_id' => $flowId,
                'sequence_id' => $parentId,
                'parent_id' => null,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.delay',
                'config' => '',
                'true_case' => '0',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => $fatherId,
                'parent_id' => $parentId,
                'app_flow_action_id' => null,
                'rule_id' => Uuid::randomHex(),
                'display_group' => '1',
                'position' => '1',
                'action_name' => null,
                'config' => '',
                'true_case' => '0',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => $fatherId,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '1',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => $fatherId,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '0',
            ],
        ];

        $flow = $this->flowBuilder->build($flowId, $flowSequences);
        $firstAction = $flow->getSequences()[0];
        static::assertInstanceOf(ActionSequence::class, $firstAction);
        static::assertSame('action.delay', $firstAction->action);
        $nextAction = $firstAction->nextAction;
        static::assertInstanceOf(IfSequence::class, $nextAction);
        static::assertInstanceOf(ActionSequence::class, $nextAction->trueCase);
        static::assertSame('action.add.order.tag', $nextAction->trueCase->action);
        static::assertInstanceOf(ActionSequence::class, $nextAction->falseCase);
        static::assertSame('action.add.order.tag', $nextAction->falseCase->action);
    }

    public function testBuildWithIfBeforeAction(): void
    {
        $flowId = Uuid::randomHex();
        $parentId = Uuid::randomHex();
        $flowSequences = [
            [
                'flow_id' => $flowId,
                'sequence_id' => $parentId,
                'parent_id' => null,
                'app_flow_action_id' => null,
                'rule_id' => Uuid::randomHex(),
                'display_group' => '1',
                'position' => '1',
                'action_name' => null,
                'config' => '',
                'true_case' => '0',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => $parentId,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '1',
            ],
            [
                'flow_id' => $flowId,
                'sequence_id' => Uuid::randomHex(),
                'parent_id' => $parentId,
                'app_flow_action_id' => null,
                'rule_id' => '',
                'display_group' => '1',
                'position' => '1',
                'action_name' => 'action.add.order.tag',
                'config' => '',
                'true_case' => '0',
            ],
        ];

        $flow = $this->flowBuilder->build($flowId, $flowSequences);
        $firstAction = $flow->getSequences()[0];
        static::assertInstanceOf(IfSequence::class, $firstAction);
        static::assertInstanceOf(ActionSequence::class, $firstAction->trueCase);
        static::assertSame('action.add.order.tag', $firstAction->trueCase->action);
        static::assertInstanceOf(ActionSequence::class, $firstAction->falseCase);
        static::assertSame('action.add.order.tag', $firstAction->falseCase->action);
    }
}
