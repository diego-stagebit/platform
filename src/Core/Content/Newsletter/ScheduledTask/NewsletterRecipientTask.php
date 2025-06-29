<?php declare(strict_types=1);

namespace Shopware\Core\Content\Newsletter\ScheduledTask;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

#[Package('after-sales')]
class NewsletterRecipientTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'delete_newsletter_recipient_task';
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
