<?php

declare(strict_types=1);

namespace Core\Runtime\Jobs;

final class RuntimeExecutionKeys
{
    public const PHP_OUTBOUND_NOTIFICATIONS_DISPATCH = 'php:outbound_notifications_dispatch';

    public const PHP_MEMBERSHIPS_CRON = 'php:memberships_cron';

    public const WORKER_IMAGE_PIPELINE = 'worker:image_pipeline';

    /** Prefix only; full key is {@see marketingAutomation()}. */
    public const PHP_MARKETING_AUTOMATIONS_PREFIX = 'php:marketing_automations:';

    public static function marketingAutomation(string $automationKey): string
    {
        $k = trim($automationKey);

        return self::PHP_MARKETING_AUTOMATIONS_PREFIX . $k;
    }
}
