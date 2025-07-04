<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Gateway\Context\Command;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
abstract class AbstractContextGatewayCommand
{
    abstract public static function getDefaultKeyName(): string;

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function createFromPayload(array $payload = []): static
    {
        /** @phpstan-ignore new.static (the usage of "new static" is explicitly wanted and safe here) */
        return new static(...$payload);
    }
}
