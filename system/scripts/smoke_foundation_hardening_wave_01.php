<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Contracts\ClientGiftCardProfileProvider;
use Core\Contracts\ClientPackageProfileProvider;
use Core\Contracts\ClientSalesProfileProvider;
use Core\Contracts\PublicCommerceFulfillmentReconciler;
use Core\Organization\OrganizationContext;
use Modules\Clients\Services\ClientService;
use Modules\Memberships\Services\MembershipSaleService;
use Modules\Memberships\Support\MembershipEntitlementSnapshot;
use Modules\Packages\Services\PackageService;
use Modules\Packages\Support\PackageEntitlementSnapshot;
use Modules\Sales\Services\InvoiceService;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$clientService = app(ClientService::class);
$memSaleService = app(MembershipSaleService::class);
$packageService = app(PackageService::class);
$reconciler = app(PublicCommerceFulfillmentReconciler::class);
$invoiceService = app(InvoiceService::class);

$salesProv = app(ClientSalesProfileProvider::class);
$apptProv = app(ClientAppointmentProfileProvider::class);
$pkgProv = app(ClientPackageProfileProvider::class);
$giftProv = app(ClientGiftCardProfileProvider::class);

$passed = 0;
$failed = 0;
function fhPass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function fhFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

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

$setScope = static function (int $branchId, int $orgId) use ($branchContext, $orgContext): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
};

$sfx = 'FH01_' . bin2hex(random_bytes(3));
$scopeA = $resolveScope('SMOKE_A');
$scopeC = $resolveScope('SMOKE_C');
$today = date('Y-m-d');

$markInvoicePaidCanonical = static function (int $invoiceId) use ($db, $invoiceService): void {
    $inv = $db->fetchOne('SELECT id, total_amount, currency FROM invoices WHERE id = ?', [$invoiceId]);
    if ($inv === null) {
        throw new RuntimeException('Invoice not found: ' . $invoiceId);
    }
    $db->insert('payments', [
        'invoice_id' => (int) $inv['id'],
        'register_session_id' => null,
        'entry_type' => 'payment',
        'parent_payment_id' => null,
        'payment_method' => 'cash',
        'amount' => (float) ($inv['total_amount'] ?? 0),
        'currency' => (string) ($inv['currency'] ?? 'USD'),
        'status' => 'completed',
        'transaction_reference' => null,
        'paid_at' => date('Y-m-d H:i:s'),
        'notes' => 'smoke_foundation_hardening_wave_01',
        'created_by' => null,
    ]);
    $invoiceService->recomputeInvoiceFinancials($invoiceId);
};

// --- A) Missing membership snapshot → refund_review (no silent mutable definition) ---
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$clientMiss = $clientService->create(['first_name' => $sfx, 'last_name' => 'MissSnap']);
$db->insert('membership_definitions', [
    'branch_id' => $scopeA['branch_id'],
    'name' => $sfx . '_MemMiss',
    'description' => null,
    'duration_days' => 30,
    'price' => 20.0,
    'billing_enabled' => 0,
    'status' => 'active',
    'public_online_eligible' => 0,
]);
$memMissDefId = (int) $db->lastInsertId();
$missSale = $memSaleService->createSaleAndInvoice([
    'membership_definition_id' => $memMissDefId,
    'client_id' => $clientMiss,
    'branch_id' => $scopeA['branch_id'],
    'starts_at' => $today,
], []);
$missInvoiceId = (int) $missSale['invoice_id'];
$missSaleId = (int) $missSale['membership_sale_id'];
$db->query('UPDATE membership_sales SET definition_snapshot_json = NULL WHERE id = ?', [$missSaleId]);
$db->query('UPDATE membership_definitions SET duration_days = 5 WHERE id = ?', [$memMissDefId]);
$markInvoicePaidCanonical($missInvoiceId);
$memSaleService->syncMembershipSaleForInvoice($missInvoiceId);
$missRow = $db->fetchOne('SELECT status, client_membership_id FROM membership_sales WHERE id = ?', [$missSaleId]);
if (($missRow['status'] ?? '') === 'refund_review' && empty($missRow['client_membership_id'])) {
    fhPass('membership_missing_snapshot_refund_review_no_grant');
} else {
    fhFail('membership_missing_snapshot_refund_review_no_grant', json_encode($missRow));
}

