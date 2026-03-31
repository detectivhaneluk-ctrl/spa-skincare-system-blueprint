<?php

declare(strict_types=1);

/**
 * FOUNDATION-MINIMAL-REGRESSION-TEST-WAVE-01
 *
 * Complements {@see smoke_sales_tenant_data_plane_hardening_01.php} (cross-org invoice/payment read + mutate).
 * This script adds: same-org wrong-branch sales gates, public-commerce staff boundary hooks, payroll invoice-plane
 * tenant consistency, and read-only verifier subprocess smoke.
 *
 * Requires seeded smoke branches (SMOKE_A, SMOKE_B same org; SMOKE_C other org): seed_branch_smoke_data.php
 *
 * From repo root:
 *   php system/scripts/smoke_foundation_minimal_regression_wave_01.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationContext;
use Modules\Payroll\Services\PayrollService;
use Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository;
use Modules\PublicCommerce\Services\PublicCommerceService;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Repositories\RegisterSessionRepository;
use Modules\Sales\Services\InvoiceService;
use Modules\Sales\Services\PaymentService;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(\Core\Kernel\RequestContextHolder::class);
$invoiceRepo = app(InvoiceRepository::class);
$registerRepo = app(RegisterSessionRepository::class);
$paymentService = app(PaymentService::class);
$invoiceService = app(InvoiceService::class);
$pcPurchases = app(PublicCommercePurchaseRepository::class);

// Resolve smoke admin actor for permission-bearing context (admin role has all tenant permissions).
$smokeAdminRow = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', ['tenant-admin-a@example.test']);
if ($smokeAdminRow === null) {
    fwrite(STDERR, "Missing smoke admin user tenant-admin-a@example.test — run seed_branch_smoke_data.php first.\n");
    exit(1);
}
$smokeActorId = (int) $smokeAdminRow['id'];
$publicCommerceService = app(PublicCommerceService::class);
$payrollService = app(PayrollService::class);

$passed = 0;
$failed = 0;
function mrPass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function mrFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }
function mrExpectAccessDenied(callable $fn): bool {
    try {
        $fn();

        return false;
    } catch (AccessDeniedException) {
        return true;
    }
}

/**
 * @return array{branch_id:int, organization_id:int}
 */
$resolveScope = static function (string $branchCode) use ($db): array {
    $row = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id AS organization_id
         FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.code = ? AND b.deleted_at IS NULL
         LIMIT 1',
        [$branchCode]
    );
    if ($row === null) {
        throw new RuntimeException('Missing branch code ' . $branchCode . ' (seed smoke branches first).');
    }

    return ['branch_id' => (int) $row['branch_id'], 'organization_id' => (int) $row['organization_id']];
};

$setScope = static function (int $branchId, int $orgId) use ($branchContext, $orgContext, $contextHolder, $smokeActorId): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
    $contextHolder->set(\Core\Kernel\TenantContext::resolvedTenant(
        actorId: $smokeActorId,
        organizationId: $orgId,
        branchId: $branchId,
        isSupportEntry: false,
        supportActorId: null,
        assuranceLevel: \Core\Kernel\AssuranceLevel::SESSION,
        executionSurface: \Core\Kernel\ExecutionSurface::CLI,
        organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
    ));
};

$scopeA = $resolveScope('SMOKE_A');
$scopeB = $resolveScope('SMOKE_B');
$scopeC = $resolveScope('SMOKE_C');
$now = date('Y-m-d H:i:s');

$registerRepo->create([
    'branch_id' => $scopeA['branch_id'],
    'opened_by' => 1,
    'opened_at' => $now,
    'opening_cash_amount' => 100.00,
    'status' => 'open',
]);
$registerRepo->create([
    'branch_id' => $scopeB['branch_id'],
    'opened_by' => 1,
    'opened_at' => $now,
    'opening_cash_amount' => 100.00,
    'status' => 'open',
]);

if ($scopeA['organization_id'] !== $scopeB['organization_id']) {
    mrFail('fixture_smoke_a_b_same_org', 'SMOKE_A and SMOKE_B must share organization_id for wrong-branch tests');
    echo "\nSummary: {$passed} passed, {$failed} failed.\n";
    exit(1);
}

