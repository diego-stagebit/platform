<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Checkout\Validation;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Validation\Constraint\CustomerVatIdentification;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;

/**
 * @internal
 */
#[Package('checkout')]
class CustomerVatIdentificationValidatorTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testValidateVatIds(): void
    {
        $vatIds = [
            '123546',
        ];

        $constraint = new CustomerVatIdentification([
            'countryId' => $this->getValidCountryId(),
        ]);

        $validation = new DataValidationDefinition('customer.create');

        $validation
            ->add('vatIds', $constraint);

        $validator = static::getContainer()->get(DataValidator::class);

        try {
            $validator->validate(['vatIds' => $vatIds], $validation);
        } catch (\Throwable $exception) {
            static::assertInstanceOf(ConstraintViolationException::class, $exception);
            $violations = $exception->getViolations();
            $violation = $violations->get(1);

            static::assertNotEmpty($violation);
            static::assertSame($constraint->message, $violation->getMessageTemplate());
        }
    }
}
