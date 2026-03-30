<?php

declare(strict_types=1);

/**
 * PLT-LC-01 — Static proof: legacy (no membership pivot) pinned-branch resolution
 * must not treat organizations with suspended_at as operable tenant targets.
 *
 * Run: php system/scripts/read-only/verify_tenant_branch_access_legacy_suspended_org_plt_lc_01.php
 */

$systemPath = dirname(__DIR__, 2);
$tba = (string) file_get_contents($systemPath . '/core/Branch/TenantBranchAccessService.php');
if ($tba === '') {
    fwrite(STDERR, "FAIL: TenantBranchAccessService.php unreadable\n");
    exit(1);
}

$ok = true;
$fail = static function (string $m) use (&$ok): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    $ok = false;
};

if (!str_contains($tba, 'function legacyPinnedBranchInActiveOrganization')) {
    $fail('TenantBranchAccessService missing legacyPinnedBranchInActiveOrganization');
}
if (!str_contains($tba, 'legacyPinnedBranchInActiveOrganization($userBranchId)')) {
    $fail('allowedBranchIdsForUser legacy path must call legacyPinnedBranchInActiveOrganization');
}
if (substr_count($tba, 'legacyPinnedBranchInActiveOrganization') < 3) {
    $fail('expected legacyPinnedBranchInActiveOrganization used in allowed + default legacy paths');
}
$legacyBlock = $tba;
if (preg_match('/private function legacyPinnedBranchInActiveOrganization[\s\S]*?\n    \}/', $tba, $m)) {
    $legacyBlock = $m[0];
}
if (!str_contains($legacyBlock, 'o.suspended_at IS NULL')) {
    $fail('legacyPinnedBranchInActiveOrganization must require o.suspended_at IS NULL');
}

$bcc = (string) file_get_contents($systemPath . '/modules/auth/controllers/BranchContextController.php');
if ($bcc === '') {
    $fail('BranchContextController.php unreadable');
} elseif (!str_contains($bcc, 'OrganizationLifecycleGate::class')) {
    $fail('BranchContextController must resolve OrganizationLifecycleGate');
} elseif (!str_contains($bcc, 'isBranchLinkedToSuspendedOrganization')) {
    $fail('BranchContextController must call isBranchLinkedToSuspendedOrganization');
} elseif (!str_contains($bcc, 'TENANT_ORGANIZATION_SUSPENDED')) {
    $fail('BranchContextController must surface TENANT_ORGANIZATION_SUSPENDED for JSON');
}

if (!$ok) {
    exit(1);
}

echo "PASS: verify_tenant_branch_access_legacy_suspended_org_plt_lc_01\n";
exit(0);
