<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Inventory\Repositories\InventoryCountRepository;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Repositories\StockMovementRepository;
use Modules\Inventory\Services\InventoryCountService;
use Modules\Inventory\Services\ProductService;
use Modules\Inventory\Services\StockMovementService;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$products = app(ProductRepository::class);
$movements = app(StockMovementRepository::class);
$counts = app(InventoryCountRepository::class);
$productService = app(ProductService::class);
$movementService = app(StockMovementService::class);
$countService = app(InventoryCountService::class);

$passed = 0;
$failed = 0;
function inv01Pass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function inv01Fail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }
function inv01ExpectThrows(callable $fn): bool { try { $fn(); return false; } catch (\Throwable) { return true; } }

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

$setScope = static function (int $branchId, int $orgId) use ($branchContext, $orgContext): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
};

$scopeA = $resolveScope('SMOKE_A');
$scopeC = $resolveScope('SMOKE_C');
$suffix = 'INV01_' . bin2hex(random_bytes(4));

// In-tenant (A) setup.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$productAId = $productService->create([
    'name' => $suffix . '_A',
    'sku' => $suffix . '_A_SKU',
    'product_type' => 'retail',
    'cost_price' => 5.0,
    'sell_price' => 9.0,
    'is_active' => 1,
    'reorder_level' => 0,
]);
$movementAId = $movementService->createManual([
    'product_id' => $productAId,
    'movement_type' => 'purchase_in',
    'quantity' => 10,
    'notes' => 'tenant A seed movement',
]);
$countA = $countService->create([
    'product_id' => $productAId,
    'counted_quantity' => 8,
    'notes' => 'tenant A count',
    'apply_adjustment' => 0,
]);
$countAId = (int) ($countA['count_id'] ?? 0);

// Foreign-tenant (C) setup.
$setScope($scopeC['branch_id'], $scopeC['organization_id']);
$productCId = $productService->create([
    'name' => $suffix . '_C',
    'sku' => $suffix . '_C_SKU',
    'product_type' => 'retail',
    'cost_price' => 7.0,
    'sell_price' => 13.0,
    'is_active' => 1,
    'reorder_level' => 0,
]);
$movementCId = $movementService->createManual([
    'product_id' => $productCId,
    'movement_type' => 'purchase_in',
    'quantity' => 6,
    'notes' => 'tenant C seed movement',
]);

// Back to tenant A assertions.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);

($products->findInTenantScope($productAId, $scopeA['branch_id']) !== null)
    ? inv01Pass('read_own_product_allowed')
    : inv01Fail('read_own_product_allowed', 'expected scoped product row');

($movements->findInTenantScope($movementAId, $scopeA['branch_id']) !== null)
    ? inv01Pass('read_own_movement_allowed')
    : inv01Fail('read_own_movement_allowed', 'expected scoped movement row');

($counts->findInTenantScope($countAId, $scopeA['branch_id']) !== null)
    ? inv01Pass('read_own_inventory_count_allowed')
    : inv01Fail('read_own_inventory_count_allowed', 'expected scoped count row');

($products->findInTenantScope($productCId, $scopeA['branch_id']) === null)
    ? inv01Pass('read_foreign_product_by_id_denied')
    : inv01Fail('read_foreign_product_by_id_denied', 'unexpected foreign product row');

($movements->findInTenantScope($movementCId, $scopeA['branch_id']) === null)
    ? inv01Pass('read_foreign_movement_by_id_denied')
    : inv01Fail('read_foreign_movement_by_id_denied', 'unexpected foreign movement row');

inv01ExpectThrows(static fn () => $productService->update($productCId, ['name' => $suffix . '_X']))
    ? inv01Pass('update_foreign_product_denied')
    : inv01Fail('update_foreign_product_denied', 'expected denial');

inv01ExpectThrows(static fn () => $productService->delete($productCId))
    ? inv01Pass('delete_foreign_product_denied')
    : inv01Fail('delete_foreign_product_denied', 'expected denial');

inv01ExpectThrows(static fn () => $movementService->createManual([
    'product_id' => $productCId,
    'movement_type' => 'manual_adjustment',
    'quantity' => 1,
]))
    ? inv01Pass('cross_tenant_stock_movement_denied')
    : inv01Fail('cross_tenant_stock_movement_denied', 'expected denial');

