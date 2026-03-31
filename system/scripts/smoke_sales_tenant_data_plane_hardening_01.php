<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Clients\Services\ClientService;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Repositories\PaymentRepository;
use Modules\Sales\Repositories\RegisterSessionRepository;
use Modules\Sales\Services\InvoiceService;
use Modules\Sales\Services\PaymentService;
use Modules\Sales\Services\RegisterSessionService;
use Modules\Sales\Services\SalesTenantScope;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(\Core\Kernel\RequestContextHolder::class);
$tenantScope = app(SalesTenantScope::class);
$invoiceRepo = app(InvoiceRepository::class);
$paymentRepo = app(PaymentRepository::class);
$registerRepo = app(RegisterSessionRepository::class);
$invoiceService = app(InvoiceService::class);
$paymentService = app(PaymentService::class);
$registerService = app(RegisterSessionService::class);
$clientService = app(ClientService::class);
$clientRepo = app(\Modules\Clients\Repositories\ClientRepository::class);

$passed = 0;
$failed = 0;
function salesPass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function salesFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }
function salesExpectThrows(callable $fn): bool { try { $fn(); return false; } catch (\Throwable) { return true; } }

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
        throw new RuntimeException('Missing branch: ' . $branchCode);
    }

    return ['branch_id' => (int) $row['branch_id'], 'organization_id' => (int) $row['organization_id']];
};

$setScope = static function (int $branchId, int $orgId) use ($branchContext, $orgContext, $contextHolder): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
    $contextHolder->set(\Core\Kernel\TenantContext::resolvedTenant(
        actorId: 1,
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
$scopeC = $resolveScope('SMOKE_C');
$now = date('Y-m-d H:i:s');

// Create tenant A fixtures.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$invoiceAId = $invoiceRepo->create([
    'invoice_number' => 'SALES-A-' . time() . '-' . random_int(1000, 9999),
    'branch_id' => $scopeA['branch_id'],
    'status' => 'open',
    'currency' => 'USD',
    'subtotal_amount' => 200.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'total_amount' => 200.00,
    'paid_amount' => 0.00,
    'issued_at' => $now,
    'created_by' => 1,
    'updated_by' => 1,
]);
$paymentAId = $paymentRepo->create([
    'invoice_id' => $invoiceAId,
    'entry_type' => 'payment',
    'payment_method' => 'cash',
    'amount' => 10.00,
    'currency' => 'USD',
    'status' => 'completed',
    'paid_at' => $now,
    'notes' => 'sales scope smoke own',
    'created_by' => 1,
]);
$registerAId = $registerRepo->create([
    'branch_id' => $scopeA['branch_id'],
    'opened_by' => 1,
    'opened_at' => $now,
    'opening_cash_amount' => 100.00,
    'status' => 'open',
]);
$clientAId = $clientService->create(['first_name' => 'Sales', 'last_name' => 'Scope A']);

// Create tenant C fixtures.
$setScope($scopeC['branch_id'], $scopeC['organization_id']);
$invoiceCId = $invoiceRepo->create([
    'invoice_number' => 'SALES-C-' . time() . '-' . random_int(1000, 9999),
    'branch_id' => $scopeC['branch_id'],
    'status' => 'open',
    'currency' => 'USD',
    'subtotal_amount' => 175.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'total_amount' => 175.00,
    'paid_amount' => 0.00,
    'issued_at' => $now,
    'created_by' => 1,
    'updated_by' => 1,
]);
$paymentCId = $paymentRepo->create([
    'invoice_id' => $invoiceCId,
    'entry_type' => 'payment',
    'payment_method' => 'cash',
    'amount' => 12.00,
    'currency' => 'USD',
    'status' => 'completed',
    'paid_at' => $now,
    'notes' => 'sales scope smoke foreign',
    'created_by' => 1,
]);
$registerCId = $registerRepo->create([
    'branch_id' => $scopeC['branch_id'],
    'opened_by' => 1,
    'opened_at' => $now,
    'opening_cash_amount' => 90.00,
    'status' => 'open',
]);
$clientCId = $clientService->create(['first_name' => 'Sales', 'last_name' => 'Scope C']);

// Back to tenant A assertions.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);

