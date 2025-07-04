<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\App\ActionButton;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\App\ActionButton\ActionButtonLoader;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class ActionButtonLoaderTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $appRepository;

    private ActionButtonLoader $actionButtonLoader;

    private Context $context;

    private string $app1OrderDetailButtonId;

    private string $app1ProductDetailButtonId;

    private string $app1OrderListButtonId;

    private string $app2OrderDetailButtonId;

    private string $app3OrderDetailButtonId;

    protected function setUp(): void
    {
        $this->appRepository = static::getContainer()->get('app.repository');
        $this->actionButtonLoader = static::getContainer()->get(ActionButtonLoader::class);
        $this->context = Context::createDefaultContext();

        $this->app1OrderDetailButtonId = Uuid::randomHex();
        $this->app1ProductDetailButtonId = Uuid::randomHex();
        $this->app1OrderListButtonId = Uuid::randomHex();
        $this->app2OrderDetailButtonId = Uuid::randomHex();
        $this->app3OrderDetailButtonId = Uuid::randomHex();
    }

    public function testLoadActionButtonsForView(): void
    {
        $this->registerActionButtons();

        $loadedActionButtons = $this->actionButtonLoader->loadActionButtonsForView('order', 'detail', $this->context);

        usort($loadedActionButtons, fn (array $a, array $b): int => $a['app'] <=> $b['app']);

        static::assertSame([
            [
                'app' => 'App1',
                'id' => $this->app1OrderDetailButtonId,
                'label' => [
                    'en-GB' => 'Order Detail App1',
                ],
                'action' => 'orderDetailApp1',
                'url' => 'app1.com/order/detail',
                'icon' => base64_encode((string) file_get_contents(__DIR__ . '/../Manifest/_fixtures/test/icon.png')),
            ], [
                'app' => 'App2',
                'id' => $this->app2OrderDetailButtonId,
                'label' => [
                    'en-GB' => 'Order Detail App2',
                ],
                'action' => 'orderDetailApp2',
                'url' => 'app2.com/order/detail',
                'icon' => null,
            ],
        ], $loadedActionButtons);
    }

    private function registerActionButtons(): void
    {
        $this->appRepository->create([[
            'name' => 'App1',
            'active' => true,
            'path' => __DIR__ . '/../Manifest/_fixtures/test',
            'iconRaw' => file_get_contents(__DIR__ . '/../Manifest/_fixtures/test/icon.png'),
            'version' => '0.0.1',
            'label' => 'test',
            'accessToken' => 'test',
            'actionButtons' => [
                [
                    'id' => $this->app1OrderDetailButtonId,
                    'entity' => 'order',
                    'view' => 'detail',
                    'action' => 'orderDetailApp1',
                    'label' => 'Order Detail App1',
                    'url' => 'app1.com/order/detail',
                ],
                [
                    'id' => $this->app1ProductDetailButtonId,
                    'entity' => 'product',
                    'view' => 'detail',
                    'action' => 'productDetailApp1',
                    'label' => 'Product Detail App1',
                    'url' => 'app1.com/product/detail',
                ],
                [
                    'id' => $this->app1OrderListButtonId,
                    'entity' => 'order',
                    'view' => 'index',
                    'action' => 'orderListApp1',
                    'label' => 'Order List App1',
                    'url' => 'app1.com/order/list',
                ],
            ],
            'integration' => [
                'label' => 'App1',
                'accessKey' => 'test',
                'secretAccessKey' => 'test',
            ],
            'aclRole' => [
                'name' => 'App1',
            ],
        ], [
            'name' => 'App2',
            'active' => true,
            'path' => __DIR__ . '/../Manifest/_fixtures/test',
            'version' => '0.0.1',
            'label' => 'test',
            'accessToken' => 'test',
            'actionButtons' => [
                [
                    'id' => $this->app2OrderDetailButtonId,
                    'entity' => 'order',
                    'view' => 'detail',
                    'action' => 'orderDetailApp2',
                    'label' => 'Order Detail App2',
                    'url' => 'app2.com/order/detail',
                ],
            ],
            'integration' => [
                'label' => 'App2',
                'accessKey' => 'test',
                'secretAccessKey' => 'test',
            ],
            'aclRole' => [
                'name' => 'App2',
            ],
        ], [
            'name' => 'App3',
            'active' => false,
            'path' => __DIR__ . '/../Manifest/_fixtures/test',
            'version' => '0.0.1',
            'label' => 'test',
            'accessToken' => 'test',
            'actionButtons' => [
                [
                    'id' => $this->app3OrderDetailButtonId,
                    'entity' => 'order',
                    'view' => 'detail',
                    'action' => 'orderDetailApp3',
                    'label' => 'Order Detail App3',
                    'url' => 'app2.com/order/detail',
                ],
            ],
            'integration' => [
                'label' => 'App3',
                'accessKey' => 'test',
                'secretAccessKey' => 'test',
            ],
            'aclRole' => [
                'name' => 'App3',
            ],
        ]], $this->context);
    }
}
