<?php

declare(strict_types=1);

/**
 * FOUNDATION-48 — deterministic idempotent backfill for user_organization_memberships.
 *
 * Usage (from `system/`):
 *   php scripts/backfill_user_organization_memberships.php
 *   php scripts/backfill_user_organization_memberships.php --dry-run
 *
 * Exit codes: 0 success, 1 failure (no DB, table missing, exception).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

try {
    $pdo = app(\Core\App\Database::class)->connection();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($dbName) || $dbName === '') {
        fwrite(STDERR, "ERROR: no database selected\n");
        exit(1);
    }

    $svc = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class);
    $repo = app(\Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository::class);
    if (!$repo->isMembershipTablePresent()) {
        fwrite(STDERR, "ERROR: user_organization_memberships table not present (apply migration 087 first).\n");
        exit(1);
    }

    $r = $svc->run($dryRun);

    echo 'foundation_wave: FOUNDATION-48' . "\n";
    echo 'dry_run: ' . ($r['dry_run'] ? 'true' : 'false') . "\n";
    echo 'scanned: ' . $r['scanned'] . "\n";
    echo 'inserted: ' . $r['inserted'] . "\n";
    echo 'skipped_existing: ' . $r['skipped_existing'] . "\n";
    echo 'skipped_ambiguous: ' . $r['skipped_ambiguous'] . "\n";
    echo 'skipped_no_branch: ' . $r['skipped_no_branch'] . "\n";
    echo 'skipped_missing_branch_org: ' . $r['skipped_missing_branch_org'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