($invoiceRepo->find($invoiceAId) !== null && count($invoiceRepo->list(['branch_id' => $scopeA['branch_id']], 50, 0)) > 0)
    ? salesPass('tenant_can_read_own_invoices')
    : salesFail('tenant_can_read_own_invoices', 'missing own invoice read/list access');
($paymentRepo->findInInvoicePlane($paymentAId) !== null && count($paymentRepo->listByInvoiceIdInInvoicePlane($invoiceAId)) > 0)
    ? salesPass('tenant_can_read_own_payments')
    : salesFail('tenant_can_read_own_payments', 'missing own payment read access');
($registerRepo->find($registerAId) !== null && count($registerRepo->listRecent($scopeA['branch_id'], 20, 0)) > 0)
    ? salesPass('tenant_can_read_own_register_sessions')
    : salesFail('tenant_can_read_own_register_sessions', 'missing own register read access');

($invoiceRepo->find($invoiceCId) === null) ? salesPass('foreign_invoice_by_id_denied') : salesFail('foreign_invoice_by_id_denied', 'expected null');
($paymentRepo->findInInvoicePlane($paymentCId) === null) ? salesPass('foreign_payment_by_id_denied') : salesFail('foreign_payment_by_id_denied', 'expected null');
($registerRepo->find($registerCId) === null) ? salesPass('foreign_register_by_id_denied') : salesFail('foreign_register_by_id_denied', 'expected null');

salesExpectThrows(static fn () => $invoiceService->cancel($invoiceCId))
    ? salesPass('mutate_foreign_invoice_denied')
    : salesFail('mutate_foreign_invoice_denied', 'expected throw');
salesExpectThrows(static fn () => $paymentService->refund($paymentCId, 1.0, 'cross-tenant refund should fail'))
    ? salesPass('mutate_foreign_payment_denied')
    : salesFail('mutate_foreign_payment_denied', 'expected throw');
salesExpectThrows(static fn () => $registerService->closeSession($registerCId, 90.0, 'cross-tenant close should fail'))
    ? salesPass('mutate_foreign_register_denied')
    : salesFail('mutate_foreign_register_denied', 'expected throw');

salesExpectThrows(static fn () => $paymentService->create([
    'invoice_id' => $invoiceCId,
    'payment_method' => 'cash',
    'amount' => 5.0,
    'status' => 'completed',
]))
    ? salesPass('cross_tenant_payment_application_denied')
    : salesFail('cross_tenant_payment_application_denied', 'expected throw');

try {
    $paymentService->create([
        'invoice_id' => $invoiceAId,
        'payment_method' => 'cash',
        'amount' => 25.0,
        'status' => 'completed',
        'notes' => 'valid in-tenant payment',
    ]);
    $invoiceService->update($invoiceAId, [
        'items' => [
            [
                'item_type' => 'manual',
                'description' => 'Scope-safe in-tenant update',
                'quantity' => 1,
                'unit_price' => 200.0,
                'discount_amount' => 0.0,
                'tax_rate' => 0.0,
            ],
        ],
        'status' => 'open',
        'discount_amount' => 0.0,
        'tax_amount' => 0.0,
    ]);
    salesPass('valid_in_tenant_sales_read_write_paths_work');
} catch (\Throwable $e) {
    salesFail('valid_in_tenant_sales_read_write_paths_work', $e->getMessage());
}

$branchContext->setCurrentBranchId(null);
$orgContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);
salesExpectThrows(static fn () => $tenantScope->assertProtectedTenantContextResolved())
    ? salesPass('unresolved_tenant_context_fails_closed_for_protected_sales_runtime')
    : salesFail('unresolved_tenant_context_fails_closed_for_protected_sales_runtime', 'expected throw');

// Relevant boundary regression: foreign tenant client remains inaccessible.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
($clientRepo->find($clientCId) === null)
    ? salesPass('regression_foreign_client_boundary_still_denied')
    : salesFail('regression_foreign_client_boundary_still_denied', 'expected null');
($clientRepo->find($clientAId) !== null)
    ? salesPass('regression_own_client_boundary_still_allowed')
    : salesFail('regression_own_client_boundary_still_allowed', 'expected own client');

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
