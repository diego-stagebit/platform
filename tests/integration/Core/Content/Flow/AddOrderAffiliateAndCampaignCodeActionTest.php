<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Content\Flow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Dispatching\Action\AddOrderAffiliateAndCampaignCodeAction;
use Shopware\Core\Content\Test\Flow\OrderActionTrait;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Framework\IdsCollection;

/**
 * @internal
 */
#[Package('after-sales')]
class AddOrderAffiliateAndCampaignCodeActionTest extends TestCase
{
    use OrderActionTrait;

    private EntityRepository $flowRepository;

    protected function setUp(): void
    {
        $this->flowRepository = static::getContainer()->get('flow.repository');

        $this->customerRepository = static::getContainer()->get('customer.repository');

        $this->ids = new IdsCollection();

        $this->browser = $this->createCustomSalesChannelBrowser([
            'id' => $this->ids->create('sales-channel'),
        ]);

        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', $this->ids->create('token'));
    }

    /**
     * @param array<string, mixed> $existedData
     * @param array<string, mixed> $updateData
     * @param array<string, mixed> $expectData
     */
    #[DataProvider('createDataProvider')]
    public function testAddAffiliateAndCampaignCodeForOrder(array $existedData, array $updateData, array $expectData): void
    {
        $this->createCustomerAndLogin();
        $this->createOrder($this->ids->get('customer'), $existedData);

        $sequenceId = Uuid::randomHex();
        $this->flowRepository->create([[
            'name' => 'Cancel order',
            'eventName' => 'state_enter.order.state.cancelled',
            'priority' => 1,
            'active' => true,
            'sequences' => [
                [
                    'id' => $sequenceId,
                    'parentId' => null,
                    'ruleId' => null,
                    'actionName' => AddOrderAffiliateAndCampaignCodeAction::getName(),
                    'position' => 1,
                    'config' => $updateData,
                ],
            ],
        ]], Context::createDefaultContext());

        $this->cancelOrder();

        /** @var OrderEntity $order */
        $order = static::getContainer()->get('order.repository')->search(new Criteria([$this->ids->get('order')]), Context::createDefaultContext())->first();

        static::assertSame($order->getAffiliateCode(), $expectData['affiliateCode']);
        static::assertSame($order->getCampaignCode(), $expectData['campaignCode']);
    }

    /**
     * @return array<int, mixed>
     */
    public static function createDataProvider(): array
    {
        return [
            // existed data / update data / expect data
            [
                [],
                [
                    'affiliateCode' => ['value' => '11111', 'upsert' => false],
                    'campaignCode' => ['value' => '22222', 'upsert' => false],
                ],
                ['affiliateCode' => '11111', 'campaignCode' => '22222'],
            ],
            [
                ['affiliateCode' => '11111', 'campaignCode' => '22222'],
                [
                    'affiliateCode' => ['value' => '33333', 'upsert' => false],
                    'campaignCode' => ['value' => '33333', 'upsert' => false],
                ],
                ['affiliateCode' => '11111', 'campaignCode' => '22222'],
            ],
            [
                ['affiliateCode' => '11111', 'campaignCode' => '22222'],
                [
                    'affiliateCode' => ['value' => '33333', 'upsert' => false],
                    'campaignCode' => ['value' => '33333', 'upsert' => true],
                ],
                ['affiliateCode' => '11111', 'campaignCode' => '33333'],
            ],
            [
                ['affiliateCode' => '11111', 'campaignCode' => '22222'],
                [
                    'affiliateCode' => ['value' => '33333', 'upsert' => true],
                    'campaignCode' => ['value' => '33333', 'upsert' => true],
                ],
                ['affiliateCode' => '33333', 'campaignCode' => '33333'],
            ],
        ];
    }
}
