<?php

declare(strict_types=1);

/**
 * INVOICE-SEQUENCE-PHASE-2 + PLT-TNT-01 CLOSURE-15 (**FND-TNT-26**) — static proof of per-organization sequence contract
 * and branch-derived org basis aligned with {@see \Modules\Sales\Services\SalesTenantScope::invoiceClause()}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_invoice_number_sequence_hotspot_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$repoPath = $system . '/modules/sales/repositories/InvoiceRepository.php';
$svcPath = $system . '/modules/sales/services/InvoiceService.php';
$bootPath = $system . '/modules/bootstrap/register_sales_public_commerce_memberships_settings.php';
$scopePath = $system . '/core/Organization/OrganizationRepositoryScope.php';
$tenantScopePath = $system . '/modules/sales/services/SalesTenantScope.php';
$m027 = $system . '/data/migrations/027_create_invoices_table.sql';
$m043 = $system . '/data/migrations/043_payment_refunds_and_invoice_sequence.sql';
$m116 = $system . '/data/migrations/116_invoice_number_sequence_per_organization.sql';

$repo = (string) file_get_contents($repoPath);
$svc = (string) file_get_contents($svcPath);
$boot = (string) file_get_contents($bootPath);
$scope = (string) file_get_contents($scopePath);
$tenantScopeFile = (string) file_get_contents($tenantScopePath);
$sql027 = (string) file_get_contents($m027);
$sql043 = (string) file_get_contents($m043);
$sql116 = (string) file_get_contents($m116);

$checks = [];

$checks['Migration 027: invoices.uk_invoices_number (global unique invoice_number)'] = str_contains($sql027, 'uk_invoices_number')
    && str_contains($sql027, 'invoice_number');

$checks['Migration 043: invoice_number_sequences created + legacy invoice seed'] = str_contains($sql043, 'CREATE TABLE invoice_number_sequences')
    && str_contains($sql043, "'invoice'");

$checks['Migration 116: organization_id + composite PK on invoice_number_sequences'] = str_contains($sql116, 'organization_id')
    && str_contains($sql116, 'DROP PRIMARY KEY')
    && str_contains($sql116, 'PRIMARY KEY (organization_id, sequence_key)');

$checks['DI: InvoiceRepository wired with Database + SalesTenantScope only (org via tenant scope)'] =
    str_contains($boot, 'InvoiceRepository::class')
    && str_contains($boot, 'new \\Modules\\Sales\\Repositories\\InvoiceRepository($c->get(\\Core\\App\\Database::class), $c->get(\\Modules\\Sales\\Services\\SalesTenantScope::class))');

$checks['OrganizationRepositoryScope: requireBranchDerivedOrganizationIdForDataPlane delegates to branch-derived guard'] =
    str_contains($scope, 'function requireBranchDerivedOrganizationIdForDataPlane')
    && str_contains($scope, 'return $this->requireTenantProtectedBranchDerivedOrganizationId();');

$checks['SalesTenantScope: requireBranchDerivedOrganizationIdForInvoicePlane delegates to OrganizationRepositoryScope'] =
    str_contains($tenantScopeFile, 'function requireBranchDerivedOrganizationIdForInvoicePlane')
    && str_contains($tenantScopeFile, 'return $this->organizationScope->requireBranchDerivedOrganizationIdForDataPlane();');

$checks['InvoiceRepository: allocator locks organization_id + sequence_key (FOR UPDATE), not global invoice row only'] =
    str_contains($repo, 'WHERE organization_id = ? AND sequence_key = ?')
    && str_contains($repo, 'FOR UPDATE')
    && str_contains($repo, 'allocateNextInvoiceNumber');

$checks['InvoiceRepository: no SEQUENCE_KEY_INVOICE_GLOBAL (removed global-only path)'] = !str_contains($repo, 'SEQUENCE_KEY_INVOICE_GLOBAL');

$checks['InvoiceRepository: seed scoped by branches.organization_id (no global invoice scan)'] =
    str_contains($repo, 'b.organization_id = ?')
    && str_contains($repo, 'INNER JOIN branches b ON b.id = i.branch_id')
    && !str_contains($repo, 'SUBSTRING(invoice_number, 5)');

$checks['InvoiceRepository: new stored format ORG{id}-INV-########'] =
    str_contains($repo, "'ORG'")
    && str_contains($repo, "'-INV-'")
    && str_contains($repo, 'str_pad');

$checks['InvoiceRepository: branch-derived org required before allocate (aligned with invoiceClause)'] =
    str_contains($repo, 'requireBranchDerivedOrganizationIdForInvoicePlane()')
    && !str_contains($repo, 'assertProtectedTenantContextResolved()');

$checks['InvoiceService::create allocates via repo when invoice_number absent'] = preg_match(
    '/function\s+create\s*\([\s\S]*?allocateNextInvoiceNumber\s*\(\s*\)/',
    $svc
) === 1;

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Invoice per-organization sequence static contract checks passed.' . PHP_EOL;
exit(0);
