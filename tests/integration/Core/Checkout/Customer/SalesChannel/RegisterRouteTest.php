<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Customer\SalesChannel;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerConfirmRegisterUrlEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerDoubleOptInRegistrationEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Checkout\Customer\Rule\CustomerLoggedInRule;
use Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Test\TestCaseBase\CountryAddToSalesChannelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\Salutation\SalutationDefinition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * @internal
 */
#[Package('checkout')]
#[Group('store-api')]
class RegisterRouteTest extends TestCase
{
    use CountryAddToSalesChannelTestBehaviour;
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    private KernelBrowser $browser;

    private IdsCollection $ids;

    /**
     * @var EntityRepository<CustomerCollection>
     */
    private EntityRepository $customerRepository;

    private SystemConfigService $systemConfigService;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();

        $this->browser = $this->createCustomSalesChannelBrowser([
            'id' => $this->ids->create('sales-channel'),
        ]);

        $this->addCountriesToSalesChannel([], $this->ids->get('sales-channel'));

        $this->assignSalesChannelContext($this->browser);
        $this->customerRepository = static::getContainer()->get('customer.repository');

        $this->systemConfigService = static::getContainer()->get(SystemConfigService::class);
    }

    public function testRegistration(): void
    {
        $registrationData = $this->getRegistrationData();
        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $connection = static::getContainer()->get(Connection::class);
        $result = $connection->fetchOne(
            'SELECT `payload` FROM `sales_channel_api_context` WHERE `customer_id` = :customerId ',
            [
                'customerId' => Uuid::fromHexToBytes($response['id']),
            ]
        );
        $result = json_decode((string) $result, true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('domainId', $result);

        static::assertSame('customer', $response['apiAlias']);
        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegisterEventWithCustomerRules(): void
    {
        $ids = new IdsCollection();

        $rule = [
            'id' => $ids->create('rule'),
            'name' => 'Test rule',
            'priority' => 1,
            'conditions' => [
                ['type' => (new CustomerLoggedInRule())->getName(), 'value' => ['isLoggedIn' => true]],
            ],
        ];

        static::getContainer()->get('rule.repository')->create([$rule], Context::createDefaultContext());

        $ruleIds = null;
        static::getContainer()->get('event_dispatcher')->addListener(CustomerRegisterEvent::class, static function (CustomerRegisterEvent $event) use (&$ruleIds): void {
            $ruleIds = $event->getSalesChannelContext()->getRuleIds();
        });

        $this->browser->request('POST', '/store-api/account/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR));

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('apiAlias', $response, \print_r($response, true));
        static::assertSame('customer', $response['apiAlias']);

        $this->browser->request('POST', '/store-api/account/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => 'teg-reg@example.com', 'password' => '12345678'], \JSON_THROW_ON_ERROR));

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);

        static::assertNotNull($ruleIds, 'Register event was not dispatched');
        static::assertContains($ids->get('rule'), $ruleIds, 'Context was not reloaded');
    }

    #[DataProvider('customerBoundToSalesChannelProvider')]
    public function testRegistrationWithCustomerScope(
        bool $isCustomerScoped,
        bool $hasGlobalAccount,
        bool $hasBoundAccount,
        bool $requestOnSameSalesChannel,
        int $expectedStatus
    ): void {
        static::getContainer()->get(SystemConfigService::class)->set('core.systemWideLoginRegistration.isCustomerBoundToSalesChannel', $isCustomerScoped);

        if ($hasGlobalAccount || $hasBoundAccount) {
            $boundSalesChannel = $isCustomerScoped && $hasBoundAccount;
            $this->createBoundCustomer($this->ids->get('sales-channel'), $this->getRegistrationData()['email'], $boundSalesChannel);
        }

        $browser = $requestOnSameSalesChannel ? $this->browser : $this->createCustomSalesChannelBrowser([
            'id' => $this->ids->create('sales-channel-2'),
            'domains' => [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'currencyId' => Defaults::CURRENCY,
                    'snippetSetId' => $this->getSnippetSetIdForLocale('en-GB'),
                    'url' => 'http://localhost2',
                ],
            ],
        ]);

        $storefrontUrl = $requestOnSameSalesChannel ? 'http://localhost' : 'http://localhost2';

        $browser->request(
            'POST',
            '/store-api/account/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->getRegistrationData($storefrontUrl), \JSON_THROW_ON_ERROR)
        );

        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame($expectedStatus, $browser->getResponse()->getStatusCode());

        if ($expectedStatus === 200) {
            static::assertSame('customer', $response['apiAlias']);
            static::assertArrayNotHasKey('errors', $response);
            static::assertNotEmpty($browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

            $browser->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

            $response = $this->browser->getResponse();

            $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
            static::assertNotEmpty($contextToken);
        } else {
            static::assertNotEmpty($response['errors']);
            static::assertSame('VIOLATION::CUSTOMER_EMAIL_NOT_UNIQUE', $response['errors'][0]['code']);
        }
    }

    public function testRegistrationWithGivenToken(): void
    {
        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);
        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', (string) $this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer'
            );

        $customer = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayNotHasKey('errors', $customer);
        static::assertSame('customer', $customer['apiAlias']);
    }

    /**
     * @param array<string, string> $domainUrlTest
     */
    #[DataProvider('registerWithDomainAndLeadingSlashProvider')]
    public function testRegistrationWithTrailingSlashUrl(array $domainUrlTest): void
    {
        $browser = $this->createCustomSalesChannelBrowser([
            'id' => $this->ids->create('sales-channel-3'),
            'domains' => [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'currencyId' => Defaults::CURRENCY,
                    'snippetSetId' => $this->getSnippetSetIdForLocale('en-GB'),
                    'url' => $domainUrlTest['domain'],
                ],
            ],
        ]);

        $browser->request(
            'POST',
            '/store-api/account/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->getRegistrationData($domainUrlTest['expectDomain']), \JSON_THROW_ON_ERROR)
        );

        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame(200, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());

        static::assertSame('customer', $response['apiAlias']);
        static::assertArrayNotHasKey('errors', $response);
        static::assertNotEmpty($browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $browser->request(
            'POST',
            '/store-api/account/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'teg-reg@example.com',
                'password' => '12345678',
            ], \JSON_THROW_ON_ERROR)
        );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    /**
     * @return array{array{array{domain: string, expectDomain: string}}, array{array{domain: string, expectDomain: string}}}
     */
    public static function registerWithDomainAndLeadingSlashProvider(): array
    {
        return [
            // test without leading slash
            [
                ['domain' => 'http://my-evil-page', 'expectDomain' => 'http://my-evil-page'],
            ],
            // test with leading slash
            [
                ['domain' => 'http://my-evil-page/', 'expectDomain' => 'http://my-evil-page'],
            ],
            // test with double leading slash
            [
                ['domain' => 'http://my-evil-page//', 'expectDomain' => 'http://my-evil-page'],
            ],
        ];
    }

    public function testDoubleOptin(): void
    {
        $systemConfig = static::getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.loginRegistration.doubleOptInRegistration', true);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);

        $customerId = $response['id'];

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);

        $responseData = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayHasKey('errors', $responseData);
        static::assertSame('CHECKOUT__CUSTOMER_OPTIN_NOT_COMPLETED', $responseData['errors'][0]['code']);
        static::assertSame('401', $responseData['errors'][0]['status']);

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, Context::createDefaultContext())->first();
        static::assertInstanceOf(CustomerEntity::class, $customer);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register-confirm',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'hash' => $customer->getHash(),
                    'em' => Hasher::hash('teg-reg@example.com', 'sha1'),
                ], \JSON_THROW_ON_ERROR)
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testDoubleOptinContextReloadForEvents(): void
    {
        $ids = new IdsCollection();

        $rule = [
            'id' => $ids->create('rule'),
            'name' => 'Test rule',
            'priority' => 1,
            'conditions' => [
                ['type' => (new CustomerLoggedInRule())->getName(), 'value' => ['isLoggedIn' => true]],
            ],
        ];

        static::getContainer()->get('rule.repository')->create([$rule], Context::createDefaultContext());

        $ruleIds = null;
        static::getContainer()->get('event_dispatcher')->addListener(CustomerRegisterEvent::class, static function (CustomerRegisterEvent $event) use (&$ruleIds): void {
            $ruleIds = $event->getSalesChannelContext()->getRuleIds();
        });

        $systemConfig = static::getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.loginRegistration.doubleOptInRegistration', true);

        $this->browser->request('POST', '/store-api/account/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR));

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);

        $customerId = $response['id'];

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, Context::createDefaultContext())->first();
        static::assertInstanceOf(CustomerEntity::class, $customer);

        $this->browser->request('POST', '/store-api/account/register-confirm', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['hash' => $customer->getHash(), 'em' => Hasher::hash('teg-reg@example.com', 'sha1')], \JSON_THROW_ON_ERROR));

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());

        static::assertNotNull($ruleIds, 'Register event was not dispatched');
        static::assertContains($ids->get('rule'), $ruleIds, 'Context was not reloaded');
    }

    public function testDoubleOptinChangedUrl(): void
    {
        $systemConfig = static::getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.loginRegistration.doubleOptInRegistration', true);
        $systemConfig->set('core.loginRegistration.confirmationUrl', '/confirm/custom/%%HASHEDEMAIL%%/%%SUBSCRIBEHASH%%');

        $dispatcher = static::getContainer()->get('event_dispatcher');

        $this->addEventListener(
            $dispatcher,
            CustomerConfirmRegisterUrlEvent::class,
            static function (CustomerConfirmRegisterUrlEvent $event): void {
                $event->setConfirmUrl($event->getConfirmUrl());
            }
        );

        $caughtEvent = null;
        $this->addEventListener(
            $dispatcher,
            CustomerDoubleOptInRegistrationEvent::class,
            static function (CustomerDoubleOptInRegistrationEvent $event) use (&$caughtEvent): void {
                $caughtEvent = $event;
            }
        );

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR)
            );

        static::assertInstanceOf(CustomerDoubleOptInRegistrationEvent::class, $caughtEvent);
        static::assertStringStartsWith('http://localhost/confirm/custom/', $caughtEvent->getConfirmUrl());
    }

    public function testDoubleOptinGivenTokenIsNotLoggedin(): void
    {
        $systemConfig = static::getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.loginRegistration.doubleOptInRegistration', true);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);

        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', (string) $this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer'
            );

        $customer = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayHasKey('errors', $customer);
        static::assertSame(RoutingException::CUSTOMER_NOT_LOGGED_IN_CODE, $customer['errors'][0]['code']);
    }

    public function testDoubleOptinWithHeaderToken(): void
    {
        $systemConfig = static::getContainer()->get(SystemConfigService::class);

        $systemConfig->set('core.loginRegistration.doubleOptInRegistration', true);

        // Register
        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($this->getRegistrationData(), \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);
        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', (string) $this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        // Validate I am not logged in
        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer'
            );

        $customer = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayHasKey('errors', $customer);
        static::assertSame(RoutingException::CUSTOMER_NOT_LOGGED_IN_CODE, $customer['errors'][0]['code']);

        $customerId = $response['id'];

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, Context::createDefaultContext())->first();
        static::assertInstanceOf(CustomerEntity::class, $customer);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register-confirm',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'hash' => $customer->getHash(),
                    'em' => Hasher::hash('teg-reg@example.com', 'sha1'),
                ], \JSON_THROW_ON_ERROR)
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertTrue($response['active']);
        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', (string) $this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer'
            );

        $customer = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertArrayNotHasKey('errors', $customer);
        static::assertSame('customer', $response['apiAlias']);
    }

    public function testRegistrationWithRequestedGroup(): void
    {
        $customerGroupRepository = static::getContainer()->get('customer_group.repository');
        $customerGroupRepository->create([
            [
                'id' => $this->ids->create('group'),
                'name' => 'foo',
                'registration' => [
                    'title' => 'test',
                ],
                'registrationSalesChannels' => [['id' => $this->getSalesChannelApiSalesChannelId()]],
            ],
        ], Context::createDefaultContext());

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([...$this->getRegistrationData(), ...['requestedGroupId' => $this->ids->get('group')]], \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);

        $customer = $this->customerRepository->search(new Criteria([$response['id']]), Context::createDefaultContext())->first();
        static::assertInstanceOf(CustomerEntity::class, $customer);

        static::assertSame($this->ids->get('group'), $customer->getRequestedGroupId());
    }

    public function testContextChangedBetweenRegistration(): void
    {
        $context = static::getContainer()->get(SalesChannelContextFactory::class)
            ->create('test', $this->getSalesChannelApiSalesChannelId());

        $bag = new RequestDataBag($this->getRegistrationData());
        static::getContainer()->get(RegisterRoute::class)->register($bag, $context);

        static::assertNotSame('test', $context->getToken());
    }

    /**
     * @return array<int, array{isCustomerScoped: bool, hasGlobalAccount: bool, hasBoundAccount: bool, requestOnSameSalesChannel:bool, expectedStatus: int}>
     */
    public static function customerBoundToSalesChannelProvider(): array
    {
        return [[
            'isCustomerScoped' => true,
            'hasGlobalAccount' => false,
            'hasBoundAccount' => true, // Account which has bound_sales_channel_id not null
            'requestOnSameSalesChannel' => true,
            'expectedStatus' => 400, // Email existed status
        ], [
            'isCustomerScoped' => true,
            'hasGlobalAccount' => false,
            'hasBoundAccount' => true,
            'requestOnSameSalesChannel' => false,
            'expectedStatus' => 200, // Success status
        ], [
            'isCustomerScoped' => true,
            'hasGlobalAccount' => true, // Account which has bound_sales_channel_id = null
            'hasBoundAccount' => false,
            'requestOnSameSalesChannel' => true,
            'expectedStatus' => 400,
        ], [
            'isCustomerScoped' => true,
            'hasGlobalAccount' => true,
            'hasBoundAccount' => false,
            'requestOnSameSalesChannel' => false,
            'expectedStatus' => 400,
        ], [
            'isCustomerScoped' => true,
            'hasGlobalAccount' => false,
            'hasBoundAccount' => false,
            'requestOnSameSalesChannel' => true,
            'expectedStatus' => 200,
        ], [
            'isCustomerScoped' => false,
            'hasGlobalAccount' => false,
            'hasBoundAccount' => true,
            'requestOnSameSalesChannel' => true,
            'expectedStatus' => 400,
        ], [
            'isCustomerScoped' => false,
            'hasGlobalAccount' => false,
            'hasBoundAccount' => true,
            'requestOnSameSalesChannel' => false,
            'expectedStatus' => 400,
        ], [
            'isCustomerScoped' => false,
            'hasGlobalAccount' => true,
            'hasBoundAccount' => false,
            'requestOnSameSalesChannel' => true,
            'expectedStatus' => 400,
        ], [
            'isCustomerScoped' => false,
            'hasGlobalAccount' => true,
            'hasBoundAccount' => false,
            'requestOnSameSalesChannel' => false,
            'expectedStatus' => 400,
        ], [
            'isCustomerScoped' => false,
            'hasGlobalAccount' => false,
            'hasBoundAccount' => false,
            'requestOnSameSalesChannel' => true,
            'expectedStatus' => 200,
        ]];
    }

    public function testRegistrationWithAllowedAccountType(): void
    {
        $accountTypes = static::getContainer()->getParameter('customer.account_types');
        static::assertIsArray($accountTypes);
        $accountType = $accountTypes[array_rand($accountTypes)];

        $additionalData = [
            'accountType' => $accountType,
            'billingAddress' => [
                'company' => 'Test Company',
                'department' => 'Test Department',
            ],
            'vatIds' => [
                'DE123456789',
            ],
        ];
        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame($accountType, $response['accountType']);
        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegistrationWithWrongAccountType(): void
    {
        $accountTypes = static::getContainer()->getParameter('customer.account_types');
        static::assertIsArray($accountTypes);
        $notAllowedAccountType = implode('', $accountTypes);
        $additionalData = [
            'accountType' => $notAllowedAccountType,
            'billingAddress' => [
                'company' => 'Test Company',
                'department' => 'Test Department',
            ],
            'vatIds' => [
                'DE123456789',
            ],
        ];

        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertSame(Response::HTTP_BAD_REQUEST, $this->browser->getResponse()->getStatusCode());
        static::assertArrayHasKey('errors', $response);
        static::assertCount(1, $response['errors']);
        static::assertIsArray($response['errors'][0]);
        static::assertSame('VIOLATION::NO_SUCH_CHOICE_ERROR', $response['errors'][0]['code']);
    }

    public function testRegistrationWithoutAccountTypeIsEmptyString(): void
    {
        $additionalData = [
            'accountType' => '',
        ];
        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $customerDefinition = new CustomerDefinition();
        static::assertArrayHasKey('accountType', $customerDefinition->getDefaults());
        static::assertSame($customerDefinition->getDefaults()['accountType'], $response['accountType']);

        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegistrationWithoutAccountTypeFallbackToDefaultValue(): void
    {
        $registrationData = array_merge_recursive($this->getRegistrationData());

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $customerDefinition = new CustomerDefinition();
        static::assertArrayHasKey('accountType', $customerDefinition->getDefaults());
        static::assertSame($customerDefinition->getDefaults()['accountType'], $response['accountType']);

        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegistrationCommercialAccountWithVatIds(): void
    {
        $additionalData = [
            'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
            'billingAddress' => [
                'company' => 'Test Company',
                'department' => 'Test Department',
            ],
            'vatIds' => [
                'DE123456789',
            ],
        ];
        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);
        static::assertSame(['DE123456789'], $response['vatIds']);
        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegistrationCommercialAccountWithVatIdsIsEmpty(): void
    {
        $additionalData = [
            'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
            'billingAddress' => [
                'company' => 'Test Company',
                'department' => 'Test Department',
            ],
            'vatIds' => [],
        ];
        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        if (static::getContainer()->get(SystemConfigService::class)->get('core.loginRegistration.vatIdFieldRequired', $this->getSalesChannelApiSalesChannelId())) {
            static::assertArrayHasKey('errors', $response);
        } else {
            static::assertSame('customer', $response['apiAlias']);
            static::assertEmpty($response['vatIds']);
            static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

            $this->browser
                ->request(
                    'POST',
                    '/store-api/account/login',
                    [],
                    [],
                    ['CONTENT_TYPE' => 'application/json'],
                    json_encode([
                        'email' => 'teg-reg@example.com',
                        'password' => '12345678',
                    ], \JSON_THROW_ON_ERROR)
                );

            $response = $this->browser->getResponse();

            $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
            static::assertNotEmpty($contextToken);
        }
    }

    public function testRegistrationBusinessAccountWithVatIdsNotMatchRegex(): void
    {
        static::getContainer()->get(Connection::class)
            ->executeStatement('UPDATE `country` SET `check_vat_id_pattern` = 1, `vat_id_pattern` = "(DE)?[0-9]{9}" WHERE id = :id', ['id' => Uuid::fromHexToBytes($this->getValidCountryId($this->ids->get('sales-channel')))]);

        $additionalData = [
            'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'company' => 'Test Company',
                'department' => 'Test Department',
            ],
            'vatIds' => [
                'abcd',
            ],
        ];

        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('errors', $response);
    }

    public function testRegistrationBusinessAccountWithVatIdsMatchRegex(): void
    {
        static::getContainer()->get(Connection::class)
            ->executeStatement('UPDATE `country` SET `check_vat_id_pattern` = 1, `vat_id_pattern` = "(DE)?[0-9]{9}" WHERE id = :id', ['id' => Uuid::fromHexToBytes($this->getValidCountryId())]);

        $additionalData = [
            'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
            'billingAddress' => [
                'company' => 'Test Company',
                'department' => 'Test Department',
            ],
            'vatIds' => [
                '123456789',
            ],
        ];

        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);
        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegistrationCommercialAccountWithDifferentCommercialAddress(): void
    {
        $this->systemConfigService->set('core.loginRegistration.showAccountTypeSelection', true);

        $additionalData = [
            'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
            'billingAddress' => [
                'company' => 'Test Company 1',
                'department' => 'Test Department 1',
            ],
            'shippingAddress' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'company' => 'Test Company 2',
                'department' => 'Test Department 2',
            ],
        ];
        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('customer', $response['apiAlias']);

        $criteria = new Criteria([$response['id']]);
        $criteria->addAssociation('addresses');

        $customer = $this->customerRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertInstanceOf(CustomerEntity::class, $customer);

        $customerAddressCollection = $customer->getAddresses();

        static::assertNotNull($customerAddressCollection);

        static::assertCount(2, $customerAddressCollection->getElements());

        $addressesCompany = [];
        $addressesDepartment = [];
        foreach ($customerAddressCollection->getElements() as $address) {
            $addressesCompany[] = $address->getCompany();
            $addressesDepartment[] = $address->getDepartment();
        }

        sort($addressesCompany);
        sort($addressesDepartment);

        static::assertSame('Test Company 1', $addressesCompany[0]);
        static::assertSame('Test Company 2', $addressesCompany[1]);
        static::assertSame('Test Department 1', $addressesDepartment[0]);
        static::assertSame('Test Department 2', $addressesDepartment[1]);

        static::assertNotEmpty($this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN));

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'email' => 'teg-reg@example.com',
                    'password' => '12345678',
                ], \JSON_THROW_ON_ERROR)
            );

        $response = $this->browser->getResponse();

        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);
    }

    public function testRegistrationCommercialAccountWithDifferentCommercialAddressButEmptyCompany(): void
    {
        $this->systemConfigService->set('core.loginRegistration.showAccountTypeSelection', true);

        $additionalData = [
            'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'company' => 'Test Company 1',
                'department' => 'Test Department 1',
            ],
            'shippingAddress' => [
                'accountType' => CustomerEntity::ACCOUNT_TYPE_BUSINESS,
                'department' => 'Test Department 2',
            ],
        ];
        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('errors', $response);
    }

    public function testRegistrationWithActiveCart(): void
    {
        $this->createProductTestData();
        $this->browser
            ->request(
                'POST',
                '/store-api/checkout/cart/line-item',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode([
                    'items' => [
                        [
                            'id' => $this->ids->get('p1'),
                            'label' => 'foo',
                            'type' => 'product',
                            'referencedId' => $this->ids->get('p1'),
                        ],
                    ],
                ], \JSON_THROW_ON_ERROR)
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());
        static::assertTrue($this->browser->getResponse()->headers->has(PlatformRequest::HEADER_CONTEXT_TOKEN));
        $contextToken = $this->browser->getResponse()->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', (string) $contextToken);

        $additionalData = [
            'guest' => true,
        ];

        $registrationData = array_merge_recursive($this->getRegistrationData(), $additionalData);
        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());
        static::assertTrue($this->browser->getResponse()->headers->has(PlatformRequest::HEADER_CONTEXT_TOKEN));
        $newContextToken = $this->browser->getResponse()->headers->all(PlatformRequest::HEADER_CONTEXT_TOKEN);
        static::assertCount(1, $newContextToken);
        static::assertNotSame($contextToken, $newContextToken);
    }

    public function testRegistrationWithEmptyBillingAddress(): void
    {
        $registrationData = $this->getRegistrationData();
        unset($registrationData['billingAddress']);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());
    }

    public function testRegistrationWithoutAnyAddress(): void
    {
        $registrationData = $this->getRegistrationData();
        unset($registrationData['billingAddress']);
        unset($registrationData['shippingAddress']);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotEmpty($response['errors']);
        static::assertSame('VIOLATION::IS_BLANK_ERROR', $response['errors'][0]['code']);
    }

    public function testRegistrationWithExistingNotSpecifiedSalutation(): void
    {
        $connection = static::getContainer()->get(Connection::class);

        $registrationData = $this->getRegistrationData();
        unset($registrationData['salutationId']);

        $salutations = $connection->fetchAllKeyValue('SELECT salutation_key, id FROM salutation');
        static::assertArrayHasKey(SalutationDefinition::NOT_SPECIFIED, $salutations);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $criteria = new Criteria([$response['id']]);
        $criteria->addAssociation('salutation');

        $customer = $this->customerRepository->search($criteria, Context::createDefaultContext())->first();

        static::assertInstanceOf(CustomerEntity::class, $customer);

        static::assertSame($customer->getSalutation()?->getSalutationKey(), SalutationDefinition::NOT_SPECIFIED);
    }

    public function testRegistrationToNotSpecifiedWithoutExistingSalutation(): void
    {
        $connection = static::getContainer()->get(Connection::class);

        $registrationData = $this->getRegistrationData();
        unset($registrationData['salutationId']);

        $connection->executeStatement(
            '
					DELETE FROM salutation WHERE salutation_key = :salutationKey
				',
            ['salutationKey' => SalutationDefinition::NOT_SPECIFIED]
        );

        $salutations = $connection->fetchAllKeyValue('SELECT salutation_key, id FROM salutation');
        static::assertArrayNotHasKey(SalutationDefinition::NOT_SPECIFIED, $salutations);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertNull($response['salutationId'], (string) $this->browser->getResponse()->getContent());
    }

    public function testRegistrationWithIdnEmail(): void
    {
        $connection = static::getContainer()->get(Connection::class);

        $registrationData = $this->getRegistrationData();
        $registrationData['email'] = 'teg-reg@exämple.com';

        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $fetchMail = $connection->fetchOne(
            'SELECT email FROM customer WHERE id = UNHEX(:customerId)',
            ['customerId' => $response['id']]
        );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());

        static::assertSame('teg-reg@xn--exmple-cua.com', $fetchMail);
    }

    public function testRegisterWithTooLongPassword(): void
    {
        $registrationData = $this->getRegistrationData();
        $registrationData['password'] = \str_repeat('a', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1);
        $this->browser
            ->request(
                'POST',
                '/store-api/account/register',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($registrationData, \JSON_THROW_ON_ERROR)
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame(400, $this->browser->getResponse()->getStatusCode());
        static::assertArrayHasKey('errors', $response);

        $error = $response['errors'][0];

        static::assertSame('VIOLATION::TOO_LONG_ERROR', $error['code']);
        static::assertSame('/password', $error['source']['pointer']);
        static::assertSame(':PASSWORD_IS_TOO_LONG', $error['detail']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRegistrationData(string $storefrontUrl = 'http://localhost'): array
    {
        return [
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'password' => '12345678',
            'email' => 'teg-reg@example.com',
            'title' => 'Phd',
            'active' => true,
            'birthdayYear' => 2000,
            'birthdayMonth' => 1,
            'birthdayDay' => 22,
            'storefrontUrl' => $storefrontUrl,
            'billingAddress' => [
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'countryId' => $this->getValidCountryId($this->ids->get('sales-channel')),
                'street' => 'Examplestreet 11',
                'zipcode' => '48441',
                'city' => 'Cologne',
                'phoneNumber' => '0123456789',
                'additionalAddressLine1' => 'Additional address line 1',
                'additionalAddressLine2' => 'Additional address line 2',
            ],
            'shippingAddress' => [
                'salutationId' => $this->getValidSalutationId(),
                'countryId' => $this->getValidCountryId($this->ids->get('sales-channel')),
                'firstName' => 'Test 2',
                'lastName' => 'Example 2',
                'title' => 'Prof.',
                'street' => 'Examplestreet 111',
                'zipcode' => '12341',
                'city' => 'Berlin',
                'phoneNumber' => '987654321',
                'additionalAddressLine1' => 'Additional address line 01',
                'additionalAddressLine2' => 'Additional address line 02',
            ],
        ];
    }

    private function createBoundCustomer(string $salesChannelId, string $email, bool $boundSalesChannel = false): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();

        $customer = [
            'id' => $customerId,
            'number' => '1337',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'customerNumber' => '1337',
            'email' => $email,
            'password' => 'shopware',
            'groupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => $salesChannelId,
            'boundSalesChannelId' => $boundSalesChannel ? $salesChannelId : null,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => $this->getValidCountryId(),
                    'salutationId' => $this->getValidSalutationId(),
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                ],
            ],
        ];

        static::getContainer()
            ->get('customer.repository')
            ->upsert([$customer], Context::createDefaultContext());

        return $customerId;
    }

    private function createProductTestData(): void
    {
        $productRepository = static::getContainer()->get('product.repository');
        $productRepository->create([
            [
                'id' => $this->ids->create('p1'),
                'productNumber' => $this->ids->get('p1'),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['id' => $this->ids->create('manufacturerId'), 'name' => 'test'],
                'tax' => ['id' => $this->ids->create('tax'), 'taxRate' => 17, 'name' => 'with id'],
                'active' => true,
                'visibilities' => [
                    ['salesChannelId' => $this->ids->get('sales-channel'), 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL],
                ],
            ],
        ], Context::createDefaultContext());

        $productRepository->create([
            [
                'id' => $this->ids->create('p2'),
                'productNumber' => $this->ids->get('p2'),
                'stock' => 10,
                'name' => 'Test',
                'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 10, 'net' => 9, 'linked' => false]],
                'manufacturer' => ['id' => $this->ids->get('manufacturerId'), 'name' => 'test'],
                'tax' => ['id' => $this->ids->get('tax'), 'taxRate' => 17, 'name' => 'with id'],
                'active' => true,
                'visibilities' => [
                    ['salesChannelId' => $this->ids->get('sales-channel'), 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL],
                ],
            ],
        ], Context::createDefaultContext());
    }
}
