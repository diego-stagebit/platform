<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Customer\SalesChannel;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\Integration\Traits\CustomerTestTrait;
use Shopware\Core\Test\Stub\Framework\IdsCollection;
use Shopware\Core\Test\TestDefaults;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @internal
 */
#[Package('checkout')]
class ChangeEmailRouteTest extends TestCase
{
    use CustomerTestTrait;
    use IntegrationTestBehaviour;

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

        $email = Uuid::randomHex() . '@example.com';
        $this->createCustomer('shopware', $email);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/login',
                [
                    'email' => $email,
                    'password' => 'shopware',
                ]
            );

        $response = $this->browser->getResponse();

        // After login successfully, the context token will be set in the header
        $contextToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN) ?? '';
        static::assertNotEmpty($contextToken);

        $this->browser->setServerParameter('HTTP_SW_CONTEXT_TOKEN', $contextToken);
    }

    public function testEmptyRequest(): void
    {
        $this->browser
            ->request(
                'POST',
                '/store-api/account/change-email',
                [
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('errors', $response);
        static::assertSame('VIOLATION::CUSTOMER_PASSWORD_NOT_CORRECT', $response['errors'][0]['code']);
    }

    public function testChangeInvalidPassword(): void
    {
        $this->browser
            ->request(
                'POST',
                '/store-api/account/change-email',
                [
                    'password' => 'foooware',
                    'email' => 'test@fooware.de',
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('errors', $response);
        static::assertSame('VIOLATION::CUSTOMER_PASSWORD_NOT_CORRECT', $response['errors'][0]['code']);
    }

    public function testChange(): void
    {
        $this->browser
            ->request(
                'POST',
                '/store-api/account/change-email',
                [
                    'password' => 'shopware',
                    'email' => 'test@fooware.de',
                    'emailConfirmation' => 'test@fooware.de',
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayNotHasKey('errors', $response);
        static::assertTrue($response['success']);

        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer',
                [
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame('test@fooware.de', $response['email']);
    }

    public function testChangeSuccessWithSameEmailOnDiffSalesChannel(): void
    {
        static::getContainer()->get(SystemConfigService::class)->set('core.systemWideLoginRegistration.isCustomerBoundToSalesChannel', true);

        $newEmail = 'test@fooware.de';

        $salesChannelContext = $this->createSalesChannelContext([
            'domains' => [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'currencyId' => Defaults::CURRENCY,
                    'snippetSetId' => $this->getSnippetSetIdForLocale('en-GB'),
                    'url' => 'http://localhost2',
                ],
            ],
        ]);

        $this->createCustomerOfSalesChannel($salesChannelContext->getSalesChannelId(), $newEmail);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/change-email',
                [
                    'password' => 'shopware',
                    'email' => $newEmail,
                    'emailConfirmation' => $newEmail,
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayNotHasKey('errors', $response);
        static::assertTrue($response['success']);

        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer',
                [
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame($newEmail, $response['email']);
    }

    public function testChangeFailWithSameEmailOnSameSalesChannel(): void
    {
        $newEmail = 'test@fooware.de';

        $this->createCustomerOfSalesChannel($this->ids->get('sales-channel'), $newEmail);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/change-email',
                [
                    'password' => 'shopware',
                    'email' => $newEmail,
                    'emailConfirmation' => $newEmail,
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertArrayHasKey('errors', $response);
        static::assertSame(400, $this->browser->getResponse()->getStatusCode());
        static::assertNotEmpty($response['errors']);
        static::assertSame('VIOLATION::CUSTOMER_EMAIL_NOT_UNIQUE', $response['errors'][0]['code']);

        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer',
                [
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertNotSame($newEmail, $response['email']);
    }

    public function testChangeSuccessWithNewsletterRecipient(): void
    {
        $this->browser
            ->request(
                'GET',
                '/store-api/account/customer',
                [
                ]
            );

        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $this->browser
            ->request(
                'POST',
                '/store-api/newsletter/subscribe',
                [
                    'email' => $response['email'],
                    'option' => 'direct',
                    'storefrontUrl' => 'http://localhost',
                ]
            );

        $count = (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM newsletter_recipient WHERE status = "direct" AND email = ?', [$response['email']]);
        static::assertSame(1, $count);

        $this->browser
            ->request(
                'POST',
                '/store-api/account/change-email',
                [
                    'password' => 'shopware',
                    'email' => 'test@fooware.de',
                    'emailConfirmation' => 'test@fooware.de',
                ]
            );

        $count = (int) static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT COUNT(*) FROM newsletter_recipient WHERE status = "direct" AND email = ?', [$response['email']]);
        static::assertSame(0, $count);

        $email = static::getContainer()->get(Connection::class)
            ->fetchOne('SELECT email FROM newsletter_recipient WHERE status = "direct" AND email = "test@fooware.de"');
        static::assertSame($email, 'test@fooware.de');
    }

    private function createCustomer(string $password, ?string $email = null): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();

        $customer = [
            'id' => $customerId,
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
            'password' => $password,
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'salutationId' => $this->getValidSalutationId(),
            'customerNumber' => '12345',
        ];

        $this->customerRepository->create([$customer], Context::createDefaultContext());

        return $customerId;
    }

    private function createCustomerOfSalesChannel(string $salesChannelId, string $email): string
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

        $isCustomerBound = static::getContainer()->get(SystemConfigService::class)->get('core.systemWideLoginRegistration.isCustomerBoundToSalesChannel');
        $customer['boundSalesChannelId'] = $isCustomerBound ? $salesChannelId : null;

        static::getContainer()
            ->get('customer.repository')
            ->upsert([$customer], Context::createDefaultContext());

        return $customerId;
    }
}
