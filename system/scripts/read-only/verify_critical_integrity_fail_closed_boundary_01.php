<?php
/**
 * CRITICAL-INTEGRITY-FAIL-CLOSED-BOUNDARY-AND-LIFECYCLE-CLOSURE-01
 * Read-only verifier — no DB required, static analysis only.
 *
 * Sections:
 *   A  Lifecycle gate integrity (suspended org, inactive staff, public surfaces)
 *   B  FK-only service method structural fix (getCurrentBalance, getRemainingSessions)
 *   C  FK-only child repo parent-first mutation patterns
 *   D  Out-of-scope module route middleware coverage
 *   E  Inventory deprecated method discipline
 *
 * Run: php system/scripts/read-only/verify_critical_integrity_fail_closed_boundary_01.php
 * Expect: exit 0, all PASS
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';

$pass = 0;
$fail = 0;

$t = static function (bool $ok, string $id, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        ++$pass;
        echo "PASS [{$id}] {$msg}\n";
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL [{$id}] {$msg}\n");
    }
};

$read = static function (string $rel) use ($systemPath): string {
    $full = $systemPath . '/' . ltrim($rel, '/');
    if (!file_exists($full)) {
        fwrite(STDERR, "MISSING FILE: {$full}\n");
        return '';
    }
    return (string) file_get_contents($full);
};

/**
 * Extract the body of a named method from PHP source.
 * Bounded by next public/protected/private function declaration.
 */
$methodBody = static function (string $src, string $name): string {
    $pos = strpos($src, "function {$name}(");
    if ($pos === false) {
        return '';
    }
    $end = strlen($src);
    foreach (["\n    public function ", "\n    protected function ", "\n    private function "] as $s) {
        $nx = strpos($src, $s, $pos + 1);
        if ($nx !== false && $nx < $end) {
            $end = $nx;
        }
    }
    return substr($src, $pos, $end - $pos);
};

// ---------------------------------------------------------------------------
// Section A — Lifecycle gate integrity
// ---------------------------------------------------------------------------

$enforcer  = $read('core/tenant/TenantRuntimeContextEnforcer.php');
$gate      = $read('core/Organization/OrganizationLifecycleGate.php');
$authMw    = $read('core/middleware/AuthMiddleware.php');
$pubBook   = $read('modules/online-booking/services/PublicBookingService.php');
$pubComm   = $read('modules/public-commerce/services/PublicCommerceService.php');
$branchAcc = $read('core/Branch/TenantBranchAccessService.php');

$t(
    str_contains($enforcer, 'isOrganizationActive') || str_contains($enforcer, 'TENANT_ORGANIZATION_SUSPENDED'),
    'A1',
    'TenantRuntimeContextEnforcer blocks suspended organizations'
);
$t(
    str_contains($enforcer, 'isTenantUserInactiveStaffAtBranch') || str_contains($enforcer, 'TENANT_ACTOR_INACTIVE'),
    'A2',
    'TenantRuntimeContextEnforcer blocks inactive staff actors'
);
$t(
    str_contains($gate, 'isTenantUserInactiveStaffAtBranch'),
    'A3',
    'OrganizationLifecycleGate::isTenantUserInactiveStaffAtBranch() exists'
);
$t(
    str_contains($authMw, 'enforceForAuthenticatedUser'),
    'A4',
    'AuthMiddleware calls TenantRuntimeContextEnforcer::enforceForAuthenticatedUser'
);
$t(
    str_contains($pubBook, 'isBranchLinkedToSuspendedOrganization'),
    'A5',
    'PublicBookingService checks isBranchLinkedToSuspendedOrganization'
);
$t(
    str_contains($pubComm, 'isBranchLinkedToSuspendedOrganization'),
    'A6',
    'PublicCommerceService checks isBranchLinkedToSuspendedOrganization'
);
$cnt = substr_count($branchAcc, 'o.suspended_at IS NULL');
$t(
    $cnt >= 4,
    'A7',
    "TenantBranchAccessService has o.suspended_at IS NULL in {$cnt} SQL fragments (need >= 4)"
);

// ---------------------------------------------------------------------------
// Section B — FK-only service method structural fix
// ---------------------------------------------------------------------------

$giftSvc = $read('modules/gift-cards/services/GiftCardService.php');
$pkgSvc  = $read('modules/packages/services/PackageService.php');