// --- A) Same-org wrong-branch: repo read still visible org-wide; branch gate fails closed (403-class exception) ---
$setScope($scopeB['branch_id'], $scopeB['organization_id']);
$invoiceWrongBranchId = $invoiceRepo->create([
    'invoice_number' => 'MR01-B-' . time() . '-' . random_int(1000, 9999),
    'branch_id' => $scopeB['branch_id'],
    'status' => 'draft',
    'currency' => 'USD',
    'subtotal_amount' => 50.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'total_amount' => 50.00,
    'paid_amount' => 0.00,
    'issued_at' => $now,
    'created_by' => 1,
    'updated_by' => 1,
]);
$paymentOnBranchBId = $paymentService->create([
    'invoice_id' => $invoiceWrongBranchId,
    'payment_method' => 'cash',
    'amount' => 5.00,
    'status' => 'completed',
    'notes' => 'MR01 payment on branch B invoice',
]);

$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$invCrossBranch = $invoiceRepo->find($invoiceWrongBranchId);
if ($invCrossBranch === null) {
    mrFail('same_org_cross_branch_invoice_repo_still_visible', 'expected org-scoped find to return row for branch B while in org');
} else {
    mrPass('same_org_cross_branch_invoice_repo_still_visible');
}

mrExpectAccessDenied(static function () use ($branchContext, $invCrossBranch): void {
    $bid = isset($invCrossBranch['branch_id']) && $invCrossBranch['branch_id'] !== '' && $invCrossBranch['branch_id'] !== null
        ? (int) $invCrossBranch['branch_id']
        : null;
    $branchContext->assertBranchMatchOrGlobalEntity($bid);
})
    ? mrPass('same_org_wrong_branch_assert_branch_match_denied')
    : mrFail('same_org_wrong_branch_assert_branch_match_denied', 'expected AccessDeniedException');

mrExpectAccessDenied(static function () use ($paymentService, $invoiceWrongBranchId): void {
    $paymentService->create([
        'invoice_id' => $invoiceWrongBranchId,
        'payment_method' => 'cash',
        'amount' => 5.0,
        'status' => 'completed',
        'notes' => 'MR01 wrong-branch payment',
    ]);
})
    ? mrPass('same_org_wrong_branch_payment_create_denied')
    : mrFail('same_org_wrong_branch_payment_create_denied', 'expected AccessDeniedException');

mrExpectAccessDenied(static function () use ($invoiceService, $invoiceWrongBranchId): void {
    $invoiceService->delete($invoiceWrongBranchId);
})
    ? mrPass('same_org_wrong_branch_invoice_delete_denied')
    : mrFail('same_org_wrong_branch_invoice_delete_denied', 'expected AccessDeniedException');

mrExpectAccessDenied(static function () use ($paymentService, $paymentOnBranchBId): void {
    $paymentService->refund($paymentOnBranchBId, 1.0, 'MR01 wrong-branch refund');
})
    ? mrPass('same_org_wrong_branch_payment_refund_denied')
    : mrFail('same_org_wrong_branch_payment_refund_denied', 'expected AccessDeniedException');

// Same-branch happy path on a fresh draft invoice (no cross-branch conflict).
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$invoiceOkId = $invoiceRepo->create([
    'invoice_number' => 'MR01-A-' . time() . '-' . random_int(1000, 9999),
    'branch_id' => $scopeA['branch_id'],
    'status' => 'draft',
    'currency' => 'USD',
    'subtotal_amount' => 10.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'total_amount' => 10.00,
    'paid_amount' => 0.00,
    'issued_at' => $now,
    'created_by' => 1,
    'updated_by' => 1,
]);
try {
    $paymentService->create([
        'invoice_id' => $invoiceOkId,
        'payment_method' => 'cash',
        'amount' => 4.0,
        'status' => 'completed',
        'notes' => 'MR01 same-branch payment partial',
    ]);
    $invoiceService->update($invoiceOkId, [
        'items' => [[
            'item_type' => 'manual',
            'description' => 'MR01 line',
            'quantity' => 1,
            'unit_price' => 10.0,
            'discount_amount' => 0.0,
            'tax_rate' => 0.0,
        ]],
        'status' => 'open',
        'discount_amount' => 0.0,
        'tax_amount' => 0.0,
    ]);
    mrPass('same_branch_payment_and_invoice_update_allowed');
} catch (\Throwable $e) {
    mrFail('same_branch_payment_and_invoice_update_allowed', $e->getMessage());
}

// --- B) Public commerce: staff queue / sync fail closed without scope or session ---
$unscopedQueue = $pcPurchases->listAwaitingVerificationWithInvoices(null, null, 20);
if ($unscopedQueue === []) {
    mrPass('public_commerce_queue_unscoped_returns_empty');
} else {
    mrFail('public_commerce_queue_unscoped_returns_empty', 'expected empty list without branch or org');
}