// --- B) Membership snapshot immutability (sold 45d, definition changed to 1d before activation) ---
$clientMem = $clientService->create(['first_name' => $sfx, 'last_name' => 'MemSnap']);
$db->insert('membership_definitions', [
    'branch_id' => $scopeA['branch_id'],
    'name' => $sfx . '_Mem45',
    'description' => null,
    'duration_days' => 45,
    'price' => 33.0,
    'billing_enabled' => 0,
    'status' => 'active',
    'public_online_eligible' => 0,
]);
$mem45Id = (int) $db->lastInsertId();
$snapSale = $memSaleService->createSaleAndInvoice([
    'membership_definition_id' => $mem45Id,
    'client_id' => $clientMem,
    'branch_id' => $scopeA['branch_id'],
    'starts_at' => $today,
], []);
$snapInv = (int) $snapSale['invoice_id'];
$snapSaleId = (int) $snapSale['membership_sale_id'];
$db->query('UPDATE membership_definitions SET duration_days = 1 WHERE id = ?', [$mem45Id]);
$markInvoicePaidCanonical($snapInv);
$memSaleService->syncMembershipSaleForInvoice($snapInv);
$saleAfter = $db->fetchOne('SELECT status, client_membership_id FROM membership_sales WHERE id = ?', [$snapSaleId]);
$cmId = (int) ($saleAfter['client_membership_id'] ?? 0);
$cmRow = $cmId > 0 ? $db->fetchOne('SELECT ends_at, entitlement_snapshot_json FROM client_memberships WHERE id = ?', [$cmId]) : null;
$expectEnd = date('Y-m-d', strtotime($today . ' +45 days'));
$cmSnapJson = $cmRow['entitlement_snapshot_json'] ?? null;
$cmSnapStr = is_string($cmSnapJson) ? $cmSnapJson : (is_array($cmSnapJson) ? json_encode($cmSnapJson, JSON_THROW_ON_ERROR) : '');
$cmSnapDec = MembershipEntitlementSnapshot::decode($cmSnapStr !== '' ? $cmSnapStr : null);
if (
    ($saleAfter['status'] ?? '') === 'activated'
    && $cmRow
    && (string) ($cmRow['ends_at'] ?? '') === $expectEnd
    && $cmSnapDec !== null
    && (int) ($cmSnapDec['duration_days'] ?? 0) === 45
) {
    fhPass('membership_activation_uses_sale_snapshot_not_mutated_definition');
} else {
    fhFail('membership_activation_uses_sale_snapshot_not_mutated_definition', json_encode([$saleAfter, $cmRow, 'expectEnd' => $expectEnd]));
}

// --- C) Package assignment stores snapshot; definition drift does not rewrite snapshot ---
$clientPkg = $clientService->create(['first_name' => $sfx, 'last_name' => 'PkgSnap']);
$db->insert('packages', [
    'branch_id' => $scopeA['branch_id'],
    'name' => $sfx . '_Pkg8',
    'description' => null,
    'status' => 'active',
    'total_sessions' => 8,
    'validity_days' => null,
    'price' => 50.0,
    'public_online_eligible' => 0,
]);
$pkg8Id = (int) $db->lastInsertId();
$cpId = $packageService->assignPackageToClient([
    'package_id' => $pkg8Id,
    'client_id' => $clientPkg,
    'branch_id' => $scopeA['branch_id'],
    'assigned_sessions' => 8,
]);
$db->query('UPDATE packages SET total_sessions = 2 WHERE id = ?', [$pkg8Id]);
$rawPs = $db->fetchOne('SELECT package_snapshot_json FROM client_packages WHERE id = ?', [$cpId]);
$psVal = $rawPs['package_snapshot_json'] ?? null;
$psStr = is_string($psVal) ? $psVal : (is_array($psVal) ? json_encode($psVal, JSON_THROW_ON_ERROR) : '');
$snapPkg = PackageEntitlementSnapshot::decode($psStr !== '' ? $psStr : null);
if ($snapPkg && (int) ($snapPkg['total_sessions'] ?? 0) === 8) {
    fhPass('package_assignment_snapshot_immutable_after_definition_change');
} else {
    fhFail('package_assignment_snapshot_immutable_after_definition_change', json_encode($snapPkg));
}