$balanceBody = $methodBody($giftSvc, 'getCurrentBalance');
$posScope    = strpos($balanceBody, 'findInTenantScope');
$posLatest   = strpos($balanceBody, '->latestForCard(');
$t(
    $posScope !== false && $posLatest !== false && $posScope < $posLatest,
    'B1',
    'GiftCardService::getCurrentBalance calls findInTenantScope before ->latestForCard( (always proves tenant ownership first)'
);

$remainBody = $methodBody($pkgSvc, 'getRemainingSessions');
$posScopeR  = strpos($remainBody, 'findInTenantScope');
$posLatestR = strpos($remainBody, '->latestForClientPackage(');
$t(
    $posScopeR !== false && $posLatestR !== false && $posScopeR < $posLatestR,
    'B2',
    'PackageService::getRemainingSessions calls findInTenantScope before ->latestForClientPackage( (always proves tenant ownership first)'
);

$hasInvBody = $methodBody($giftSvc, 'hasInvoiceRedemption');
$t(
    str_contains($hasInvBody, 'existsRedeemForInvoice')
        && !str_contains($hasInvBody, 'fetchOne')
        && !str_contains($hasInvBody, 'fetchAll'),
    'B3',
    'GiftCardService::hasInvoiceRedemption is a boolean guard (no data row returned from service layer)'
);

$hasApptBody = $methodBody($pkgSvc, 'hasAppointmentConsumption');
$t(
    str_contains($hasApptBody, 'existsUsageByReference')
        && !str_contains($hasApptBody, 'fetchOne')
        && !str_contains($hasApptBody, 'fetchAll'),
    'B4',
    'PackageService::hasAppointmentConsumption is a boolean guard (no data row returned from service layer)'
);

// ---------------------------------------------------------------------------
// Section C — FK-only child repo parent-first mutation patterns
// ---------------------------------------------------------------------------

$memSvc       = $read('modules/memberships/services/MembershipService.php');
$memUsageRepo = $read('modules/memberships/repositories/MembershipBenefitUsageRepository.php');
$gcTransRepo  = $read('modules/gift-cards/repositories/GiftCardTransactionRepository.php');
$pkgUsageRepo = $read('modules/packages/repositories/PackageUsageRepository.php');

$consumeBody = $methodBody($memSvc, 'consumeBenefitForAppointment');
$posLock     = strpos($consumeBody, 'lockWithDefinitionInTenantScope');
$posInsert   = strpos($consumeBody, 'benefitUsages->insert');
$t(
    $posLock !== false && $posInsert !== false && $posLock < $posInsert,
    'C1',
    'MembershipService::consumeBenefitForAppointment loads parent via lockWithDefinitionInTenantScope before benefitUsages->insert'
);

$redeemBody = $methodBody($giftSvc, 'redeemForInvoice');
$posLoad    = PHP_INT_MAX;
foreach (['loadCardForUpdate', 'findLockedInTenantScope'] as $needle) {
    $p = strpos($redeemBody, $needle);
    if ($p !== false && $p < $posLoad) {
        $posLoad = $p;
    }
}
// redeemForInvoice delegates the actual create to $this->redeemGiftCard() after loadCardForUpdate
$posDelegate = strpos($redeemBody, 'redeemGiftCard(');
$t(
    $posLoad < PHP_INT_MAX && $posDelegate !== false && $posLoad < $posDelegate,
    'C2',
    'GiftCardService::redeemForInvoice calls loadCardForUpdate/findLockedInTenantScope before delegating to redeemGiftCard (tenant proof before transaction write)'
);

$consumePkgBody = $methodBody($pkgSvc, 'consumeForCompletedAppointment');
$posPkgLock     = strpos($consumePkgBody, 'findForUpdateInTenantScope');
$posUsageCreate = strpos($consumePkgBody, 'usages->create');
$t(
    $posPkgLock !== false && $posUsageCreate !== false && $posPkgLock < $posUsageCreate,
    'C3',
    'PackageService::consumeForCompletedAppointment calls findForUpdateInTenantScope before usages->create'
);

$t(
    !str_contains($memUsageRepo, 'OrganizationRepositoryScope'),
    'C4',
    'MembershipBenefitUsageRepository is FK-only by design (no OrganizationRepositoryScope — documented trust-upstream)'
);
$t(
    !str_contains($gcTransRepo, 'OrganizationRepositoryScope'),
    'C5',
    'GiftCardTransactionRepository is FK-only by design (no OrganizationRepositoryScope — documented trust-upstream)'
);
$t(
    str_contains($pkgUsageRepo, 'OrganizationRepositoryScope'),
    'C6',
    'PackageUsageRepository::find() uses OrganizationRepositoryScope for single-row reads (not pure FK-only)'
);

