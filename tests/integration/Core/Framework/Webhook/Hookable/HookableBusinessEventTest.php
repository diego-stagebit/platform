<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Webhook\Hookable;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleDefinition;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\ArrayBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\CollectionBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\EntityBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\NestedEntityBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\ScalarBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\StructuredArrayObjectBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\StructuredObjectBusinessEvent;
use Shopware\Core\Framework\Test\Webhook\_fixtures\BusinessEvents\UnstructuredObjectBusinessEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\AclPrivilegeCollection;
use Shopware\Core\Framework\Webhook\BusinessEventEncoder;
use Shopware\Core\Framework\Webhook\Hookable\HookableBusinessEvent;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\System\Tax\TaxEntity;

/**
 * @internal
 */
class HookableBusinessEventTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testGetter(): void
    {
        $scalarEvent = new ScalarBusinessEvent();
        $event = HookableBusinessEvent::fromBusinessEvent(
            $scalarEvent,
            static::getContainer()->get(BusinessEventEncoder::class)
        );

        static::assertSame($scalarEvent->getName(), $event->getName());
        $shopwareVersion = static::getContainer()->getParameter('kernel.shopware_version');
        static::assertSame($scalarEvent->getEncodeValues($shopwareVersion), $event->getWebhookPayload());
    }

    #[DataProvider('getEventsWithoutPermissions')]
    public function testIsAllowedForNonEntityBasedEvents(FlowEventAware $rootEvent): void
    {
        $event = HookableBusinessEvent::fromBusinessEvent(
            $rootEvent,
            static::getContainer()->get(BusinessEventEncoder::class)
        );

        static::assertTrue($event->isAllowed(Uuid::randomHex(), new AclPrivilegeCollection([])));
    }

    #[DataProvider('getEventsWithPermissions')]
    public function testIsAllowedForEntityBasedEvents(FlowEventAware $rootEvent): void
    {
        $event = HookableBusinessEvent::fromBusinessEvent(
            $rootEvent,
            static::getContainer()->get(BusinessEventEncoder::class)
        );

        $allowedPermissions = new AclPrivilegeCollection([
            TaxDefinition::ENTITY_NAME . ':' . AclRoleDefinition::PRIVILEGE_READ,
        ]);
        static::assertTrue($event->isAllowed(Uuid::randomHex(), $allowedPermissions));

        $notAllowedPermissions = new AclPrivilegeCollection([
            ProductDefinition::ENTITY_NAME . ':' . AclRoleDefinition::PRIVILEGE_READ,
        ]);
        static::assertFalse($event->isAllowed(Uuid::randomHex(), $notAllowedPermissions));
    }

    /**
     * @return array<array{0: FlowEventAware}>
     */
    public static function getEventsWithoutPermissions(): array
    {
        return [
            [new ScalarBusinessEvent()],
            [new StructuredObjectBusinessEvent()],
            [new StructuredArrayObjectBusinessEvent()],
            [new UnstructuredObjectBusinessEvent()],
        ];
    }

    /**
     * @return array<array{0: FlowEventAware}>
     */
    public static function getEventsWithPermissions(): array
    {
        $tax = new TaxEntity();
        $tax->setId('tax-id');
        $tax->setName('test');
        $tax->setTaxRate(19);
        $tax->setPosition(1);

        return [
            [new EntityBusinessEvent($tax)],
            [new CollectionBusinessEvent(new TaxCollection([$tax]))],
            [new ArrayBusinessEvent(new TaxCollection([$tax]))],
            [new NestedEntityBusinessEvent($tax)],
        ];
    }
}
