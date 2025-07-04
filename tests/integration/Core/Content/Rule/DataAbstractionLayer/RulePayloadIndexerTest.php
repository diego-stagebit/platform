<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Rule\DataAbstractionLayer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Content\Rule\DataAbstractionLayer\RuleIndexer;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Migration\MigrationRuntime;
use Shopware\Core\Framework\Migration\MigrationSource;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Event\PluginLifecycleEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostInstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUninstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Rule\Container\AndRule;
use Shopware\Core\Framework\Rule\Container\OrRule;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\SalesChannelRule;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Migration\Test\NullConnection;
use Shopware\Core\System\Currency\Rule\CurrencyRule;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class RulePayloadIndexerTest extends TestCase
{
    use IntegrationTestBehaviour;

    private Context $context;

    /**
     * @var EntityRepository<RuleCollection>
     */
    private EntityRepository $ruleRepository;

    private RuleIndexer $indexer;

    private Connection $connection;

    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->ruleRepository = static::getContainer()->get('rule.repository');
        $this->indexer = static::getContainer()->get(RuleIndexer::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->context = Context::createDefaultContext();
        $this->eventDispatcher = static::getContainer()->get('event_dispatcher');
    }

    public function testIndex(): void
    {
        $id = Uuid::randomHex();
        $currencyId1 = Uuid::randomHex();
        $currencyId2 = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new OrRule())->getName(),
                    'children' => [
                        [
                            'type' => (new CurrencyRule())->getName(),
                            'value' => [
                                'currencyIds' => [
                                    $currencyId1,
                                    $currencyId2,
                                ],
                                'operator' => CurrencyRule::OPERATOR_EQ,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->ruleRepository->create([$data], $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['HEX(1)' => '1']);
        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertNull($rule->get('payload'));

        $this->indexer->handle(new EntityIndexingMessage([$id]));

        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
    }

    public function testRefresh(): void
    {
        $id = Uuid::randomHex();
        $currencyId1 = Uuid::randomHex();
        $currencyId2 = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new OrRule())->getName(),
                    'children' => [
                        [
                            'type' => (new CurrencyRule())->getName(),
                            'value' => [
                                'currencyIds' => [
                                    $currencyId1,
                                    $currencyId2,
                                ],
                                'operator' => CurrencyRule::OPERATOR_EQ,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->ruleRepository->create([$data], $this->context);

        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
    }

    public function testRefreshWithMultipleRules(): void
    {
        $id = Uuid::randomHex();
        $rule2Id = Uuid::randomHex();
        $currencyId1 = Uuid::randomHex();
        $currencyId2 = Uuid::randomHex();
        $salesChannelId1 = Uuid::randomHex();
        $salesChannelId2 = Uuid::randomHex();

        $data = [
            [
                'id' => $id,
                'name' => 'test rule',
                'priority' => 1,
                'conditions' => [
                    [
                        'type' => (new OrRule())->getName(),
                        'children' => [
                            [
                                'type' => (new CurrencyRule())->getName(),
                                'value' => [
                                    'currencyIds' => [
                                        $currencyId1,
                                        $currencyId2,
                                    ],
                                    'operator' => CurrencyRule::OPERATOR_EQ,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => $rule2Id,
                'name' => 'second rule',
                'priority' => 42,
                'conditions' => [
                    [
                        'type' => (new SalesChannelRule())->getName(),
                        'value' => [
                            'salesChannelIds' => [
                                $salesChannelId1,
                                $salesChannelId2,
                            ],
                            'operator' => CurrencyRule::OPERATOR_EQ,
                        ],
                    ],
                ],
            ],
        ];

        $this->ruleRepository->create($data, $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['HEX(1)' => '1']);
        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertNull($rule->get('payload'));

        $this->indexer->handle(new EntityIndexingMessage([$id, $rule2Id]));

        $rules = $this->ruleRepository->search(new Criteria([$id, $rule2Id]), $this->context);
        $rule = $rules->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
        $rule = $rules->get($rule2Id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new SalesChannelRule())->assign(['salesChannelIds' => [$salesChannelId1, $salesChannelId2]])]),
            $rule->getPayload()
        );
    }

    public function testIndexWithMultipleRules(): void
    {
        $id = Uuid::randomHex();
        $rule2Id = Uuid::randomHex();
        $currencyId1 = Uuid::randomHex();
        $currencyId2 = Uuid::randomHex();
        $salesChannelId1 = Uuid::randomHex();
        $salesChannelId2 = Uuid::randomHex();

        $data = [
            [
                'id' => $id,
                'name' => 'test rule',
                'priority' => 1,
                'conditions' => [
                    [
                        'type' => (new OrRule())->getName(),
                        'children' => [
                            [
                                'type' => (new CurrencyRule())->getName(),
                                'value' => [
                                    'currencyIds' => [
                                        $currencyId1,
                                        $currencyId2,
                                    ],
                                    'operator' => CurrencyRule::OPERATOR_EQ,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => $rule2Id,
                'name' => 'second rule',
                'priority' => 42,
                'conditions' => [
                    [
                        'type' => (new SalesChannelRule())->getName(),
                        'value' => [
                            'salesChannelIds' => [
                                $salesChannelId1,
                                $salesChannelId2,
                            ],
                            'operator' => SalesChannelRule::OPERATOR_EQ,
                        ],
                    ],
                ],
            ],
        ];

        $this->ruleRepository->create($data, $this->context);

        $rules = $this->ruleRepository->search(new Criteria([$id, $rule2Id]), $this->context);
        $rule = $rules->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
        $rule = $rules->get($rule2Id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new SalesChannelRule())->assign(['salesChannelIds' => [$salesChannelId1, $salesChannelId2]])]),
            $rule->getPayload()
        );
    }

    public function testIndexWithMultipleRootConditions(): void
    {
        $id = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new OrRule())->getName(),
                    'children' => [
                        [
                            'type' => (new AndRule())->getName(),
                            'children' => [
                                [
                                    'type' => (new CurrencyRule())->getName(),
                                    'value' => [
                                        'currencyIds' => [
                                            Uuid::randomHex(),
                                            Uuid::randomHex(),
                                        ],
                                        'operator' => CurrencyRule::OPERATOR_EQ,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => (new OrRule())->getName(),
                ],
            ],
        ];

        $this->ruleRepository->create([$data], $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['HEX(1)' => '1']);
        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertNull($rule->get('payload'));
        $this->indexer->handle(new EntityIndexingMessage([$id]));

        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(AndRule::class, $rule->getPayload());

        static::assertCount(2, $rule->getPayload()->getRules());
        static::assertContainsOnlyInstancesOf(OrRule::class, $rule->getPayload()->getRules());
    }

    public function testIndexWithRootRuleNotAndRule(): void
    {
        $id = Uuid::randomHex();
        $currencyId1 = Uuid::randomHex();
        $currencyId2 = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new CurrencyRule())->getName(),
                    'value' => [
                        'currencyIds' => [
                            $currencyId1,
                            $currencyId2,
                        ],
                        'operator' => CurrencyRule::OPERATOR_EQ,
                    ],
                ],
            ],
        ];

        $this->ruleRepository->create([$data], $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['HEX(1)' => '1']);
        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertNull($rule->get('payload'));

        $this->indexer->handle(new EntityIndexingMessage([$id]));

        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])]),
            $rule->getPayload()
        );
    }

    public function testRefreshWithRootRuleNotAndRule(): void
    {
        $id = Uuid::randomHex();
        $currencyId1 = Uuid::randomHex();
        $currencyId2 = Uuid::randomHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new CurrencyRule())->getName(),
                    'value' => [
                        'currencyIds' => [
                            $currencyId1,
                            $currencyId2,
                        ],
                        'operator' => CurrencyRule::OPERATOR_EQ,
                    ],
                ],
            ],
        ];

        $this->ruleRepository->create([$data], $this->context);

        $rule = $this->ruleRepository->search(new Criteria([$id]), $this->context)->getEntities()->get($id);
        static::assertInstanceOf(RuleEntity::class, $rule);
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])]),
            $rule->getPayload()
        );
    }

    #[DataProvider('dataProviderForTestPostEventNullsPayload')]
    public function testPostEventNullsPayload(PluginLifecycleEvent $event): void
    {
        $payload = serialize(new AndRule());

        for ($i = 0; $i < 21; ++$i) {
            $this->connection->createQueryBuilder()
                ->insert('rule')
                ->values(['id' => ':id', 'name' => ':name', 'priority' => 1, 'payload' => ':payload', 'created_at' => ':createdAt'])
                ->setParameter('id', Uuid::randomBytes())
                ->setParameter('payload', $payload)
                ->setParameter('name', 'Rule' . $i)
                ->setParameter('createdAt', (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT))
                ->executeStatement();
        }

        $this->eventDispatcher->dispatch($event);

        $rules = $this->connection->createQueryBuilder()
            ->select('id', 'payload', 'invalid')
            ->from('rule')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rules as $rule) {
            static::assertSame('0', $rule['invalid']);
            static::assertNull($rule['payload']);
            static::assertNotNull($rule['id']);
        }
    }

    /**
     * @return list<array<PluginLifecycleEvent>>
     */
    public static function dataProviderForTestPostEventNullsPayload(): array
    {
        $plugin = new PluginEntity();
        $plugin->setName('TestPlugin');
        $plugin->setBaseClass(RulePlugin::class);
        $plugin->setPath('');

        $context = Context::createDefaultContext();
        $rulePlugin = new RulePlugin(false, '');

        $nullConnection = new NullConnection();
        $nullLogger = new NullLogger();
        $collection = new MigrationCollection(
            new MigrationSource('asd', []),
            new MigrationRuntime($nullConnection, $nullLogger),
            $nullConnection,
            $nullLogger,
        );

        return [
            [new PluginPostInstallEvent($plugin, new InstallContext($rulePlugin, $context, '', '', $collection))],
            [new PluginPostActivateEvent($plugin, new ActivateContext($rulePlugin, $context, '', '', $collection))],
            [new PluginPostUpdateEvent($plugin, new UpdateContext($rulePlugin, $context, '', '', $collection, ''))],
            [new PluginPostDeactivateEvent($plugin, new DeactivateContext($rulePlugin, $context, '', '', $collection))],
            [new PluginPostUninstallEvent($plugin, new UninstallContext($rulePlugin, $context, '', '', $collection, true))],
        ];
    }
}

/**
 * @internal
 */
#[Package('fundamentals@after-sales')]
class RulePlugin extends Plugin
{
}
