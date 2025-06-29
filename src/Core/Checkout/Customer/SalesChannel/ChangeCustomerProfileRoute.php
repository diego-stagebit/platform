<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\SalesChannel;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Validation\Constraint\CustomerVatIdentification;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Event\DataMappingEvent;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidationFactoryInterface;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\StoreApiCustomFieldMapper;
use Shopware\Core\System\SalesChannel\SuccessResponse;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\System\Salutation\SalutationDefinition;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route(defaults: ['_routeScope' => ['store-api'], '_contextTokenRequired' => true])]
#[Package('checkout')]
class ChangeCustomerProfileRoute extends AbstractChangeCustomerProfileRoute
{
    /**
     * @internal
     *
     * @param EntityRepository<CustomerCollection> $customerRepository
     * @param EntityRepository<SalutationCollection> $salutationRepository
     */
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DataValidator $validator,
        private readonly DataValidationFactoryInterface $customerProfileValidationFactory,
        private readonly StoreApiCustomFieldMapper $storeApiCustomFieldMapper,
        private readonly EntityRepository $salutationRepository,
    ) {
    }

    public function getDecorated(): AbstractChangeCustomerProfileRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/account/change-profile',
        name: 'store-api.account.change-profile',
        defaults: ['_loginRequired' => true, '_loginRequiredAllowGuest' => true],
        methods: ['POST']
    )]
    public function change(RequestDataBag $data, SalesChannelContext $context, CustomerEntity $customer): SuccessResponse
    {
        $validation = $this->customerProfileValidationFactory->update($context);

        if ($data->has('accountType') && empty($data->get('accountType'))) {
            $data->remove('accountType');
        }

        if ($data->get('accountType') === CustomerEntity::ACCOUNT_TYPE_BUSINESS) {
            $validation->add('company', new NotBlank());
            $billingAddress = $customer->getDefaultBillingAddress();
            if ($billingAddress) {
                $this->addVatIdsValidation($validation, $billingAddress);
            }
        } else {
            $data->set('company', '');
            $data->set('vatIds', null);
        }

        $vatIds = $data->get('vatIds');
        if ($vatIds instanceof RequestDataBag) {
            $vatIds = \array_filter($vatIds->all());
            $data->set('vatIds', empty($vatIds) ? null : $vatIds);
        }

        if (!$data->get('salutationId')) {
            $data->set('salutationId', $this->getDefaultSalutationId($context));
        }

        $this->dispatchValidationEvent($validation, $data, $context->getContext());

        $this->validator->validate($data->all(), $validation);

        $customerData = $data->only('firstName', 'lastName', 'salutationId', 'title', 'company', 'accountType');

        if ($vatIds) {
            $vatIds = $data->get('vatIds');

            if ($vatIds instanceof DataBag) {
                $vatIds = $vatIds->all();
            }

            $customerData['vatIds'] = $vatIds;
        }

        if ($birthday = $this->getBirthday($data)) {
            $customerData['birthday'] = $birthday;
        }

        if ($data->get('customFields') instanceof RequestDataBag) {
            $customerData['customFields'] = $this->storeApiCustomFieldMapper->map(
                CustomerDefinition::ENTITY_NAME,
                $data->get('customFields')
            );
            if ($customerData['customFields'] === []) {
                unset($customerData['customFields']);
            }
        }

        $mappingEvent = new DataMappingEvent($data, $customerData, $context->getContext());
        $this->eventDispatcher->dispatch($mappingEvent, CustomerEvents::MAPPING_CUSTOMER_PROFILE_SAVE);

        $customerData = $mappingEvent->getOutput();

        $customerData['id'] = $customer->getId();

        $this->customerRepository->update([$customerData], $context->getContext());

        return new SuccessResponse();
    }

    private function dispatchValidationEvent(DataValidationDefinition $definition, DataBag $data, Context $context): void
    {
        $validationEvent = new BuildValidationEvent($definition, $data, $context);
        $this->eventDispatcher->dispatch($validationEvent, $validationEvent->getName());
    }

    private function addVatIdsValidation(DataValidationDefinition $validation, CustomerAddressEntity $address): void
    {
        $constraints = [
            new Type('array'),
            new CustomerVatIdentification(
                ['countryId' => $address->getCountryId()]
            ),
        ];
        if ($address->getCountry() && $address->getCountry()->getVatIdRequired()) {
            $constraints[] = new NotBlank();
        }

        $validation->add('vatIds', ...$constraints);
    }

    private function getBirthday(DataBag $data): ?\DateTimeInterface
    {
        $birthdayDay = $data->get('birthdayDay');
        $birthdayMonth = $data->get('birthdayMonth');
        $birthdayYear = $data->get('birthdayYear');

        if (!\is_numeric($birthdayDay) || !\is_numeric($birthdayMonth) || !\is_numeric($birthdayYear)) {
            return null;
        }

        return new \DateTime(\sprintf(
            '%s-%s-%s',
            $birthdayYear,
            $birthdayMonth,
            $birthdayDay
        ));
    }

    private function getDefaultSalutationId(SalesChannelContext $context): ?string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('salutationKey', SalutationDefinition::NOT_SPECIFIED));

        return $this->salutationRepository->searchIds($criteria, $context->getContext())->firstId();
    }
}
