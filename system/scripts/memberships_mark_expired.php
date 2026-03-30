<?php

declare(strict_types=1);

/**
 * Mark client memberships expired after ends_at + branch-effective memberships.grace_period_days.
 * Safe for scheduler/cron; idempotent per row.
 *
 * Production: prefer {@see memberships_cron.php} (ordered pass + flock). Use this script for expiry-only runs.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$n = app(\Modules\Memberships\Services\MembershipService::class)->markExpired(null);
echo 'marked-expired=' . (int) $n . PHP_EOL;
