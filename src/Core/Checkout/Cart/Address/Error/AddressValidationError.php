<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Address\Error;

use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Validator\ConstraintViolationList;

#[Package('checkout')]
class AddressValidationError extends Error
{
    private const KEY = 'address-invalid';

    public function __construct(
        protected readonly bool $isBillingAddress,
        protected readonly ConstraintViolationList $violations
    ) {
        $this->message = \sprintf(
            'Please check your %s address for missing or invalid values.',
            $isBillingAddress ? 'billing' : 'shipping'
        );

        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return $this->getMessageKey();
    }

    public function getMessageKey(): string
    {
        return \sprintf('%s-%s', $this->isBillingAddress ? 'billing' : 'shipping', self::KEY);
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return ['isBillingAddress' => $this->isBillingAddress, 'violations' => $this->violations];
    }

    public function isBillingAddress(): bool
    {
        return $this->isBillingAddress;
    }

    public function getViolations(): ConstraintViolationList
    {
        return $this->violations;
    }
}
