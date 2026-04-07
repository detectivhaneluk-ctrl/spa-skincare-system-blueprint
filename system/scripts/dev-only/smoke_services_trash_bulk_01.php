<?php

declare(strict_types=1);

/**
 * Runtime smoke: services trash / restore / permanent delete / purge (tenant-scoped).
 *
 * Requires seeded DB + at least one live service in current org branch scope.
 *
 *   php system/scripts/dev-only/smoke_services_trash_bulk_01.php
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
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\ServicesResources\Services\ServiceService;

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$repo = app(ServiceRepository::class);
$service = app(ServiceService::class);

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

$live = $repo->list(null, $branchId, false);
if (count($live) < 2) {
    fwrite(STDERR, "ABORT: need at least 2 active services in branch scope (found " . count($live) . ")\n");
    exit(1);
}

$idA = (int) $live[0]['id'];
$idB = (int) $live[1]['id'];

$fail = 0;
$pass = function (string $m) use (&$pass): void {
    fwrite(STDOUT, "PASS  {$m}\n");
};
$failf = function (string $m) use (&$fail): void {
    $fail++;
    fwrite(STDERR, "FAIL  {$m}\n");
};

// 1) Single trash
try {
    $service->delete($idA);
} catch (\Throwable $e) {
    $failf('single trash: ' . $e->getMessage());
    exit(1);
}
if ($repo->find($idA) !== null) {
    $failf('active list find still sees trashed A');
} else {
    $pass('single trash: not in active find()');
}
if ($repo->findTrashed($idA) === null) {
    $failf('trash findTrashed missing A');
} else {
    $pass('single trash: visible as trashed');
}
$trashList = $repo->list(null, $branchId, true);
$trashIds = array_column($trashList, 'id');
if (!in_array($idA, array_map('intval', $trashIds), true)) {
    $failf('trash list missing A');
} else {
    $pass('single trash: trash list contains A');
}

// 2) Bulk trash B
$n = $service->bulkTrash([$idB]);
if ($n < 1) {
    $failf('bulk trash affected 0');
} else {
    $pass('bulk trash: ' . $n . ' row(s)');
}
if ($repo->find($idB) !== null) {
    $failf('B still active after bulk');
} else {
    $pass('bulk trash: B not active');
}

// 3) Restore A
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

// 4) Permanent delete B (trashed)
try {
    $service->permanentlyDelete($idB);
} catch (\Throwable $e) {
    $failf('permanent B: ' . $e->getMessage());
}
$rowB = $db->fetchOne('SELECT id FROM services WHERE id = ?', [$idB]);
if ($rowB !== null) {
    $failf('B row still exists after permanent delete');
} else {
    $pass('permanent delete: B physically removed');
}

// 5) Active service must not hard-delete via permanent API
try {
    $service->permanentlyDelete($idA);
    $failf('permanent delete on active should fail');
} catch (\DomainException) {
    $pass('permanent delete rejects active row');
}

// 6) Purge: trash A with past purge_after_at
$service->delete($idA);
$db->query(
    'UPDATE services SET purge_after_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?',
    [$idA]
);
$r = $service->purgeExpiredTrashedBatch(20);
if ($r['purged'] < 1) {
    $failf('purge expected >=1 purged, got ' . json_encode($r));
} else {
    $pass('purge: expired trashed removed (' . $r['purged'] . ')');
}
$rowA = $db->fetchOne('SELECT id FROM services WHERE id = ?', [$idA]);
if ($rowA !== null) {
    $failf('A still exists after purge');
} else {
    $pass('purge: A physically gone');
}

// 7) Unexpired trashed not purged: recreate minimal row is heavy — set purge_after future on hypothetical skipped path
// Skipped if no spare service; smoke already covered happy purge.

exit($fail > 0 ? 1 : 0);
