<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\CustomerException;
use Shopware\Core\Checkout\Customer\Event\CustomerBeforeLoginEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Checkout\Customer\Exception\AddressNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\BadCredentialsException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundByIdException;
use Shopware\Core\Checkout\Customer\Exception\CustomerNotFoundException;
use Shopware\Core\Checkout\Customer\Exception\CustomerOptinNotCompletedException;
use Shopware\Core\Checkout\Customer\Password\LegacyPasswordVerifier;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\System\SalesChannel\Context\CartRestorer;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\Validator\ConstraintViolation;

#[Package('checkout')]
class AccountService
{
    use CheckPasswordLengthTrait;

    /**
     * @internal
     *
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LegacyPasswordVerifier $legacyPasswordVerifier,
        private readonly AbstractSwitchDefaultAddressRoute $switchDefaultAddressRoute,
        private readonly CartRestorer $restorer
    ) {
    }

    /**
     * @throws CartException
     * @throws InvalidUuidException
     * @throws AddressNotFoundException
     */
    public function setDefaultBillingAddress(string $addressId, SalesChannelContext $context, CustomerEntity $customer): void
    {
        $this->switchDefaultAddressRoute->swap($addressId, AbstractSwitchDefaultAddressRoute::TYPE_BILLING, $context, $customer);
    }

    /**
     * @throws CartException
     * @throws InvalidUuidException
     * @throws AddressNotFoundException
     */
    public function setDefaultShippingAddress(string $addressId, SalesChannelContext $context, CustomerEntity $customer): void
    {
        $this->switchDefaultAddressRoute->swap($addressId, AbstractSwitchDefaultAddressRoute::TYPE_SHIPPING, $context, $customer);
    }

    /**
     * @throws BadCredentialsException
     * @throws CustomerNotFoundByIdException
     */
    public function loginById(string $id, SalesChannelContext $context): string
    {
        if (!Uuid::isValid($id)) {
            throw CustomerException::badCredentials();
        }

        $customer = $this->fetchCustomer(new Criteria([$id]), $context, true);
        if ($customer === null) {
            throw CustomerException::customerNotFoundByIdException($id);
        }

        $event = new CustomerBeforeLoginEvent($context, $customer->getEmail());
        $this->eventDispatcher->dispatch($event);

        return $this->loginByCustomer($customer, $context);
    }

    /**
     * @throws CustomerNotFoundException
     * @throws BadCredentialsException
     * @throws CustomerOptinNotCompletedException
     */
    public function loginByCredentials(string $email, string $password, SalesChannelContext $context): string
    {
        if ($email === '' || $password === '') {
            throw CustomerException::badCredentials();
        }

        $event = new CustomerBeforeLoginEvent($context, $email);
        $this->eventDispatcher->dispatch($event);

        $customer = $this->getCustomerByLogin($email, $password, $context);

        return $this->loginByCustomer($customer, $context);
    }

    /**
     * @throws CustomerNotFoundException
     * @throws BadCredentialsException
     * @throws CustomerOptinNotCompletedException
     */
    public function getCustomerByLogin(string $email, string $password, SalesChannelContext $context): CustomerEntity
    {
        if ($this->isPasswordTooLong($password)) {
            throw CustomerException::badCredentials();
        }

        $customer = $this->getCustomerByEmail($email, $context);

        if ($customer->hasLegacyPassword()) {
            if (!$this->legacyPasswordVerifier->verify($password, $customer)) {
                throw CustomerException::badCredentials();
            }

            $this->updatePasswordHash($password, $customer, $context->getContext());

            return $customer;
        }

        if ($customer->getPassword() === null
            || !password_verify($password, $customer->getPassword())) {
            throw CustomerException::badCredentials();
        }

        if (!$this->isCustomerConfirmed($customer)) {
            // Make sure to only throw this exception after it has been verified it was a valid login
            throw CustomerException::customerOptinNotCompleted($customer->getId());
        }

        return $customer;
    }

    /**
     * @throws CustomerNotFoundException
     */
    public function getCustomerByEmail(string $email, SalesChannelContext $context): CustomerEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('email', $email));

        $customer = $this->fetchCustomer($criteria, $context);
        if ($customer === null) {
            throw CustomerException::customerNotFound($email);
        }

        return $customer;
    }

    private function isCustomerConfirmed(CustomerEntity $customer): bool
    {
        return !$customer->getDoubleOptInRegistration() || $customer->getDoubleOptInConfirmDate();
    }

    private function loginByCustomer(CustomerEntity $customer, SalesChannelContext $context): string
    {
        $this->customerRepository->update([
            [
                'id' => $customer->getId(),
                'lastLogin' => new \DateTimeImmutable(),
            ],
        ], $context->getContext());

        $context = $this->restorer->restore($customer->getId(), $context);
        $newToken = $context->getToken();

        $event = new CustomerLoginEvent($context, $customer, $newToken);
        $this->eventDispatcher->dispatch($event);

        return $newToken;
    }

    /**
     * This method filters for the standard customer related constraints like active or the sales channel
     * assignment.
     * Add only filters to the $criteria for values which have an index in the database, e.g. id, or email. The rest
     * should be done via PHP because it's a lot faster to filter a few entities on PHP side with the same email
     * address, than to filter a huge numbers of rows in the DB on a not indexed column.
     */
    private function fetchCustomer(Criteria $criteria, SalesChannelContext $context, bool $includeGuest = false): ?CustomerEntity
    {
        $criteria->setTitle('account-service::fetchCustomer');

        $result = $this->customerRepository->search($criteria, $context->getContext())->getEntities();
        $result = $result->filter(function (CustomerEntity $customer) use ($includeGuest, $context): ?bool {
            // Skip not active users
            if (!$customer->getActive()) {
                return null;
            }

            // Skip guest if not required
            if (!$includeGuest && $customer->getGuest()) {
                return null;
            }

            // If not bound, we still need to consider it
            if ($customer->getBoundSalesChannelId() === null) {
                return true;
            }

            // It is bound, but not to the current one. Skip it
            if ($customer->getBoundSalesChannelId() !== $context->getSalesChannelId()) {
                return null;
            }

            return true;
        });

        // If there is more than one account we want to return the latest, this is important
        // for guest accounts, real customer accounts should only occur once, otherwise the
        // wrong password will be validated
        if ($result->count() > 1) {
            $result->sort(fn (CustomerEntity $a, CustomerEntity $b) => ($a->getCreatedAt() <=> $b->getCreatedAt()) * -1);
        }

        return $result->first();
    }

    private function updatePasswordHash(string $password, CustomerEntity $customer, Context $context): void
    {
        try {
            $this->customerRepository->update([
                [
                    'id' => $customer->getId(),
                    'password' => $password,
                    'legacyPassword' => null,
                    'legacyEncoder' => null,
                ],
            ], $context);
        } catch (WriteException $writeException) {
            $this->handleWriteExceptionForUpdatingPasswordHash($writeException);
        }
    }

    private function handleWriteExceptionForUpdatingPasswordHash(WriteException $writeException): void
    {
        foreach ($writeException->getExceptions() as $exception) {
            if (!$exception instanceof WriteConstraintViolationException) {
                continue;
            }

            /** @var ConstraintViolation $constraintViolation */
            foreach ($exception->getViolations() as $constraintViolation) {
                if ($constraintViolation->getPropertyPath() === '/password') {
                    throw CustomerException::passwordPoliciesUpdated();
                }
            }
        }

        throw $writeException;
    }
}
