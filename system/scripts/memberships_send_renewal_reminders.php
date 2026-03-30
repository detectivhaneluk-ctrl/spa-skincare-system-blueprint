<?php

declare(strict_types=1);

/**
 * Dispatch membership renewal reminders: outbound email queue attempt first, then optional in-app staff notice.
 * Queue rows are not proof of customer delivery (see outbound transport semantics).
 *
 * Production: prefer {@see memberships_cron.php} (ordered pass + flock). Use this script for reminders-only runs.
 * After reminders, run {@see outbound_notifications_dispatch.php} to process the queue.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$stats = app(\Modules\Memberships\Services\MembershipService::class)->dispatchRenewalReminders();

echo 'scanned=' . (int) ($stats['scanned'] ?? 0) . PHP_EOL;
echo 'eligible=' . (int) ($stats['eligible'] ?? 0) . PHP_EOL;
echo 'created=' . (int) ($stats['created'] ?? 0) . PHP_EOL;
echo 'skipped-duplicate=' . (int) ($stats['skipped_duplicate'] ?? 0) . PHP_EOL;
echo 'outreach-pending-enqueued=' . (int) ($stats['outreach_pending_enqueued'] ?? 0) . PHP_EOL;
echo 'outreach-duplicate=' . (int) ($stats['outreach_duplicate_ignored'] ?? 0) . PHP_EOL;
echo 'outreach-skipped=' . (int) ($stats['outreach_skipped'] ?? 0) . PHP_EOL;
echo 'outreach-failed=' . (int) ($stats['outreach_failed'] ?? 0) . PHP_EOL;
echo 'skipped-disabled-or-zero-reminder=' . (int) ($stats['skipped_disabled_or_zero_reminder'] ?? 0) . PHP_EOL;
echo 'skipped-notifications-disabled=' . (int) ($stats['skipped_notifications_disabled'] ?? 0) . PHP_EOL;
