<?php

declare(strict_types=1);

/**
 * Service-layer proof: ServiceService::bulkPermanentlyDelete returns deleted + blocked.
 *
 *   php system/scripts/dev-only/smoke_services_bulk_permanent_outcome_01.php
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\Database;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Modules\ServicesResources\Services\ServiceService;

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
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

$contextHolder->set(TenantContext::resolvedTenant(
    actorId: $actorId,
    organizationId: $orgId,
    branchId: $branchId,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::CLI,
    organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
));

$suffix = bin2hex(random_bytes(4));
$idOk = $db->insert('services', [
    'name' => 'BulkOut Ok ' . $suffix,
    'branch_id' => $branchId,
    'sku' => 'BULK-OK-' . $suffix,
    'is_active' => 1,
]);
$idBad = $db->insert('services', [
    'name' => 'BulkOut Bad ' . $suffix,
    'branch_id' => $branchId,
    'sku' => 'BULK-BAD-' . $suffix,
    'is_active' => 1,
]);

$service->delete($idOk);
$service->delete($idBad);

$client = $db->fetchOne(
    'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
    [$branchId]
);
$staffRow = $db->fetchOne(
    'SELECT id FROM staff WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
    [$branchId]
);
if ($client === null || $staffRow === null) {
    fwrite(STDERR, "SKIP: need client+staff for series\n");
    $db->query('DELETE FROM services WHERE id IN (?, ?)', [$idOk, $idBad]);
    exit(0);
}

$db->query(
    'INSERT INTO appointment_series (
        branch_id, client_id, service_id, staff_id,
        recurrence_type, interval_weeks, weekday, start_date, start_time, end_time, status
    ) VALUES (?, ?, ?, ?, \'weekly\', 1, 1, CURDATE(), \'09:00:00\', \'10:00:00\', \'active\')',
    [$branchId, (int) $client['id'], $idBad, (int) $staffRow['id']]
);

$out = $service->bulkPermanentlyDelete([$idOk, $idBad]);

$fail = 0;
if (($out['deleted'] ?? 0) !== 1) {
    fwrite(STDERR, 'FAIL: expected deleted=1, got ' . json_encode($out) . "\n");
    $fail++;
}
if (count($out['blocked'] ?? []) !== 1) {
    fwrite(STDERR, "FAIL: expected 1 blocked row\n");
    $fail++;
} else {
    $b = $out['blocked'][0];
    if ((int) ($b['id'] ?? 0) !== $idBad) {
        fwrite(STDERR, "FAIL: blocked id should be bad service\n");
        $fail++;
    }
    $reason = strtolower((string) ($b['reason'] ?? ''));
    if (!str_contains($reason, 'appointment series') && !str_contains($reason, 'related records')) {
        fwrite(STDERR, "FAIL: blocked reason unexpected\n");
        $fail++;
    }
}

$db->query('DELETE FROM appointment_series WHERE service_id = ?', [$idBad]);
try {
    $service->permanentlyDelete($idBad);
} catch (\Throwable) {
    $db->query('DELETE FROM services WHERE id = ?', [$idBad]);
}

if ($fail === 0) {
    fwrite(STDOUT, "PASS  services bulk partial outcome\n");
}

exit($fail > 0 ? 1 : 0);