// ---------------------------------------------------------------------------
// Section D — Out-of-scope module route middleware coverage
// ---------------------------------------------------------------------------

$routePayroll = $read('routes/web/register_payroll.php');
$routeReports = $read('routes/web/register_reports.php');
$routeNotifs  = $read('routes/web/register_notifications.php');
$routeDocs    = $read('routes/web/register_documents.php');
$notifRepo    = $read('modules/notifications/repositories/NotificationRepository.php');
$payrollRepo  = $read('modules/payroll/repositories/PayrollRunRepository.php');
$reportRepo   = $read('modules/reports/repositories/ReportRepository.php');
$docRepo      = $read('modules/documents/repositories/DocumentRepository.php');

$t(
    str_contains($routePayroll, 'AuthMiddleware') && str_contains($routePayroll, 'TenantProtectedRouteMiddleware'),
    'D1',
    'Payroll routes: AuthMiddleware + TenantProtectedRouteMiddleware present (lifecycle enforcement)'
);
$t(
    str_contains($routeReports, 'AuthMiddleware') && str_contains($routeReports, 'TenantProtectedRouteMiddleware'),
    'D2',
    'Reports routes: AuthMiddleware + TenantProtectedRouteMiddleware present (lifecycle enforcement)'
);
$t(
    str_contains($routeNotifs, 'AuthMiddleware') && str_contains($routeNotifs, 'TenantProtectedRouteMiddleware'),
    'D3',
    'Notifications routes: AuthMiddleware + TenantProtectedRouteMiddleware present (lifecycle enforcement)'
);
$t(
    str_contains($routeDocs, 'AuthMiddleware') && str_contains($routeDocs, 'TenantProtectedRouteMiddleware'),
    'D4',
    'Documents routes: AuthMiddleware + TenantProtectedRouteMiddleware present (lifecycle enforcement)'
);
$t(
    str_contains($notifRepo, 'OrganizationRepositoryScope'),
    'D5',
    'NotificationRepository uses OrganizationRepositoryScope for tenant-scoped paths'
);
$t(
    str_contains($payrollRepo, 'branch_id') || str_contains($payrollRepo, 'organization_id') || str_contains($payrollRepo, 'payrollRunBranchOrgExistsClause'),
    'D6',
    'PayrollRunRepository has org/branch scope on read paths'
);
$t(
    str_contains($reportRepo, 'invoiceClause') || str_contains($reportRepo, 'branch_id') || str_contains($reportRepo, 'organization_id'),
    'D7',
    'ReportRepository has branch/org scope on report queries'
);
$t(
    str_contains($docRepo, 'organization_id') || str_contains($docRepo, 'branch_id') || str_contains($docRepo, 'OrganizationRepositoryScope'),
    'D8',
    'DocumentRepository has org/branch scope on document queries'
);

// ---------------------------------------------------------------------------
// Section E — Inventory deprecated method discipline
// ---------------------------------------------------------------------------

$productRepo   = $read('modules/inventory/repositories/ProductRepository.php');
$productSvc    = $read('modules/inventory/services/ProductService.php');
$invoiceSvc    = $read('modules/sales/services/InvoiceService.php');
$stockMoveRepo = $read('modules/inventory/repositories/StockMovementRepository.php');

$t(
    str_contains($productRepo, '@deprecated'),
    'E1',
    'ProductRepository has @deprecated markers on legacy unscoped methods'
);
$t(
    str_contains($productRepo, 'listInTenantScope') && str_contains($productRepo, 'findInTenantScope'),
    'E2',
    'ProductRepository has tenant-scoped variants (listInTenantScope, findInTenantScope)'
);
$t(
    str_contains($productSvc, 'findInTenantScope') || str_contains($productSvc, 'findLockedInTenantScope'),
    'E3',
    'ProductService uses tenant-scoped repository methods (findInTenantScope or findLockedInTenantScope)'
);
$t(
    str_contains($stockMoveRepo, 'existsSaleDeductionForInvoiceItem'),
    'E4',
    'StockMovementRepository::existsSaleDeductionForInvoiceItem() is present (reference_id-only; caller-discipline documented)'
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$total = $pass + $fail;
echo "\n";
if ($fail === 0) {
    echo "PASS: {$pass} / {$total} — verify_critical_integrity_fail_closed_boundary_01\n";
    exit(0);
} else {
    fwrite(STDERR, "FAIL: {$fail} failures / {$total} total\n");
    exit(1);
}
