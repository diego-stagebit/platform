<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Controller;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Script\Debugging\ScriptTraces;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Wishlist\GuestWishlistPageLoadedHook;
use Shopware\Storefront\Page\Wishlist\WishlistPageLoadedHook;
use Shopware\Storefront\Page\Wishlist\WishlistWidgetLoadedHook;
use Shopware\Storefront\Pagelet\Wishlist\GuestWishlistPageletLoadedHook;
use Shopware\Storefront\Test\Controller\StorefrontControllerTestBehaviour;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * @internal
 */
class WishlistControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;
    use StorefrontControllerTestBehaviour;

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerId = Uuid::randomHex();
        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        $systemConfig->set('core.cart.wishlistEnabled', true);
    }

    #[Before]
    #[After]
    public function clearFlashBag(): void
    {
        $this->getFlashBag()->clear();
    }

    public function testWishlistIndex(): void
    {
        $browser = $this->login();

        $productId = $this->createProduct($this->getSalesChannelId());

        // add product to wishlist
        $this->createCustomerWishlist($productId, $this->getSalesChannelId());

        $browser->request('GET', '/wishlist');
        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode(), $response->getContent() ?: '');
    }

    public function testWishlistGuestIndex(): void
    {
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());

        $browser->request('GET', $_SERVER['APP_URL'] . '/wishlist');

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode());
    }

    public function testWishlistGuestPageletShouldThrowExceptionWhenLoggedIn(): void
    {
        $browser = $this->login();

        $browser->request('GET', $_SERVER['APP_URL']);

        $productId = $this->createProduct($browser->getRequest()->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID));

        $browser->request('POST', $_SERVER['APP_URL'] . '/wishlist/guest-pagelet', $this->tokenize('frontend.wishlist.guestPage.pagelet', ['productIds' => [$productId]]));

        static::assertSame(Response::HTTP_NOT_FOUND, $browser->getResponse()->getStatusCode());
    }

    public function testWishlistGuestPagelet(): void
    {
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());

        $productId = $this->createProduct($this->getSalesChannelId());

        $this->addEventListener(static::getContainer()->get('event_dispatcher'), StorefrontRenderEvent::class, function (StorefrontRenderEvent $event) use ($productId): void {
            static::assertInstanceOf(EntitySearchResult::class, $result = $event->getParameters()['searchResult']);
            static::assertCount(1, $result);
            static::assertInstanceOf(Entity::class, $result->first());
            static::assertSame($productId, $result->first()->get('id'));
        });

        $browser->request('POST', $_SERVER['APP_URL'] . '/wishlist/guest-pagelet', $this->tokenize('frontend.wishlist.guestPage.pagelet', ['productIds' => [$productId]]));

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode());
    }

    public function testDeleteProductInWishlistPage(): void
    {
        $browser = $this->login();

        $productId = $this->createProduct($this->getSalesChannelId());

        $browser->request('DELETE', '/wishlist/product/delete/' . $productId);

        static::assertSame(
            ['danger' => ['Unfortunately, something went wrong.']],
            $this->getFlashBag()->all()
        );

        $this->createCustomerWishlist($productId, $this->getSalesChannelId());

        $browser->request('DELETE', '/wishlist/product/delete/' . $productId);
        $response = $browser->getResponse();

        static::assertArrayHasKey('success', $this->getFlashBag()->all());
        static::assertSame(200, $response->getStatusCode());
    }

    public function testAjaxListWithoutCreatedWishlist(): void
    {
        $browser = $this->login();

        $browser->request('GET', $_SERVER['APP_URL'] . '/wishlist/list');

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        static::assertEmpty(json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAjaxAdd(): void
    {
        $browser = $this->login();

        $productId = $this->createProduct($this->getSalesChannelId());

        $browser->request('POST', $_SERVER['APP_URL'] . '/wishlist/add/' . $productId, $this->tokenize('frontend.wishlist.product.add', []));

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $content = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotEmpty($content);
        static::assertTrue($content['success']);
    }

    public function testAjaxList(): void
    {
        $browser = $this->login();

        $browser->request('GET', $_SERVER['APP_URL'] . '/wishlist/list');

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testAjaxRemove(): void
    {
        $browser = $this->login();

        $productId = $this->createProduct($this->getSalesChannelId());

        $browser->request('POST', $_SERVER['APP_URL'] . '/wishlist/add/' . $productId, $this->tokenize('frontend.wishlist.product.add', []));

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $browser->request('POST', $_SERVER['APP_URL'] . '/wishlist/remove/' . $productId, $this->tokenize('frontend.wishlist.product.remove', []));

        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $content = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotEmpty($content);
        static::assertTrue($content['success']);
    }

    public function testAddAfterLogin(): void
    {
        $browser = $this->login();

        $productId = $this->createProduct($this->getSalesChannelId());

        $browser->request('GET', $_SERVER['APP_URL'] . '/wishlist/add-after-login/' . $productId);

        /** @var RedirectResponse $response */
        $response = $browser->getResponse();

        static::assertSame(302, $response->getStatusCode());
        static::assertInstanceOf(RedirectResponse::class, $response);
        static::assertSame('/wishlist', $response->getTargetUrl());

        $session = $this->getSession();
        static::assertInstanceOf(Session::class, $session);
        $flashBag = $session->getFlashBag();

        static::assertNotEmpty($successFlash = $flashBag->get('success'));
        static::assertSame('You have successfully added the product to your wishlist.', $successFlash[0]);

        $browser->request('GET', $_SERVER['APP_URL'] . '/wishlist/add-after-login/' . $productId);

        static::assertNotEmpty($warningFlash = $flashBag->get('warning'));
        static::assertSame('Product has already been added to your wishlist.', $warningFlash[0]);
    }

    public function testWishlistPageLoadedHookScriptsAreExecuted(): void
    {
        $browser = $this->login();

        $browser->request('GET', '/wishlist');
        $response = $browser->getResponse();
        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(WishlistPageLoadedHook::HOOK_NAME, $traces);
    }

    public function testGuestWishlistPageLoadedHookScriptsAreExecuted(): void
    {
        $response = $this->request('GET', '/wishlist', []);
        static::assertSame(200, $response->getStatusCode());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(GuestWishlistPageLoadedHook::HOOK_NAME, $traces);
    }

    public function testGuestWishlistPageletLoadedHookScriptsAreExecuted(): void
    {
        $browser = $this->registerAsGuest();

        $browser->xmlHttpRequest(
            'POST',
            $_SERVER['APP_URL'] . '/wishlist/guest-pagelet'
        );
        $response = $browser->getResponse();

        static::assertSame(200, $response->getStatusCode());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(GuestWishlistPageletLoadedHook::HOOK_NAME, $traces);
    }

    public function testWishlistPageLoadedHookScriptsAreExecutedForWidget(): void
    {
        $browser = $this->login();

        $browser->request('GET', '/widgets/wishlist');
        $response = $browser->getResponse();
        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(WishlistPageLoadedHook::HOOK_NAME, $traces);
    }

    public function testWishlistWidgetLoadedHookScriptsAreExecuted(): void
    {
        $browser = $this->login();

        $browser->request('GET', '/wishlist/merge/pagelet');
        $response = $browser->getResponse();
        static::assertSame(200, $response->getStatusCode());

        $traces = static::getContainer()->get(ScriptTraces::class)->getTraces();

        static::assertArrayHasKey(WishlistWidgetLoadedHook::HOOK_NAME, $traces);
    }

    private function createCustomer(): CustomerEntity
    {
        $addressId = Uuid::randomHex();

        $customer = [
            'id' => $this->customerId,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'defaultShippingAddress' => [
                'id' => $addressId,
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Musterstraße 1',
                'city' => 'Schöppingen',
                'zipcode' => '12345',
                'salutationId' => $this->getValidSalutationId(),
                'countryId' => $this->getValidCountryId(),
            ],
            'defaultBillingAddressId' => $addressId,
            'groupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'email' => 'testuser@example.com',
            'password' => TestDefaults::HASHED_PASSWORD,
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'salutationId' => $this->getValidSalutationId(),
            'customerNumber' => '12345',
        ];

        $repo = static::getContainer()->get('customer.repository');

        $repo->create([$customer], Context::createDefaultContext());

        $entity = $repo->search(new Criteria([$this->customerId]), Context::createDefaultContext())->first();

        static::assertInstanceOf(CustomerEntity::class, $entity);

        return $entity;
    }

    private function login(): KernelBrowser
    {
        $customer = $this->createCustomer();

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request(
            'POST',
            EnvironmentHelper::getVariable('APP_URL') . '/account/login',
            $this->tokenize('frontend.account.login', [
                'username' => $customer->getEmail(),
                'password' => 'shopware',
            ])
        );
        $response = $browser->getResponse();
        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $browser;
    }

    private function registerAsGuest(): KernelBrowser
    {
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request(
            'POST',
            $_SERVER['APP_URL'] . '/account/register',
            $this->tokenize('frontend.account.register.save', [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_PRIVATE,
                'email' => 'max.mustermann@example.com',
                'emailConfirmation' => 'max.mustermann@example.com',
                'salutationId' => $this->getValidSalutationId(),
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'storefrontUrl' => 'http://localhost',
                'guest' => true,
                'billingAddress' => [
                    'countryId' => $this->getValidCountryId(),
                    'street' => 'Musterstrasse 13',
                    'zipcode' => '48599',
                    'city' => 'Epe',
                ],
            ])
        );
        $response = $browser->getResponse();
        static::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $browser;
    }

    private function createProduct(?string $salesChannelId = null): string
    {
        $id = Uuid::randomHex();

        $product = [
            'id' => $id,
            'productNumber' => $id,
            'stock' => 5,
            'name' => 'Test',
            'isCloseout' => true,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
            'tax' => ['id' => Uuid::randomHex(), 'name' => 'test', 'taxRate' => 19],
            'manufacturer' => ['name' => 'test'],
            'visibilities' => [
                [
                    'salesChannelId' => $salesChannelId ?? TestDefaults::SALES_CHANNEL,
                    'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
                ],
            ],
        ];

        $repository = static::getContainer()->get('product.repository');

        $repository->create([$product], Context::createDefaultContext());

        return $id;
    }

    private function createCustomerWishlist(string $productId, string $salesChannelId): string
    {
        $customerWishlistId = Uuid::randomHex();
        $customerWishlistRepository = static::getContainer()->get('customer_wishlist.repository');

        $customerWishlistRepository->create([
            [
                'id' => $customerWishlistId,
                'customerId' => $this->customerId,
                'salesChannelId' => $salesChannelId,
                'products' => [
                    [
                        'productId' => $productId,
                    ],
                ],
            ],
        ], Context::createDefaultContext());

        return $customerWishlistId;
    }

    private function getFlashBag(): FlashBagInterface
    {
        $session = $this->getSession();

        if (!method_exists($session, 'getFlashBag')) {
            throw new \RuntimeException('session does not have flashbag');
        }

        return $session->getFlashBag();
    }
}
