<?php

declare(strict_types=1);

/**
 * Runtime smoke: staff trash / restore / permanent delete / purge (tenant-scoped).
 *
 * Requires ≥2 active staff in branch scope with no appointment_series or payroll_commission_lines rows.
 *
 *   php system/scripts/dev-only/smoke_staff_trash_bulk_01.php
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Core\Auth\SessionAuth;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Services\StaffService;

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$repo = app(StaffRepository::class);
$service = app(StaffService::class);

$br = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE b.deleted_at IS NULL ORDER BY b.id LIMIT 1'
);
if ($br === null) {
    fwrite(STDERR, "ABORT: no live branch\n");
    exit(1);
}
$branchId = (int) $br['branch_id'];
$orgId = (int) $br['organization_id'];

$admin = $db->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');
if ($admin === null) {
    fwrite(STDERR, "ABORT: no user\n");
    exit(1);
}
$actorId = (int) $admin['id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
if (session_status() === PHP_SESSION_NONE) {
    Application::container()->get(SessionAuth::class)->startSession();
}
$_SESSION['user_id'] = $actorId;

$tenantCtx = TenantContext::resolvedTenant(
    actorId: $actorId,
    organizationId: $orgId,
    branchId: $branchId,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::CLI,
    organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
);
$contextHolder->set($tenantCtx);

$liveRows = $repo->list(['branch_id' => $branchId], 200, 0, false);
$candidates = [];
foreach ($liveRows as $r) {
    $sid = (int) $r['id'];
    if ($repo->countAppointmentSeriesForStaff($sid) > 0) {
        continue;
    }
    if ($repo->countPayrollCommissionLinesForStaff($sid) > 0) {
        continue;
    }
    $candidates[] = $sid;
    if (count($candidates) >= 3) {
        break;
    }
}

if (count($candidates) < 3) {
    fwrite(STDERR, 'ABORT: need 3 staff in branch scope with no appointment_series/payroll_commission_lines (found ' . count($candidates) . ")\n");
    exit(1);
}

$idA = $candidates[0];
$idB = $candidates[1];
$idC = $candidates[2];

$fail = 0;
$pass = static function (string $m): void {
    fwrite(STDOUT, "PASS  {$m}\n");
};
$failf = static function (string $m) use (&$fail): void {
    $fail++;
    fwrite(STDERR, "FAIL  {$m}\n");
};

try {
    $service->delete($idA);
} catch (\Throwable $e) {
    $failf('single trash: ' . $e->getMessage());
    exit(1);
}
if ($repo->find($idA) !== null) {
    $failf('active find still sees trashed A');
} else {
    $pass('single trash: not in active find()');
}
if ($repo->findTrashed($idA) === null) {
    $failf('findTrashed missing A');
} else {
    $pass('single trash: trashed row loadable');
}

$n = $service->bulkTrash([$idB]);
if ($n < 1) {
    $failf('bulk trash 0 rows');
} else {
    $pass('bulk trash: ' . $n . ' row(s)');
}

try {
    $service->restore($idA);
} catch (\Throwable $e) {
    $failf('restore A: ' . $e->getMessage());
}
if ($repo->find($idA) === null) {
    $failf('A not active after restore');
} else {
    $pass('restore: A back in active list');
}

try {
    $service->permanentlyDelete($idB);
} catch (\Throwable $e) {
    $failf('permanent B: ' . $e->getMessage());
}
$rowB = $db->fetchOne('SELECT id FROM staff WHERE id = ?', [$idB]);
if ($rowB !== null) {
    $failf('B still exists after permanent delete');
} else {
    $pass('permanent delete: B physically removed');
}

try {
    $service->permanentlyDelete($idA);
    $failf('permanent on active should fail');
} catch (\DomainException) {
    $pass('permanent delete rejects active row');
}

// Purge expired
$service->delete($idA);
$db->query(
    'UPDATE staff SET purge_after_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?',
    [$idA]
);
$r = $service->purgeExpiredTrashedBatch(20);
if ($r['purged'] < 1) {
    $failf('purge expected >=1, got ' . json_encode($r));
} else {
    $pass('purge: expired trashed removed (' . $r['purged'] . ')');
}

// Unexpired not purged
$service->delete($idC);
$db->query(
    'UPDATE staff SET purge_after_at = DATE_ADD(NOW(), INTERVAL 365 DAY) WHERE id = ?',
    [$idC]
);
$r2 = $service->purgeExpiredTrashedBatch(20);
if ($db->fetchOne('SELECT id FROM staff WHERE id = ?', [$idC]) === null) {
    $failf('unexpired trashed C was purged');
} else {
    $pass('purge: unexpired trashed staff retained');
}

exit($fail > 0 ? 1 : 0);
