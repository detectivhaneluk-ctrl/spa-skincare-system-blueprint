<?php

declare(strict_types=1);

/**
 * STAFF-TRASH-PERMANENT-DELETE-HARD-PROOF-01 — DB + service-layer evidence for hard delete.
 *
 * Proves:
 *  - Disposable trashed staff: row count 1 → permanent delete → 0
 *  - Trash repository views: visible before, absent after
 *  - Restore after permanent delete fails (DomainException)
 *  - Active staff: permanentlyDelete blocked (trash-only rule)
 *  - appointment_series reference: domain block before DELETE
 *
 *   php system/scripts/dev-only/smoke_staff_permanent_delete_hard_proof_01.php
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

$fail = 0;
$pass = static function (string $m): void {
    fwrite(STDOUT, "PASS  {$m}\n");
};
$failf = static function (string $m) use (&$fail): void {
    $fail++;
    fwrite(STDERR, "FAIL  {$m}\n");
};

$suffix = bin2hex(random_bytes(4));
$proofId = $db->insert('staff', [
    'first_name' => 'HardProof',
    'last_name' => 'Tmp' . $suffix,
    'branch_id' => $branchId,
    'is_active' => 1,
]);
if ($proofId <= 0) {
    $failf('could not insert disposable staff');
    exit(1);
}

// --- A) DB count before trash ---
$c1 = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM staff WHERE id = ?', [$proofId])['c'] ?? 0);
if ($c1 !== 1) {
    $failf("DB count before trash expected 1, got {$c1}");
} else {
    $pass("A) DB count before trash = 1 (id={$proofId})");
}

$service->delete($proofId);

$inTrash = $repo->findTrashed($proofId);
if ($inTrash === null) {
    $failf('disposable staff not visible via findTrashed after trash');
} else {
    $pass('B) Trash query: findTrashed sees row after move to Trash');
}

$trashListBefore = $repo->list(['branch_id' => $branchId], 500, 0, true);
$idsTrash = array_map(static fn (array $r): int => (int) $r['id'], $trashListBefore);
if (!in_array($proofId, $idsTrash, true)) {
    $failf('disposable staff not in trash list()');
} else {
    $pass('B) Trash list query includes disposable id');
}

$service->permanentlyDelete($proofId);

$c0 = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM staff WHERE id = ?', [$proofId])['c'] ?? 0);
if ($c0 !== 0) {
    $failf("DB count after permanent delete expected 0, got {$c0}");
} else {
    $pass('A) DB count after permanent delete = 0');
}

if ($repo->findTrashed($proofId) !== null) {
    $failf('findTrashed still returns row after hard delete');
} else {
    $pass('B) Trash query: findTrashed null after hard delete');
}

$trashListAfter = $repo->list(['branch_id' => $branchId], 500, 0, true);
$idsTrashAfter = array_map(static fn (array $r): int => (int) $r['id'], $trashListAfter);
if (in_array($proofId, $idsTrashAfter, true)) {
    $failf('trash list still contains id after hard delete');
} else {
    $pass('B) Trash list query excludes id after hard delete');
}

if ($repo->find($proofId, true) !== null) {
    $failf('find(withTrashed) still sees deleted row');
} else {
    $pass('Repository read: find(id, withTrashed=true) is null');
}

try {
    $service->restore($proofId);
    $failf('restore after permanent delete should throw');
} catch (\DomainException $e) {
    if (!str_contains($e->getMessage(), 'not found')) {
        $failf('unexpected restore message: ' . $e->getMessage());
    } else {
        $pass('C) Restore after permanent delete fails (DomainException)');
    }
}

// --- Active row cannot be permanently deleted ---
$activeId = $db->insert('staff', [
    'first_name' => 'HardProof',
    'last_name' => 'Active' . $suffix,
    'branch_id' => $branchId,
    'is_active' => 1,
]);
try {
    $service->permanentlyDelete($activeId);
    $failf('permanent delete on active staff must be rejected');
} catch (\DomainException $e) {
    if (!str_contains($e->getMessage(), 'Only trashed')) {
        $failf('unexpected active permanent-delete message: ' . $e->getMessage());
    } else {
        $pass('Active-only guard: permanentlyDelete rejects non-trashed row');
    }
}
// cleanup active test row (still live)
$db->query('DELETE FROM staff WHERE id = ?', [$activeId]);

// --- D) appointment_series blocks hard delete (domain rule) ---
$client = $db->fetchOne(
    'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
    [$branchId]
);
$svc = $db->fetchOne(
    'SELECT id FROM services WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
    [$branchId]
);
if ($client === null || $svc === null) {
    $pass('D) SKIP dependency block test (no client/service in branch)');
} else {
    $seriesStaffId = $db->insert('staff', [
        'first_name' => 'HardProof',
        'last_name' => 'Series' . $suffix,
        'branch_id' => $branchId,
        'is_active' => 1,
    ]);
    $service->delete($seriesStaffId);
    $db->query(
        'INSERT INTO appointment_series (
            branch_id, client_id, service_id, staff_id,
            recurrence_type, interval_weeks, weekday, start_date, start_time, end_time, status
        ) VALUES (?, ?, ?, ?, \'weekly\', 1, 1, CURDATE(), \'09:00:00\', \'10:00:00\', \'active\')',
        [$branchId, (int) $client['id'], (int) $svc['id'], $seriesStaffId]
    );
    $seriesId = (int) $db->lastInsertId();
    try {
        $service->permanentlyDelete($seriesStaffId);
        $failf('permanent delete should be blocked by appointment_series');
    } catch (\DomainException $e) {
        if (!str_contains($e->getMessage(), 'appointment series')) {
            $failf('unexpected series block message: ' . $e->getMessage());
        } else {
            $pass('D) Blocked when appointment_series references staff');
        }
    }
    $db->query('DELETE FROM appointment_series WHERE id = ?', [$seriesId]);
    $service->permanentlyDelete($seriesStaffId);
    if ($db->fetchOne('SELECT id FROM staff WHERE id = ?', [$seriesStaffId]) !== null) {
        $failf('cleanup: series staff still exists');
    } else {
        $pass('D) cleanup: staff removed after series row deleted');
    }
}

exit($fail > 0 ? 1 : 0);
