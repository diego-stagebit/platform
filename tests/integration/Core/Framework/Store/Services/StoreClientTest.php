<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Store\Services;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Store\Services\StoreClient;
use Shopware\Core\Framework\Store\StoreException;
use Shopware\Core\Framework\Store\Struct\ExtensionCollection;
use Shopware\Core\Framework\Store\Struct\ExtensionStruct;
use Shopware\Core\Framework\Store\Struct\ReviewStruct;
use Shopware\Core\Framework\Test\Store\StoreClientBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @internal
 */
#[Package('checkout')]
#[CoversClass(StoreClient::class)]
class StoreClientTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StoreClientBehaviour;

    private StoreClient $storeClient;

    private SystemConfigService $configService;

    private Context $storeContext;

    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->configService = static::getContainer()->get(SystemConfigService::class);
        $this->cache = static::getContainer()->get('cache.object');
        $this->storeClient = static::getContainer()->get(StoreClient::class);

        $this->setLicenseDomain('shopware-test');

        $this->storeContext = $this->createAdminStoreContext();
    }

    public function testLoginWithShopwareIdInvalidSource(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('Expected context source to be "' . AdminApiSource::class . '" but got "' . SystemSource::class . '".');

        $this->storeClient->loginWithShopwareId('shopwareId', 'password', Context::createDefaultContext());
    }

    public function testSignPayloadWithAppSecret(): void
    {
        $this->getStoreRequestHandler()->append(new Response(200, [], '{"signature": "signed"}'));

        static::assertSame('signed', $this->storeClient->signPayloadWithAppSecret('[this can be anything]', 'testApp'));

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertInstanceOf(RequestInterface::class, $lastRequest);

        static::assertSame('/swplatform/generatesignature', $lastRequest->getUri()->getPath());

        static::assertSame([
            'shopwareVersion' => $this->getShopwareVersion(),
            'language' => 'en-GB',
            'domain' => 'shopware-test',
        ], Query::parse($lastRequest->getUri()->getQuery()));

        static::assertEquals([
            'appName' => 'testApp',
            'payload' => '[this can be anything]',
        ], \json_decode($lastRequest->getBody()->getContents(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testItUpdatesUserTokenAfterLogin(): void
    {
        $responseBody = \file_get_contents(__DIR__ . '/../_fixtures/responses/login.json');
        static::assertIsString($responseBody);

        $this->getStoreRequestHandler()->append(
            new Response(200, [], $responseBody)
        );

        $this->storeClient->loginWithShopwareId('shopwareId', 'password', $this->storeContext);

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertInstanceOf(RequestInterface::class, $lastRequest);

        static::assertSame([
            'shopwareVersion' => $this->getShopwareVersion(),
            'language' => 'en-GB',
            'domain' => 'shopware-test',
        ], Query::parse($lastRequest->getUri()->getQuery()));

        $contextSource = $this->storeContext->getSource();
        static::assertInstanceOf(AdminApiSource::class, $contextSource);

        static::assertSame([
            'shopwareId' => 'shopwareId',
            'password' => 'password',
            'shopwareUserId' => $contextSource->getUserId(),
        ], \json_decode($lastRequest->getBody()->getContents(), true, flags: \JSON_THROW_ON_ERROR));

        // token from login.json
        static::assertSame(
            'updated-token',
            $this->getStoreTokenFromContext($this->storeContext)
        );

        // secret from login.json
        static::assertSame(
            'shop.secret',
            $this->configService->get('core.store.shopSecret')
        );
    }

    public function testItRequestsUpdatesForLoggedInUser(): void
    {
        $pluginList = new ExtensionCollection();
        $pluginList->add((new ExtensionStruct())->assign([
            'name' => 'TestExtension',
            'version' => '1.0.0',
        ]));

        $this->getStoreRequestHandler()->append(new Response(200, [], \json_encode([
            'data' => [],
        ], \JSON_THROW_ON_ERROR)));

        $updateList = $this->storeClient->getExtensionUpdateList($pluginList, $this->storeContext);

        static::assertSame([], $updateList);

        $cachedList = $this->cache->get(StoreClient::EXTENSION_LIST_CACHE, fn () => null);

        static::assertIsArray($cachedList);
        static::assertSame([], $cachedList);

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertInstanceOf(RequestInterface::class, $lastRequest);

        static::assertSame(
            $this->getStoreTokenFromContext($this->storeContext),
            $lastRequest->getHeader('X-Shopware-Platform-Token')[0],
        );
    }

    public function testItRequestsUpdateForNotLoggedInUser(): void
    {
        $contextSource = $this->storeContext->getSource();
        static::assertInstanceOf(AdminApiSource::class, $contextSource);

        $this->getUserRepository()->update([
            [
                'id' => $contextSource->getUserId(),
                'storeToken' => null,
            ],
        ], Context::createDefaultContext());

        $pluginList = new ExtensionCollection();
        $pluginList->add((new ExtensionStruct())->assign([
            'name' => 'TestExtension',
            'version' => '1.0.0',
            'inAppPurchases' => ['feature1', 'feature2'],
        ]));

        $this->getStoreRequestHandler()->append(new Response(200, [], \json_encode([
            'data' => [
                [
                    'name' => 'TestExtension',
                    'version' => '1.1.0',
                    'inAppFeatures' => 'feature1,feature2',
                ],
            ],
        ], \JSON_THROW_ON_ERROR)));

        $updateList = $this->storeClient->getExtensionUpdateList($pluginList, $this->storeContext);

        static::assertCount(1, $updateList);
        static::assertSame('TestExtension', $updateList[0]->getName());
        static::assertSame('1.1.0', $updateList[0]->getVersion());
        static::assertSame('feature1,feature2', $updateList[0]->getInAppFeatures());

        $cachedList = $this->cache->get(StoreClient::EXTENSION_LIST_CACHE, fn () => null);

        static::assertIsArray($cachedList);
        static::assertCount(1, $cachedList);
        static::assertSame('TestExtension', $cachedList[0]->getName());
        static::assertSame('1.1.0', $cachedList[0]->getVersion());
        static::assertSame('feature1,feature2', $cachedList[0]->getInAppFeatures());

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertInstanceOf(RequestInterface::class, $lastRequest);

        static::assertFalse($lastRequest->hasHeader('X-Shopware-Platform-Token'));
    }

    public function testItReturnsUserInfo(): void
    {
        $userInfo = [
            'name' => 'John Doe',
            'email' => 'john.doe@shopware.com',
            'avatarUrl' => 'https://avatar.shopware.com/john-doe.png',
        ];

        $this->getStoreRequestHandler()->append(new Response(200, [], \json_encode($userInfo, \JSON_THROW_ON_ERROR)));

        $returnedUserInfo = $this->storeClient->userInfo($this->storeContext);

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertInstanceOf(RequestInterface::class, $lastRequest);

        static::assertSame('/swplatform/userinfo', $lastRequest->getUri()->getPath());
        static::assertSame('GET', $lastRequest->getMethod());
        static::assertSame($userInfo, $returnedUserInfo);
    }

    public function testMissingConnectionBecauseYouAreInGermanCellularInternet(): void
    {
        $this->getStoreRequestHandler()->append(new ConnectException(
            'cURL error 7: Failed to connect to api.shopware.com port 443 after 4102 ms: Network is unreachable (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.shopware.com/swplatform/pluginupdates?shopwareVersion=6.4.12.0&language=de-DE&domain=',
            $this->createMock(RequestInterface::class)
        ));

        $pluginList = new ExtensionCollection();
        $pluginList->add((new ExtensionStruct())->assign([
            'name' => 'TestExtension',
            'version' => '1.0.0',
        ]));

        $returnedUserInfo = $this->storeClient->getExtensionUpdateList($pluginList, $this->storeContext);

        static::assertSame([], $returnedUserInfo);
    }

    public function testCancelExtensionAlreadyCancelled(): void
    {
        $errorInfo = [
            'success' => false,
            'code' => StoreClient::EXTENSION_LICENSE_IS_ALREADY_CANCELLED,
            'title' => 'Error',
            'description' => 'The license is already cancelled',
        ];
        $this->getStoreRequestHandler()->append(new Response(400, [], \json_encode($errorInfo, \JSON_THROW_ON_ERROR)));

        $this->storeClient->cancelSubscription(123, $this->storeContext);

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertInstanceOf(RequestInterface::class, $lastRequest);

        static::assertSame('/swplatform/pluginlicenses/123/cancel', $lastRequest->getUri()->getPath());
        static::assertSame('POST', $lastRequest->getMethod());
    }

    public function testCancelSubscriptionAlreadyCancelled(): void
    {
        $errorInfo = [
            'success' => false,
        ];
        $this->getStoreRequestHandler()->append(new Response(400, [], \json_encode($errorInfo, \JSON_THROW_ON_ERROR)));

        $this->expectException(StoreException::class);
        $this->storeClient->cancelSubscription(123, $this->storeContext);
    }

    public function testCreateRatingThrowsExceptionOnClientError(): void
    {
        $this->getStoreRequestHandler()->append(new ClientException(
            'Client error',
            $this->createMock(RequestInterface::class),
            $this->createMock(ResponseInterface::class)
        ));

        $rating = new ReviewStruct();
        $rating->setExtensionId(123);

        $this->expectException(StoreException::class);
        $this->storeClient->createRating($rating, $this->storeContext);
    }

    public function testFetchLicensesThrowsExceptionOnClientError(): void
    {
        $this->getStoreRequestHandler()->append(new ClientException(
            'Client error',
            $this->createMock(RequestInterface::class),
            $this->createMock(ResponseInterface::class)
        ));

        $this->expectException(StoreException::class);
        $this->storeClient->listMyExtensions(new ExtensionCollection(), $this->storeContext);
    }
}
