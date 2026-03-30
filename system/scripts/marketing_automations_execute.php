<?php

declare(strict_types=1);

/**
 * Manual / scheduled execution path for marketing automated-email foundation.
 * The web app does not invoke this file; configure cron or another scheduler separately.
 *
 * FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01: flock per automation key on this host + DB exclusive run registry
 * (stale active slot cleared with an honest `last_error_summary` note).
 *
 * Usage:
 *   php system/scripts/marketing_automations_execute.php --key=reengagement_45_day [--branch=11] [--dry-run=1]
 *   php system/scripts/marketing_automations_execute.php --key=birthday_special --dry-run=1
 *   php system/scripts/marketing_automations_execute.php --key=first_time_visitor_welcome
 *
 * Exit: 0 success, 11 lock held / exclusive conflict, 1 fatal.
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

use Core\Runtime\Jobs\RuntimeExecutionConflictException;
use Core\Runtime\Jobs\RuntimeExecutionKeys;
use Core\Runtime\Jobs\RuntimeExecutionRegistry;

$opts = getopt('', ['key:', 'branch::', 'dry-run::']);
$key = isset($opts['key']) ? trim((string) $opts['key']) : '';
if ($key === '') {
    fwrite(STDERR, "Missing required --key option.\n");
    exit(1);
}
$dryRun = isset($opts['dry-run']) && (string) $opts['dry-run'] !== '0';
$branchOpt = isset($opts['branch']) ? (int) $opts['branch'] : 0;

$lockDir = $base . '/storage/locks';
$san = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key);
$san = trim($san, '_');
if ($san === '') {
    $san = 'h' . substr(sha1($key), 0, 16);
}
$lockPath = $lockDir . '/marketing_automations_' . substr($san, 0, 80) . '.lock';

if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "marketing-automations: cannot create lock directory: {$lockDir}\n");
    exit(1);
}

$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false) {
    fwrite(STDERR, "marketing-automations: cannot open lock file: {$lockPath}\n");
    exit(1);
}

if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fclose($lockFh);
    fwrite(STDOUT, "marketing-automations: lock-held for key={$key}, exiting.\n");
    exit(11);
}

$pdo = app(\Core\App\Database::class)->connection();
if ($branchOpt > 0) {
    $st = $pdo->prepare(
        'SELECT id, organization_id FROM branches WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $st->execute([$branchOpt]);
    $branch = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
} else {
    $branch = $pdo->query(
        'SELECT id, organization_id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1'
    )->fetch(\PDO::FETCH_ASSOC) ?: null;
}
if ($branch === null) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    fwrite(STDERR, "No active branch available for execution.\n");
    exit(1);
}

$branchId = (int) ($branch['id'] ?? 0);
$orgId = (int) ($branch['organization_id'] ?? 0);
app(\Core\Branch\BranchContext::class)->setCurrentBranchId($branchId);
app(\Core\Organization\OrganizationContext::class)->setFromResolution(
    $orgId,
    \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED
);

/** @var RuntimeExecutionRegistry $registry */
$registry = app(RuntimeExecutionRegistry::class);
$execKey = RuntimeExecutionKeys::marketingAutomation($key);

try {
    $registry->beginExclusiveRun($execKey, 'php_pid=' . getmypid() . ' key=' . $key);
} catch (RuntimeExecutionConflictException $e) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    fwrite(STDERR, 'marketing-automations: exclusive-run-conflict: ' . $e->getMessage() . PHP_EOL);
    exit(11);
} catch (\Throwable $e) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    fwrite(STDERR, 'marketing-automations: registry_begin_failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** @var \Modules\Marketing\Services\MarketingAutomationExecutionService $svc */
$svc = app(\Modules\Marketing\Services\MarketingAutomationExecutionService::class);
$exitCode = 0;

try {
    $summary = $svc->executeAutomationForBranch($branchId, $key, $dryRun);

    echo 'branch_id=' . $branchId . PHP_EOL;
    echo 'automation_key=' . $summary['automation_key'] . PHP_EOL;
    echo 'dry_run=' . ($summary['dry_run'] ? 'yes' : 'no') . PHP_EOL;
    echo 'enabled=' . ($summary['enabled'] ? 'yes' : 'no') . PHP_EOL;
    echo 'eligible=' . (int) $summary['eligible'] . PHP_EOL;
    echo 'skipped_disabled=' . (int) $summary['skipped_disabled'] . PHP_EOL;
    echo 'skipped_duplicate=' . (int) $summary['skipped_duplicate'] . PHP_EOL;
    echo 'enqueued=' . (int) $summary['enqueued'] . PHP_EOL;
    echo 'invalid_recipient_data=' . (int) $summary['invalid_recipient_data'] . PHP_EOL;

    $registry->completeExclusiveSuccess($execKey);
} catch (\Throwable $e) {
    $exitCode = 1;
    try {
        $registry->completeExclusiveFailure($execKey, $e->getMessage());
    } catch (\Throwable) {
    }
    fwrite(STDERR, 'execution_error=' . $e->getMessage() . PHP_EOL);
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

exit($exitCode);
