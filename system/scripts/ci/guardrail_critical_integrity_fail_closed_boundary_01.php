<?php
/**
 * CRITICAL-INTEGRITY-FAIL-CLOSED-BOUNDARY-AND-LIFECYCLE-CLOSURE-01
 * CI Guardrail
 *
 * Bans:
 *   G1-CF  GiftCardService::getCurrentBalance FK-only fast path regression
 *   G2-CP  PackageService::getRemainingSessions FK-only fast path regression
 *   G3-PL  Public suspension check removal (PublicBookingService / PublicCommerceService)
 *   G4-FK  FK-only child repo mutation callers missing parent tenant proof
 *   G5-DP  Deprecated inventory methods called from HTTP-facing service files
 *
 * Run: php system/scripts/ci/guardrail_critical_integrity_fail_closed_boundary_01.php
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
        return '';
    }
    return (string) file_get_contents($full);
};

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
// G1-CF: getCurrentBalance must call findInTenantScope before latestForCard
// ---------------------------------------------------------------------------

$giftSvc     = $read('modules/gift-cards/services/GiftCardService.php');
$balanceBody = $methodBody($giftSvc, 'getCurrentBalance');
$posScope    = strpos($balanceBody, 'findInTenantScope');
$posLatest   = strpos($balanceBody, '->latestForCard(');

$t(
    $balanceBody !== '' && $posScope !== false && $posLatest !== false && $posScope < $posLatest,
    'G1-CF',
    'GiftCardService::getCurrentBalance: findInTenantScope before latestForCard (FK-only fast path must not be re-introduced)'
);

// ---------------------------------------------------------------------------
// G2-CP: getRemainingSessions must call findInTenantScope before latestForClientPackage
// ---------------------------------------------------------------------------

$pkgSvc     = $read('modules/packages/services/PackageService.php');
$remainBody = $methodBody($pkgSvc, 'getRemainingSessions');
$posScopeR  = strpos($remainBody, 'findInTenantScope');
$posLatestR = strpos($remainBody, '->latestForClientPackage(');

$t(
    $remainBody !== '' && $posScopeR !== false && $posLatestR !== false && $posScopeR < $posLatestR,
    'G2-CP',
    'PackageService::getRemainingSessions: findInTenantScope before latestForClientPackage (FK-only fast path must not be re-introduced)'
);

// ---------------------------------------------------------------------------
// G3-PL: Public suspension checks must remain in place
// ---------------------------------------------------------------------------

$pubBook = $read('modules/online-booking/services/PublicBookingService.php');
$pubComm = $read('modules/public-commerce/services/PublicCommerceService.php');

$t(
    str_contains($pubBook, 'isBranchLinkedToSuspendedOrganization'),
    'G3-PL-1',
    'PublicBookingService: isBranchLinkedToSuspendedOrganization present (must not be removed)'
);
$t(
    str_contains($pubComm, 'isBranchLinkedToSuspendedOrganization'),
    'G3-PL-2',
    'PublicCommerceService: isBranchLinkedToSuspendedOrganization present (must not be removed)'
);

// ---------------------------------------------------------------------------
// G4-FK: FK-only child repo mutation callers must prove parent tenant ownership first
// ---------------------------------------------------------------------------

$memSvc      = $read('modules/memberships/services/MembershipService.php');
$consumeBody = $methodBody($memSvc, 'consumeBenefitForAppointment');

$t(
    str_contains($consumeBody, 'lockWithDefinitionInTenantScope')
        && strpos($consumeBody, 'lockWithDefinitionInTenantScope') < strpos($consumeBody, 'benefitUsages->insert'),
    'G4-FK-1',
    'MembershipService::consumeBenefitForAppointment: lockWithDefinitionInTenantScope before benefitUsages->insert'
);

$redeemBody = $methodBody($giftSvc, 'redeemForInvoice');
$posLoad    = PHP_INT_MAX;
foreach (['loadCardForUpdate', 'findLockedInTenantScope'] as $n) {
    $p = strpos($redeemBody, $n);
    if ($p !== false && $p < $posLoad) {
        $posLoad = $p;
    }
}
$posDelegate = strpos($redeemBody, 'redeemGiftCard(');
$t(
    $posLoad < PHP_INT_MAX && $posDelegate !== false && $posLoad < $posDelegate,
    'G4-FK-2',
    'GiftCardService::redeemForInvoice: tenant-scoped card lock before delegating to redeemGiftCard (tenant proof before transaction write)'
);

$consumePkgBody = $methodBody($pkgSvc, 'consumeForCompletedAppointment');
$t(
    str_contains($consumePkgBody, 'findForUpdateInTenantScope')
        && strpos($consumePkgBody, 'findForUpdateInTenantScope') < strpos($consumePkgBody, 'usages->create'),
    'G4-FK-3',
    'PackageService::consumeForCompletedAppointment: findForUpdateInTenantScope before usages->create'
);

// ---------------------------------------------------------------------------
// G5-DP: Deprecated inventory methods must not be the only DB path in ProductService
// ---------------------------------------------------------------------------

$productSvc = $read('modules/inventory/services/ProductService.php');

$t(
    str_contains($productSvc, 'findInTenantScope') || str_contains($productSvc, 'findLockedInTenantScope'),
    'G5-DP-1',
    'ProductService uses tenant-scoped find variants (findInTenantScope or findLockedInTenantScope)'
);

$invoiceSvc = $read('modules/sales/services/InvoiceService.php');
$t(
    str_contains($invoiceSvc, 'findReadableForStockMutation')
        || str_contains($invoiceSvc, 'findGlobalCatalogProduct')
        || str_contains($invoiceSvc, 'findForInvoiceProductLine'),
    'G5-DP-2',
    'InvoiceService uses tenant-scoped product resolution methods (findReadableForStockMutation / findGlobalCatalogProduct / findForInvoiceProductLine)'
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$total = $pass + $fail;
echo "\n";
if ($fail === 0) {
    echo "PASS: {$pass} / {$total} — guardrail_critical_integrity_fail_closed_boundary_01\n";
    exit(0);
} else {
    fwrite(STDERR, "FAIL: {$fail} failures / {$total} total\n");
    exit(1);
}
