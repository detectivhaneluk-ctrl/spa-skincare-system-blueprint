<?php

declare(strict_types=1);

/**
 * FOUNDATION-NO-NEW-PAGES / settings tenancy — Tier A read-only proof for `vat_rates` + `payment_methods` repositories
 * (`Modules\Sales\Repositories\VatRateRepository`, `PaymentMethodRepository`): org-gated global-null reads and
 * branch ∪ global-null overlay via {@see \Core\Organization\OrganizationRepositoryScope} settings helpers.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_settings_backed_vat_payment_tenant_scope_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$orgScope = (string) file_get_contents($system . '/core/Organization/OrganizationRepositoryScope.php');
$vat = (string) file_get_contents($system . '/modules/sales/repositories/VatRateRepository.php');
$pay = (string) file_get_contents($system . '/modules/sales/repositories/PaymentMethodRepository.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_sales_public_commerce_memberships_settings.php');

$checks['OrganizationRepositoryScope defines settings-backed catalog helpers'] = str_contains($orgScope, 'function settingsBackedCatalogGlobalNullBranchOrgAnchoredSql')
    && str_contains($orgScope, 'function settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause');

$checks['VatRateRepository wires OrganizationRepositoryScope + settings unions'] = str_contains($vat, 'OrganizationRepositoryScope')
    && str_contains($vat, 'settingsBackedCatalogGlobalNullBranchOrgAnchoredSql')
    && substr_count($vat, 'settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause') >= 4;

$checks['PaymentMethodRepository wires OrganizationRepositoryScope + settings unions'] = str_contains($pay, 'OrganizationRepositoryScope')
    && str_contains($pay, 'settingsBackedCatalogGlobalNullBranchOrgAnchoredSql')
    && substr_count($pay, 'settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause') >= 3;

$pmDi = 'new \Modules\Sales\Repositories\PaymentMethodRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))';
$vatDi = 'new \Modules\Sales\Repositories\VatRateRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))';
$checks['Bootstrap passes OrganizationRepositoryScope into VAT + payment method repos'] = str_contains($bootstrap, $pmDi)
    && str_contains($bootstrap, $vatDi);

$checks['No hand-rolled (branch_id IS NULL OR branch_id = ?) in VAT/payment repositories'] = !preg_match('/branch_id\s+IS\s+NULL\s+OR\s+branch_id\s*=/i', $vat)
    && !preg_match('/branch_id\s+IS\s+NULL\s+OR\s+branch_id\s*=/i', $pay);

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

exit(0);
