<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Store\Services;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Store\Services\AbstractExtensionStoreLicensesService;
use Shopware\Core\Framework\Store\Services\ExtensionDataProvider;
use Shopware\Core\Framework\Store\Services\StoreService;
use Shopware\Core\Framework\Store\Struct\ReviewStruct;
use Shopware\Core\Framework\Test\Store\StoreClientBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('checkout')]
class ExtensionStoreLicensesServiceTest extends TestCase
{
    use IntegrationTestBehaviour;
    use StoreClientBehaviour;

    private AbstractExtensionStoreLicensesService $extensionLicensesService;

    protected function setUp(): void
    {
        $this->extensionLicensesService = static::getContainer()->get(AbstractExtensionStoreLicensesService::class);
    }

    public function testCancelSubscriptionNotInstalled(): void
    {
        static::getContainer()->get(SystemConfigService::class)->set(StoreService::CONFIG_KEY_STORE_LICENSE_DOMAIN, 'localhost');
        $context = $this->getContextWithStoreToken();

        $this->setCancellationResponses();

        $this->extensionLicensesService->cancelSubscription(1, $context);

        $lastRequest = $this->getStoreRequestHandler()->getLastRequest();
        static::assertNotNull($lastRequest);
        static::assertSame(
            '/swplatform/pluginlicenses/1/cancel',
            $lastRequest->getUri()->getPath()
        );

        static::assertSame(
            [
                'shopwareVersion' => '___VERSION___',
                'language' => 'en-GB',
                'domain' => 'localhost',
            ],
            Query::parse($lastRequest->getUri()->getQuery())
        );
    }

    public function testCreateRating(): void
    {
        $this->getStoreRequestHandler()->append(new Response(200, [], null));
        $review = new ReviewStruct();
        $review->setExtensionId(5);
        $this->extensionLicensesService->rateLicensedExtension($review, $this->getContextWithStoreToken());
    }

    private function getContextWithStoreToken(): Context
    {
        $userId = Uuid::randomHex();

        $data = [
            [
                'id' => $userId,
                'localeId' => $this->getLocaleIdOfSystemLanguage(),
                'username' => 'foobar',
                'password' => TestDefaults::HASHED_PASSWORD,
                'firstName' => 'Foo',
                'lastName' => 'Bar',
                'email' => 'foo@bar.com',
                'storeToken' => Uuid::randomHex(),
                'admin' => true,
                'aclRoles' => [],
            ],
        ];

        static::getContainer()->get('user.repository')->create($data, Context::createDefaultContext());
        $source = new AdminApiSource($userId);
        $source->setIsAdmin(true);

        return Context::createDefaultContext($source);
    }

    private function setLicensesRequest(string $licenseBody): void
    {
        $this->getStoreRequestHandler()->reset();
        $this->getStoreRequestHandler()->append(new Response(200, [], $licenseBody));
    }

    private function setCancellationResponses(): void
    {
        $licenses = json_decode(file_get_contents(__DIR__ . '/../_fixtures/responses/licenses.json') ?: '', true, 512, \JSON_THROW_ON_ERROR);
        $licenses[0]['extension']['name'] = 'TestApp';

        $this->setLicensesRequest(json_encode($licenses, \JSON_THROW_ON_ERROR));
        $this->getStoreRequestHandler()->append(new Response(204));

        unset($licenses[0]);
        $this->getStoreRequestHandler()->append(
            new Response(
                200,
                [ExtensionDataProvider::HEADER_NAME_TOTAL_COUNT => '0'],
                json_encode($licenses, \JSON_THROW_ON_ERROR)
            )
        );
    }
}
