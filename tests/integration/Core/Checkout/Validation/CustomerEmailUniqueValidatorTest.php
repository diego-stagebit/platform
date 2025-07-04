<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Validation;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Validation\Constraint\CustomerEmailUnique;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\Test\TestDefaults;

/**
 * @internal
 */
#[Package('checkout')]
class CustomerEmailUniqueValidatorTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testSameCustomerEmailWithExistedBoundAccount(): void
    {
        $email = 'john.doe@example.com';

        $salesChannelContext1 = $this->createSalesChannelContext();
        $this->createCustomerOfSalesChannel($salesChannelContext1->getSalesChannelId(), $email);

        $salesChannelParameters = [
            'domains' => [
                [
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'currencyId' => Defaults::CURRENCY,
                    'snippetSetId' => $this->getSnippetSetIdForLocale('en-GB'),
                    'url' => 'http://localhost2',
                ],
            ],
        ];

        $salesChannelContext2 = $this->createSalesChannelContext($salesChannelParameters);

        $constraint = new CustomerEmailUnique([
            'salesChannelContext' => $salesChannelContext2,
        ]);

        $validation = new DataValidationDefinition('customer.email.update');
        $validation->add('email', $constraint);

        $validator = static::getContainer()->get(DataValidator::class);
        $validator->validate(['email' => $email], $validation);
    }

    public function testSameCustomerEmailOnSameSalesChannel(): void
    {
        $email = 'john.doe@example.com';

        $salesChannelContext1 = $this->createSalesChannelContext();
        $this->createCustomerOfSalesChannel($salesChannelContext1->getSalesChannelId(), $email);

        $constraint = new CustomerEmailUnique([
            'salesChannelContext' => $salesChannelContext1,
        ]);

        $validation = new DataValidationDefinition('customer.email.update');

        $validation->add('email', $constraint);

        $validator = static::getContainer()->get(DataValidator::class);

        try {
            $validator->validate([
                'email' => $email,
            ], $validation);

            static::fail('No exception is thrown');
        } catch (\Throwable $exception) {
            static::assertInstanceOf(ConstraintViolationException::class, $exception);
            $violations = $exception->getViolations();
            $violation = $violations->get(1);

            static::assertNotEmpty($violation);
            static::assertSame($constraint->message, $violation->getMessageTemplate());
        }
    }

    public function testSameCustomerEmailWithExistedNonBoundAccount(): void
    {
        $email = 'john.doe@example.com';

        $salesChannelContext1 = $this->createSalesChannelContext();
        $this->createCustomerOfSalesChannel($salesChannelContext1->getSalesChannelId(), $email);

        $constraint = new CustomerEmailUnique([
            'salesChannelContext' => $salesChannelContext1,
        ]);

        $validation = new DataValidationDefinition('customer.email.update');

        $validation->add('email', $constraint);

        $validator = static::getContainer()->get(DataValidator::class);

        try {
            $validator->validate([
                'email' => $email,
            ], $validation);

            static::fail('No exception is thrown');
        } catch (\Throwable $exception) {
            static::assertInstanceOf(ConstraintViolationException::class, $exception);
            $violations = $exception->getViolations();
            $violation = $violations->get(1);

            static::assertNotEmpty($violation);
            static::assertSame($constraint->message, $violation->getMessageTemplate());
        }
    }

    private function createCustomerOfSalesChannel(string $salesChannelId, string $email, bool $boundToSalesChannel = true): string
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
            'password' => TestDefaults::HASHED_PASSWORD,
            'boundSalesChannelId' => $boundToSalesChannel ? $salesChannelId : null,
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

        static::getContainer()
            ->get('customer.repository')
            ->upsert([$customer], Context::createDefaultContext());

        return $customerId;
    }
}
