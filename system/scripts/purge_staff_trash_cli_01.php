<?php

declare(strict_types=1);

/**
 * Purge expired trashed staff for one organization or all organizations (cron-safe).
 *
 * From repo root:
 *   php system/scripts/purge_staff_trash_cli_01.php --organization-id=1
 *   php system/scripts/purge_staff_trash_cli_01.php --all-organizations --batch=50
 *   php system/scripts/purge_staff_trash_cli_01.php --organization-id=1 --repeat-until-empty
 */

$systemRoot = dirname(__DIR__);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Staff\Services\StaffService;

$db             = app(Database::class);
$branchContext  = app(BranchContext::class);
$orgContext     = app(OrganizationContext::class);
$staffService   = app(StaffService::class);

$orgId   = null;
$allOrgs = false;
$batch   = 50;
$repeat  = false;

foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--organization-id=')) {
        $orgId = (int) trim(substr($arg, strlen('--organization-id=')));
    }
    if ($arg === '--all-organizations') {
        $allOrgs = true;
    }
    if (str_starts_with($arg, '--batch=')) {
        $batch = max(1, min(500, (int) trim(substr($arg, strlen('--batch=')))));
    }
    if ($arg === '--repeat-until-empty') {
        $repeat = true;
    }
}

if (!$allOrgs && ($orgId === null || $orgId <= 0)) {
    fwrite(STDERR, "Usage: --organization-id=POSITIVE_INT | --all-organizations  [--batch=1-500] [--repeat-until-empty]\n");
    exit(1);
}

$orgRows = $allOrgs
    ? $db->fetchAll('SELECT id FROM organizations WHERE deleted_at IS NULL ORDER BY id', [])
    : [['id' => $orgId]];

$grandPurged = 0;
$grandSkipB  = 0;
$grandSkipE  = 0;

foreach ($orgRows as $or) {
    $oid = (int) $or['id'];
    $br  = $db->fetchOne(
        'SELECT id FROM branches WHERE organization_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
        [$oid]
    );
    if ($br === null) {
        fwrite(STDERR, "purge_staff_trash: skip org {$oid} (no live branch)\n");
        continue;
    }
    $bid = (int) $br['id'];
    $branchContext->setCurrentBranchId($bid);
    $orgContext->setFromResolution($oid, OrganizationContext::MODE_BRANCH_DERIVED);

    $iter = 0;
    do {
        $r = $staffService->purgeExpiredTrashedBatch($batch);
        $grandPurged += $r['purged'];
        $grandSkipB  += $r['skipped_blocked'];
        $grandSkipE  += $r['skipped_error'];
        fwrite(
            STDOUT,
            sprintf(
                "org=%d batch purged=%d skipped_blocked=%d skipped_error=%d\n",
                $oid,
                $r['purged'],
                $r['skipped_blocked'],
                $r['skipped_error']
            )
        );
        $iter++;
        if ($iter > 10000) {
            fwrite(STDERR, "purge_staff_trash: safety stop after 10000 iterations (org {$oid})\n");
            break;
        }
    } while ($repeat && $r['purged'] > 0);
}

fwrite(
    STDOUT,
    sprintf("purge_staff_trash: total purged=%d skipped_blocked=%d skipped_error=%d\n", $grandPurged, $grandSkipB, $grandSkipE)
);
exit(0);