inv01ExpectThrows(static fn () => $countService->create([
    'product_id' => $productCId,
    'counted_quantity' => 3,
    'apply_adjustment' => 0,
]))
    ? inv01Pass('cross_tenant_inventory_count_denied')
    : inv01Fail('cross_tenant_inventory_count_denied', 'expected denial');

inv01ExpectThrows(static fn () => $movementService->createManual([
    'product_id' => $productCId,
    'movement_type' => 'count_adjustment',
    'quantity' => -2,
]))
    ? inv01Pass('cross_tenant_stock_adjustment_denied')
    : inv01Fail('cross_tenant_stock_adjustment_denied', 'expected denial');

try {
    $productService->update($productAId, ['name' => $suffix . '_A_UPDATED']);
    inv01Pass('in_tenant_product_update_still_works');
} catch (\Throwable $e) {
    inv01Fail('in_tenant_product_update_still_works', $e->getMessage());
}

try {
    $movementService->createManual([
        'product_id' => $productAId,
        'movement_type' => 'manual_adjustment',
        'quantity' => 2,
        'notes' => 'in-tenant update',
    ]);
    inv01Pass('in_tenant_stock_movement_still_works');
} catch (\Throwable $e) {
    inv01Fail('in_tenant_stock_movement_still_works', $e->getMessage());
}

try {
    $list = $products->listInTenantScope([], $scopeA['branch_id'], 100, 0);
    $movementList = $movements->listInTenantScope([], $scopeA['branch_id'], 200, 0);
    $countList = $counts->listInTenantScope([], $scopeA['branch_id'], 200, 0);
    $hasAProduct = false;
    foreach ($list as $row) {
        if ((int) ($row['id'] ?? 0) === $productAId) {
            $hasAProduct = true;
            break;
        }
    }
    $hasForeignMovement = false;
    foreach ($movementList as $row) {
        if ((int) ($row['id'] ?? 0) === $movementCId) {
            $hasForeignMovement = true;
            break;
        }
    }
    $hasACount = false;
    foreach ($countList as $row) {
        if ((int) ($row['id'] ?? 0) === $countAId) {
            $hasACount = true;
            break;
        }
    }
    $hasAProduct ? inv01Pass('tenant_list_products_scoped') : inv01Fail('tenant_list_products_scoped', 'missing own product in scoped list');
    !$hasForeignMovement ? inv01Pass('tenant_list_movements_scoped') : inv01Fail('tenant_list_movements_scoped', 'foreign movement leaked into scoped list');
    $hasACount ? inv01Pass('tenant_list_counts_scoped') : inv01Fail('tenant_list_counts_scoped', 'missing own count in scoped list');
} catch (\Throwable $e) {
    inv01Fail('tenant_scoped_list_queries', $e->getMessage());
}

// Unresolved context must fail closed for protected tenant-scoped reads/writes.
$branchContext->setCurrentBranchId(null);
$orgContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);
inv01ExpectThrows(static fn () => $products->listInTenantScope([], $scopeA['branch_id'], 10, 0))
    ? inv01Pass('unresolved_context_repo_scope_fail_closed')
    : inv01Fail('unresolved_context_repo_scope_fail_closed', 'expected scoped repo denial');
inv01ExpectThrows(static fn () => $movementService->createManual([
    'product_id' => $productAId,
    'movement_type' => 'manual_adjustment',
    'quantity' => 1,
]))
    ? inv01Pass('unresolved_context_write_fail_closed')
    : inv01Fail('unresolved_context_write_fail_closed', 'expected service denial');

// Relevant regression checks: tenant-entry and branch-eligibility behavior still fail closed.
$allowedNoContext = app(\Core\Branch\TenantBranchAccessService::class)->allowedBranchIdsForUser(0);
($allowedNoContext === [])
    ? inv01Pass('regression_tenant_branch_access_invalid_user_still_empty')
    : inv01Fail('regression_tenant_branch_access_invalid_user_still_empty', json_encode($allowedNoContext));
$entryNoUser = app(\Modules\Auth\Services\TenantEntryResolverService::class)->resolveForUser(0);
(($entryNoUser['state'] ?? '') === 'none')
    ? inv01Pass('regression_tenant_entry_invalid_user_still_none')
    : inv01Fail('regression_tenant_entry_invalid_user_still_none', json_encode($entryNoUser));

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);

