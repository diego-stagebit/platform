<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Checkout\Cart\Address;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Address\AddressValidator;
use Shopware\Core\Checkout\Cart\Address\Error\BillingAddressCountryRegionMissingError;
use Shopware\Core\Checkout\Cart\Address\Error\BillingAddressSalutationMissingError;
use Shopware\Core\Checkout\Cart\Address\Error\ShippingAddressCountryRegionMissingError;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\State;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateCollection;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\Test\Generator;

/**
 * @internal
 */
#[CoversClass(AddressValidator::class)]
#[Package('checkout')]
class AddressValidatorTest extends TestCase
{
    private MockObject&EntityRepository $repository;

    private AddressValidator $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $this->validator = new AddressValidator($this->repository);
    }

    public function testValidateShippingAddressWithMixedItems(): void
    {
        $cart = new Cart('test');
        $cart->add((new LineItem('a', 'test'))->setStates([State::IS_DOWNLOAD]));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setActive(true);
        $country->setShippingAvailable(false);

        $context = Generator::generateSalesChannelContext(country: $country);

        $idSearchResult = new IdSearchResult(
            1,
            [['data' => $country->getId(), 'primaryKey' => $country->getId()]],
            new Criteria(),
            Context::createDefaultContext()
        );
        $this->repository->method('searchIds')->willReturn($idSearchResult);

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(0, $errorCollection);

        $cart->add((new LineItem('b', 'test'))->setStates([State::IS_PHYSICAL]));

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(1, $errorCollection);
    }

    public function testValidateShippingAddressWithOnlyPhysicalItems(): void
    {
        $cart = new Cart('test');
        $cart->add((new LineItem('b', 'test'))->setStates([State::IS_PHYSICAL]));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setActive(true);
        $country->setShippingAvailable(true);

        $context = Generator::generateSalesChannelContext(country: $country);

        $idSearchResult = new IdSearchResult(
            1,
            [['data' => $country->getId(), 'primaryKey' => $country->getId()]],
            new Criteria(),
            Context::createDefaultContext()
        );
        $this->repository->method('searchIds')->willReturn($idSearchResult);

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(0, $errorCollection);
    }

    public function testValidateShippingAddressWithOnlyDownloadItems(): void
    {
        $cart = new Cart('test');
        $cart->add((new LineItem('b', 'test'))->setStates([State::IS_DOWNLOAD]));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setActive(true);
        $country->setShippingAvailable(false);

        $context = Generator::generateSalesChannelContext(country: $country);

        $idSearchResult = new IdSearchResult(
            1,
            [['data' => $country->getId(), 'primaryKey' => $country->getId()]],
            new Criteria(),
            Context::createDefaultContext()
        );
        $this->repository->method('searchIds')->willReturn($idSearchResult);

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(0, $errorCollection);
    }

    public function testValidateShippingAddressWithoutSalutation(): void
    {
        $cart = new Cart('test');
        $cart->add((new LineItem('b', 'test'))->setStates([State::IS_PHYSICAL]));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setActive(true);
        $country->setShippingAvailable(true);
        $country->setForceStateInRegistration(true);

        $countryState = new CountryStateEntity();
        $countryState->setId(Uuid::randomHex());
        $countryState->setCountryId($country->getId());
        $countryState->setCountry($country);
        $countryState->setActive(true);

        $countryStates = new CountryStateCollection();
        $countryStates->add($countryState);
        $country->setStates($countryStates);

        $customerAddress = new CustomerAddressEntity();
        $customerAddress->setId(Uuid::randomHex());
        $customerAddress->setCountryId($country->getId());
        $customerAddress->setFirstName('John');
        $customerAddress->setLastName('Doe');
        $customerAddress->setCity('ExampleCity');

        $customer = new CustomerEntity();
        $customer->setFirstName('John');
        $customer->setLastName('Doe');
        $customer->setId(Uuid::randomHex());
        $customer->setActive(true);
        $customer->setActiveBillingAddress($customerAddress);
        $customer->setActiveShippingAddress($customerAddress);

        $context = Generator::generateSalesChannelContext(country: $country, countryState: $countryState, customer: $customer);

        $idSearchResult = new IdSearchResult(
            1,
            [['data' => $country->getId(), 'primaryKey' => $country->getId()]],
            new Criteria(),
            Context::createDefaultContext()
        );
        $this->repository->method('searchIds')->willReturn($idSearchResult);

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(1, $errorCollection);
        static::assertInstanceOf(BillingAddressSalutationMissingError::class, $errorCollection->first());
    }

    public function testValidateAddressWithoutState(): void
    {
        $cart = new Cart('test');
        $cart->add((new LineItem('b', 'test'))->setStates([State::IS_PHYSICAL]));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setActive(true);
        $country->setShippingAvailable(true);
        $country->setForceStateInRegistration(true);

        $countryState = new CountryStateEntity();
        $countryState->setId(Uuid::randomHex());
        $countryState->setCountryId($country->getId());
        $countryState->setCountry($country);
        $countryState->setActive(true);

        $countryStates = new CountryStateCollection();
        $countryStates->add($countryState);
        $country->setStates($countryStates);

        $customerAddress = new CustomerAddressEntity();
        $customerAddress->setId(Uuid::randomHex());
        $customerAddress->setCountryId($country->getId());
        $customerAddress->setFirstName('John');
        $customerAddress->setLastName('Doe');
        $customerAddress->setCity('ExampleCity');
        $customerAddress->setSalutationId(Uuid::randomHex());
        $customerAddress->setCountry($country);

        $customer = new CustomerEntity();
        $customer->setFirstName('John');
        $customer->setLastName('Doe');
        $customer->setId(Uuid::randomHex());
        $customer->setActive(true);
        $customer->setActiveBillingAddress($customerAddress);
        $customer->setActiveShippingAddress($customerAddress);

        $context = Generator::generateSalesChannelContext(country: $country, countryState: $countryState, customer: $customer);

        $idSearchResult = new IdSearchResult(
            1,
            [['data' => $country->getId(), 'primaryKey' => $country->getId()]],
            new Criteria(),
            Context::createDefaultContext()
        );
        $this->repository->method('searchIds')->willReturn($idSearchResult);

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(2, $errorCollection);
        static::assertInstanceOf(BillingAddressCountryRegionMissingError::class, $errorCollection->first());
        static::assertInstanceOf(ShippingAddressCountryRegionMissingError::class, $errorCollection->last());
    }

    public function testValidateAddressWithState(): void
    {
        $cart = new Cart('test');
        $cart->add((new LineItem('b', 'test'))->setStates([State::IS_PHYSICAL]));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());
        $country->setActive(true);
        $country->setShippingAvailable(true);
        $country->setForceStateInRegistration(true);

        $countryState = new CountryStateEntity();
        $countryState->setId(Uuid::randomHex());
        $countryState->setCountryId($country->getId());
        $countryState->setCountry($country);
        $countryState->setActive(true);

        $countryStates = new CountryStateCollection();
        $countryStates->add($countryState);
        $country->setStates($countryStates);

        $customerAddress = new CustomerAddressEntity();
        $customerAddress->setId(Uuid::randomHex());
        $customerAddress->setCountryId($country->getId());
        $customerAddress->setFirstName('John');
        $customerAddress->setLastName('Doe');
        $customerAddress->setCity('ExampleCity');
        $customerAddress->setSalutationId(Uuid::randomHex());
        $customerAddress->setCountry($country);
        $customerAddress->setCountryState($countryState);

        $customer = new CustomerEntity();
        $customer->setFirstName('John');
        $customer->setLastName('Doe');
        $customer->setId(Uuid::randomHex());
        $customer->setActive(true);
        $customer->setActiveBillingAddress($customerAddress);
        $customer->setActiveShippingAddress($customerAddress);

        $context = Generator::generateSalesChannelContext(country: $country, countryState: $countryState, customer: $customer);

        $idSearchResult = new IdSearchResult(
            1,
            [['data' => $country->getId(), 'primaryKey' => $country->getId()]],
            new Criteria(),
            Context::createDefaultContext()
        );
        $this->repository->method('searchIds')->willReturn($idSearchResult);

        $errorCollection = new ErrorCollection();
        $this->validator->validate($cart, $errorCollection, $context);

        static::assertCount(0, $errorCollection);
    }
}
