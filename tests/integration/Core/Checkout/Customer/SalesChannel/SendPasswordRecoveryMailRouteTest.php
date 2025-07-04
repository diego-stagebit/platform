<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Customer\SalesChannel;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\Event\CustomerAccountRecoverRequestEvent;
use Shopware\Core\Checkout\Customer\Event\PasswordRecoveryUrlEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('checkout')]
#[Group('store-api')]
class SendPasswordRecoveryMailRouteTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    private KernelBrowser $browser;

    private IdsCollection $ids;

    /**
     * @var EntityRepository<CustomerCollection>
     */
    private EntityRepository $customerRepository;

    protected function setUp(): void
    {
        $this->ids = new IdsCollection();

        $this->browser = $this->createCustomSalesChannelBrowser([
            'id' => $this->ids->create('sales-channel'),
        ]);
        $this->assignSalesChannelContext($this->browser);
        $this->customerRepository = static::getContainer()->get('customer.repository');
    }

    public function testResetUnknownEmail(): void
    {
        $this->browser
            ->request(
                'POST',
                '/store-api/account/recovery-password',
                [
                    'email' => 'lol@lol.de',
                    'storefrontUrl' => 'http://localhost',
                ]
            );

        /** @var array<string, mixed> $response */
        $response = \json_decode($this->browser->getResponse()->getContent() ?: '', true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('apiAlias', $response);
        static::assertArrayHasKey('success', $response);
        static::assertTrue($response['success']);
    }

    public function testResetWithInvalidUrl(): void
    {
        $this->browser
            ->request(
                'POST',
                '/store-api/account/recovery-password',
                [
                    'email' => 'lol@lol.de',
                    'storefrontUrl' => 'http://aaaa.de',
                ]
            );

        $response = json_decode($this->browser->getResponse()->getContent() ?: '', true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('errors', $response);
        static::assertSame('VIOLATION::NO_SUCH_CHOICE_ERROR', $response['errors'][0]['code']);
    }

    public function testResetWithTryingToDisableValidation(): void
    {
        $this->createCustomer('foo-test@test.de');

        $this->browser
            ->request(
                'POST',
                '/store-api/account/recovery-password?validateStorefrontUrl=false',
                [
                    'email' => 'foo-test@test.de',
                    'storefrontUrl' => 'http://my-evil-page',
                    'validateStorefrontUrl' => false,
                ]
            );

        static::assertSame(400, $this->browser->getResponse()->getStatusCode());

        $response = json_decode($this->browser->getResponse()->getContent() ?: '', true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('VIOLATION::NO_SUCH_CHOICE_ERROR', $response['errors'][0]['code']);
    }

    public function testResetWithDisabledAccount(): void
    {
        $email = 'test-disabled@test.de';

        $this->createCustomer($email, false);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/recovery-password?validateStorefrontUrl=false',
                [
                    'email' => $email,
                    'storefrontUrl' => 'http://localhost',
                    'validateStorefrontUrl' => false,
                ]
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());

        /** @var array<string, mixed> $response */
        $response = \json_decode($this->browser->getResponse()->getContent() ?: '', true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('apiAlias', $response);
        static::assertArrayHasKey('success', $response);
        static::assertTrue($response['success']);
    }

    /**
     * @param array{domain: string, expectDomain: string} $domainUrlTest
     */
    #[DataProvider('sendMailWithDomainAndLeadingSlashProvider')]
    public function testSendMailWithDomainAndLeadingSlash(array $domainUrlTest): void
    {
        $this->createCustomer('foo-test@test.de');

        $this->addDomain($domainUrlTest['domain']);

        $caughtEvent = null;
        $this->addEventListener(
            static::getContainer()->get('event_dispatcher'),
            CustomerAccountRecoverRequestEvent::EVENT_NAME,
            static function (CustomerAccountRecoverRequestEvent $event) use (&$caughtEvent): void {
                $caughtEvent = $event;
            }
        );

        $this->browser
            ->request(
                'POST',
                '/store-api/account/recovery-password',
                [
                    'email' => 'foo-test@test.de',
                    'storefrontUrl' => $domainUrlTest['expectDomain'],
                ]
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode());

        /** @var CustomerAccountRecoverRequestEvent $caughtEvent */
        static::assertInstanceOf(CustomerAccountRecoverRequestEvent::class, $caughtEvent);
        static::assertStringStartsWith('http://my-evil-page/account/', $caughtEvent->getResetUrl());
    }

    public function testSendMailWithChangedUrl(): void
    {
        $this->createCustomer('foo-test@test.de');

        $systemConfigService = static::getContainer()->get(SystemConfigService::class);
        $systemConfigService->set('core.loginRegistration.pwdRecoverUrl', '/test/rec/password/%%RECOVERHASH%%"');

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');

        $caughtEvent = null;
        $this->addEventListener(
            $dispatcher,
            CustomerAccountRecoverRequestEvent::EVENT_NAME,
            static function (CustomerAccountRecoverRequestEvent $event) use (&$caughtEvent): void {
                $caughtEvent = $event;
            }
        );

        $this->addEventListener(
            $dispatcher,
            PasswordRecoveryUrlEvent::class,
            static function (PasswordRecoveryUrlEvent $event): void {
                $event->setRecoveryUrl($event->getRecoveryUrl() . '/?somethingSpecial=1');
            }
        );

        $this->browser
            ->request(
                'POST',
                '/store-api/account/recovery-password',
                [
                    'email' => 'foo-test@test.de',
                    'storefrontUrl' => 'http://localhost',
                ]
            );

        static::assertSame(200, $this->browser->getResponse()->getStatusCode(), $this->browser->getResponse()->getContent() ?: '');

        /** @var CustomerAccountRecoverRequestEvent $caughtEvent */
        static::assertInstanceOf(CustomerAccountRecoverRequestEvent::class, $caughtEvent);
        static::assertStringStartsWith('http://localhost/test/rec/password/', $caughtEvent->getResetUrl());
        static::assertStringEndsWith('/?somethingSpecial=1', $caughtEvent->getResetUrl());
    }

    /**
     * @return array<array{0: array{domain: string, expectDomain: string}}>
     */
    public static function sendMailWithDomainAndLeadingSlashProvider(): array
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

    private function addDomain(string $url): void
    {
        $snippetSetId = static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT LOWER(HEX(id)) FROM snippet_set LIMIT 1');

        $domain = [
            'salesChannelId' => $this->ids->create('sales-channel'),
            'languageId' => Defaults::LANGUAGE_SYSTEM,
            'url' => $url,
            'currencyId' => Defaults::CURRENCY,
            'snippetSetId' => $snippetSetId,
        ];

        static::getContainer()->get('sales_channel_domain.repository')
            ->create([$domain], Context::createDefaultContext());
    }

    private function createCustomer(?string $email = null, bool $active = true): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();

        $customer = [
            'id' => $customerId,
            'active' => $active,
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'defaultShippingAddress' => [
                'id' => $addressId,
                'firstName' => 'Max',
                'lastName' => 'Mustermann',
                'street' => 'Musterstraße 1',
                'city' => 'Schoöppingen',
                'zipcode' => '12345',
                'salutationId' => $this->getValidSalutationId(),
                'countryId' => $this->getValidCountryId(),
            ],
            'defaultBillingAddressId' => $addressId,
            'groupId' => TestDefaults::FALLBACK_CUSTOMER_GROUP,
            'email' => $email,
            'password' => TestDefaults::HASHED_PASSWORD,
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'salutationId' => $this->getValidSalutationId(),
            'customerNumber' => '12345',
        ];

        $this->customerRepository->create([$customer], Context::createDefaultContext());

        return $customerId;
    }
}
