<?php

declare(strict_types=1);

/**
 * Enqueue marketing run recipients onto outbound_notification_messages (worker/CLI).
 * Same effect as POST /marketing/campaigns/runs/{id}/dispatch from the UI.
 *
 * Usage:
 *   php system/scripts/marketing_campaign_enqueue_run.php <run_id>
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$runId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($runId <= 0) {
    fwrite(STDERR, "Usage: php system/scripts/marketing_campaign_enqueue_run.php <run_id>\n");
    exit(1);
}

try {
    \Core\App\Application::container()->get(\Modules\Marketing\Services\MarketingCampaignService::class)->dispatchFrozenRun($runId);
    fwrite(STDOUT, "marketing-run-dispatch ok run_id={$runId}\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'marketing-run-dispatch failed: ' . $e->getMessage() . "\n");
    exit(1);
}
