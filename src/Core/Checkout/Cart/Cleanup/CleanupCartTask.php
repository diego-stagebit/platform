<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Cleanup;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

#[Package('checkout')]
class CleanupCartTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'cart.cleanup';
    }

    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