$queueNoSession = $publicCommerceService->listStaffAwaitingVerificationQueue(20);
if ($queueNoSession === []) {
    mrPass('public_commerce_staff_queue_no_session_empty');
} else {
    mrFail('public_commerce_staff_queue_no_session_empty', 'expected empty when session user absent');
}

$syncNoActor = $publicCommerceService->staffTrustedFulfillmentSync($invoiceOkId);
if (($syncNoActor['ok'] ?? true) === false && ($syncNoActor['error_code'] ?? '') === 'unauthenticated') {
    mrPass('public_commerce_staff_sync_no_session_unauthenticated');
} else {
    mrFail('public_commerce_staff_sync_no_session_unauthenticated', json_encode($syncNoActor));
}

$orgScopedQueue = $publicCommerceService->listStaffAwaitingVerificationQueue(5);
if (is_array($orgScopedQueue)) {
    mrPass('public_commerce_staff_queue_listing_returns_array_under_org_context');
} else {
    mrFail('public_commerce_staff_queue_listing_returns_array_under_org_context', 'expected array');
}

// --- C) Payroll: eligible-service-line query respects resolved invoice-plane tenant (SalesTenantScope) ---
$fetchEligible = new \ReflectionMethod(PayrollService::class, 'fetchEligibleServiceLineEvents');
$fetchEligible->setAccessible(true);

$setScope($scopeC['branch_id'], $scopeC['organization_id']);
$leaked = $fetchEligible->invoke($payrollService, $scopeA['branch_id'], '2000-01-01', '2099-12-31');
if ($leaked === []) {
    mrPass('payroll_eligible_events_no_cross_org_branch_param_under_foreign_tenant');
} else {
    mrFail('payroll_eligible_events_no_cross_org_branch_param_under_foreign_tenant', json_encode(array_slice($leaked, 0, 3)));
}

$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$events = $fetchEligible->invoke($payrollService, $scopeA['branch_id'], '2000-01-01', '2099-12-31');
$orgIdA = $scopeA['organization_id'];
$bad = false;
foreach ($events as $ev) {
    $iid = (int) ($ev['invoice_id'] ?? 0);
    if ($iid <= 0) {
        $bad = true;
        break;
    }
    $row = $db->fetchOne(
        'SELECT b.organization_id AS organization_id
         FROM invoices i
         INNER JOIN branches b ON b.id = i.branch_id AND b.deleted_at IS NULL
         WHERE i.id = ? AND i.deleted_at IS NULL',
        [$iid]
    );
    if ($row === null || (int) ($row['organization_id'] ?? 0) !== $orgIdA) {
        $bad = true;
        break;
    }
}
$bad ? mrFail('payroll_eligible_events_invoice_plane_stays_in_resolved_org', 'foreign or missing org row')
    : mrPass('payroll_eligible_events_invoice_plane_stays_in_resolved_org');

// --- D) Read-only verifier subprocess smoke (repo-native pattern) ---
$php = PHP_BINARY;
$verifyPc = dirname(__DIR__) . '/scripts/read-only/verify_public_commerce_json_controller_staff_boundary_wave_01.php';
$verifyBasePath = dirname(__DIR__) . '/scripts/read-only/verify_base_path_requires_system_path_m007_01.php';
$auditMig = dirname(__DIR__) . '/scripts/read-only/audit_migration_branch_columns.php';

exec(escapeshellarg($php) . ' ' . escapeshellarg($verifyPc) . ' 2>&1', $oPc, $cPc);
$cPc === 0 ? mrPass('readonly_verify_public_commerce_json_staff_boundary_cli') : mrFail('readonly_verify_public_commerce_json_staff_boundary_cli', implode("\n", $oPc));

exec(escapeshellarg($php) . ' ' . escapeshellarg($verifyBasePath) . ' 2>&1', $oBp, $cBp);
$cBp === 0 ? mrPass('readonly_verify_base_path_m007_cli') : mrFail('readonly_verify_base_path_m007_cli', implode("\n", $oBp));

exec(escapeshellarg($php) . ' ' . escapeshellarg($auditMig) . ' 2>&1', $oAm, $cAm);
if ($cAm === 0 && count($oAm) > 0) {
    mrPass('readonly_audit_migration_branch_columns_cli_smoke');
} else {
    mrFail('readonly_audit_migration_branch_columns_cli_smoke', 'exit ' . $cAm . ' output_lines=' . count($oAm));
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
echo "Note: cross-tenant sales invoice/payment read + mutate regressions live in smoke_sales_tenant_data_plane_hardening_01.php\n";
exit($failed > 0 ? 1 : 0);
