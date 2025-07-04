<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Log\ScheduledTask;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

#[Package('framework')]
class LogCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'log_entry.cleanup';
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