// --- D) Public-commerce package fulfillment without snapshot → blocked + failed purchase ---
$clientPc = $clientService->create(['first_name' => $sfx, 'last_name' => 'PubCom']);
$invNo = 'FH01-PC-' . $sfx;
$db->insert('invoices', [
    'invoice_number' => $invNo,
    'client_id' => $clientPc,
    'appointment_id' => null,
    'branch_id' => $scopeA['branch_id'],
    'currency' => 'USD',
    'status' => 'paid',
    'subtotal_amount' => 10.0,
    'discount_amount' => 0,
    'tax_amount' => 0,
    'total_amount' => 10.0,
    'paid_amount' => 10.0,
    'notes' => 'smoke_foundation_hardening_wave_01',
]);
$pcInvoiceId = (int) $db->lastInsertId();
$tok = strtolower(hash('sha256', 'fh01tok_' . $sfx . '_' . random_bytes(8)));
$db->insert('public_commerce_purchases', [
    'token_hash' => $tok,
    'branch_id' => $scopeA['branch_id'],
    'client_id' => $clientPc,
    'client_resolution_reason' => 'smoke',
    'product_kind' => 'package',
    'package_id' => $pkg8Id,
    'membership_definition_id' => null,
    'package_snapshot_json' => null,
    'gift_card_amount' => null,
    'membership_sale_id' => null,
    'invoice_id' => $pcInvoiceId,
    'status' => 'initiated',
]);
$pcPurId = (int) $db->lastInsertId();
$res = $reconciler->reconcile($pcInvoiceId, PublicCommerceFulfillmentReconciler::TRIGGER_STAFF_MANUAL_SYNC, 1);
$purAfter = $db->fetchOne('SELECT status, client_package_id FROM public_commerce_purchases WHERE id = ?', [$pcPurId]);
if (
    ($res['outcome'] ?? '') === PublicCommerceFulfillmentReconciler::OUTCOME_BLOCKED
    && ($res['reason'] ?? '') === PublicCommerceFulfillmentReconciler::REASON_MISSING_PACKAGE_ENTITLEMENT_SNAPSHOT
    && ($purAfter['status'] ?? '') === 'failed'
    && empty($purAfter['client_package_id'])
) {
    fhPass('public_commerce_package_missing_snapshot_fail_closed');
} else {
    fhFail('public_commerce_package_missing_snapshot_fail_closed', json_encode([$res, $purAfter]));
}

// --- E) Client profile providers fail closed for foreign-branch client_id ---
$setScope($scopeC['branch_id'], $scopeC['organization_id']);
$clientOnC = $clientService->create(['first_name' => $sfx, 'last_name' => 'OnC']);
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$apptSum = $apptProv->getSummary($clientOnC);
$salesSum = $salesProv->getSummary($clientOnC);
$pkgSum = $pkgProv->getSummary($clientOnC);
$giftSum = $giftProv->getSummary($clientOnC);
if (($apptSum['total'] ?? -1) === 0
    && ($salesSum['invoice_count'] ?? -1) === 0
    && ($pkgSum['total'] ?? -1) === 0
    && ($giftSum['total'] ?? -1) === 0) {
    fhPass('client_profile_providers_foreign_client_fail_closed');
} else {
    fhFail('client_profile_providers_foreign_client_fail_closed', json_encode([$apptSum, $salesSum, $pkgSum, $giftSum]));
}

// --- F) Static verifiers ---
$php = PHP_BINARY;
$foot = dirname(__DIR__) . '/scripts/verify_tenant_repository_footguns.php';
$nullb = dirname(__DIR__) . '/scripts/verify_null_branch_catalog_patterns.php';
exec(escapeshellarg($php) . ' ' . escapeshellarg($foot) . ' 2>&1', $o1, $c1);
exec(escapeshellarg($php) . ' ' . escapeshellarg($nullb) . ' 2>&1', $o2, $c2);
if ($c1 === 0) {
    fhPass('verify_tenant_repository_footguns_cli');
} else {
    fhFail('verify_tenant_repository_footguns_cli', implode("\n", $o1));
}
if ($c2 === 0) {
    fhPass('verify_null_branch_catalog_patterns_cli');
} else {
    fhFail('verify_null_branch_catalog_patterns_cli', implode("\n", $o2));
}

// --- G) Prior regressions ---
passthru($php . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/smoke_memberships_giftcards_packages_hardening_01.php'), $r1);
if ($r1 === 0) {
    fhPass('prior_smoke_memberships_giftcards_packages_hardening_01');
} else {
    fhFail('prior_smoke_memberships_giftcards_packages_hardening_01', 'exit ' . $r1);
}
passthru($php . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/smoke_tenant_owned_data_plane_hardening_01.php'), $r2);
if ($r2 === 0) {
    fhPass('prior_smoke_tenant_owned_data_plane_hardening_01');
} else {
    fhFail('prior_smoke_tenant_owned_data_plane_hardening_01', 'exit ' . $r2);
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
