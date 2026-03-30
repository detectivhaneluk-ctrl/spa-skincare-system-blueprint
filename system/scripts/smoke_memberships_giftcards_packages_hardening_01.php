<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Clients\Services\ClientService;
use Modules\GiftCards\Repositories\GiftCardRepository;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Memberships\Repositories\ClientMembershipRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Packages\Repositories\ClientPackageRepository;
use Modules\Packages\Repositories\PackageRepository;
use Modules\Packages\Services\PackageService;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$clientService = app(ClientService::class);
$memDefRepo = app(MembershipDefinitionRepository::class);
$clientMemRepo = app(ClientMembershipRepository::class);
$giftRepo = app(GiftCardRepository::class);
$giftService = app(GiftCardService::class);
$pkgRepo = app(PackageRepository::class);
$clientPkgRepo = app(ClientPackageRepository::class);
$packageService = app(PackageService::class);

$passed = 0;
$failed = 0;
function mgpPass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function mgpFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }
function mgpExpectThrows(callable $fn): bool { try { $fn(); return false; } catch (\Throwable) { return true; } }

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
$sfx = 'MGP01_' . bin2hex(random_bytes(3));
$today = date('Y-m-d');
$ends = date('Y-m-d', strtotime($today . ' +30 days'));

// Tenant A fixtures
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$clientAId = $clientService->create(['first_name' => $sfx, 'last_name' => 'ClientA']);

$db->insert('membership_definitions', [
    'branch_id' => $scopeA['branch_id'],
    'name' => $sfx . '_MemDefA',
    'description' => null,
    'duration_days' => 30,
    'price' => 10.0,
    'billing_enabled' => 0,
    'status' => 'active',
    'public_online_eligible' => 0,
]);
$memDefAId = (int) $db->lastInsertId();

$db->insert('client_memberships', [
    'client_id' => $clientAId,
    'membership_definition_id' => $memDefAId,
    'branch_id' => $scopeA['branch_id'],
    'starts_at' => $today,
    'ends_at' => $ends,
    'status' => 'active',
    'billing_state' => 'inactive',
    'billing_auto_renew_enabled' => 1,
    'cancel_at_period_end' => 0,
]);
$cmAId = (int) $db->lastInsertId();

$db->insert('gift_cards', [
    'branch_id' => $scopeA['branch_id'],
    'client_id' => $clientAId,
    'code' => $sfx . '_GCA',
    'original_amount' => 25.0,
    'currency' => 'USD',
    'issued_at' => date('Y-m-d H:i:s'),
    'expires_at' => null,
    'status' => 'active',
]);
$gcAId = (int) $db->lastInsertId();
$db->insert('gift_card_transactions', [
    'gift_card_id' => $gcAId,
    'branch_id' => $scopeA['branch_id'],
    'type' => 'issue',
    'amount' => 25.0,
    'balance_after' => 25.0,
]);

$db->insert('packages', [
    'branch_id' => $scopeA['branch_id'],
    'name' => $sfx . '_PkgA',
    'description' => null,
    'status' => 'active',
    'total_sessions' => 5,
    'validity_days' => null,
    'price' => 100.0,
    'public_online_eligible' => 0,
]);
$pkgAId = (int) $db->lastInsertId();

$db->insert('client_packages', [
    'package_id' => $pkgAId,
    'client_id' => $clientAId,
    'branch_id' => $scopeA['branch_id'],
    'assigned_sessions' => 5,
    'remaining_sessions' => 5,
    'assigned_at' => date('Y-m-d H:i:s'),
    'status' => 'active',
]);
$cpAId = (int) $db->lastInsertId();
$db->insert('package_usages', [
    'client_package_id' => $cpAId,
    'branch_id' => $scopeA['branch_id'],
    'usage_type' => 'adjustment',
    'quantity' => 5,
    'remaining_after' => 5,
    'reference_type' => 'assignment',
    'reference_id' => $cpAId,
]);

// Tenant C fixtures
$setScope($scopeC['branch_id'], $scopeC['organization_id']);
$clientCId = $clientService->create(['first_name' => $sfx, 'last_name' => 'ClientC']);

$db->insert('membership_definitions', [
    'branch_id' => $scopeC['branch_id'],
    'name' => $sfx . '_MemDefC',
    'description' => null,
    'duration_days' => 30,
    'price' => 12.0,
    'billing_enabled' => 0,
    'status' => 'active',
    'public_online_eligible' => 0,
]);
$memDefCId = (int) $db->lastInsertId();

$db->insert('client_memberships', [
    'client_id' => $clientCId,
    'membership_definition_id' => $memDefCId,
    'branch_id' => $scopeC['branch_id'],
    'starts_at' => $today,
    'ends_at' => $ends,
    'status' => 'active',
    'billing_state' => 'inactive',
    'billing_auto_renew_enabled' => 1,
    'cancel_at_period_end' => 0,
]);
$cmCId = (int) $db->lastInsertId();

$db->insert('gift_cards', [
    'branch_id' => $scopeC['branch_id'],
    'client_id' => $clientCId,
    'code' => $sfx . '_GCC',
    'original_amount' => 40.0,
    'currency' => 'USD',
    'issued_at' => date('Y-m-d H:i:s'),
    'expires_at' => null,
    'status' => 'active',
]);
$gcCId = (int) $db->lastInsertId();
$db->insert('gift_card_transactions', [
    'gift_card_id' => $gcCId,
    'branch_id' => $scopeC['branch_id'],
    'type' => 'issue',
    'amount' => 40.0,
    'balance_after' => 40.0,
]);

$db->insert('packages', [
    'branch_id' => $scopeC['branch_id'],
    'name' => $sfx . '_PkgC',
    'description' => null,
    'status' => 'active',
    'total_sessions' => 3,
    'validity_days' => null,
    'price' => 80.0,
    'public_online_eligible' => 0,
]);
$pkgCId = (int) $db->lastInsertId();

$db->insert('client_packages', [
    'package_id' => $pkgCId,
    'client_id' => $clientCId,
    'branch_id' => $scopeC['branch_id'],
    'assigned_sessions' => 3,
    'remaining_sessions' => 3,
    'assigned_at' => date('Y-m-d H:i:s'),
    'status' => 'active',
]);
$cpCId = (int) $db->lastInsertId();
$db->insert('package_usages', [
    'client_package_id' => $cpCId,
    'branch_id' => $scopeC['branch_id'],
    'usage_type' => 'adjustment',
    'quantity' => 3,
    'remaining_after' => 3,
    'reference_type' => 'assignment',
    'reference_id' => $cpCId,
]);

// Assertions: tenant A scope
$setScope($scopeA['branch_id'], $scopeA['organization_id']);

($memDefRepo->findInTenantScope($memDefAId, $scopeA['branch_id']) !== null)
    ? mgpPass('membership_definition_read_own_scoped')
    : mgpFail('membership_definition_read_own_scoped', 'expected row');
($memDefRepo->findInTenantScope($memDefCId, $scopeA['branch_id']) === null)
    ? mgpPass('membership_definition_foreign_id_denied')
    : mgpFail('membership_definition_foreign_id_denied', 'unexpected foreign row');

($clientMemRepo->findInTenantScope($cmAId, $scopeA['branch_id']) !== null)
    ? mgpPass('client_membership_read_own_scoped')
    : mgpFail('client_membership_read_own_scoped', 'expected row');
($clientMemRepo->findInTenantScope($cmCId, $scopeA['branch_id']) === null)
    ? mgpPass('client_membership_foreign_id_denied')
    : mgpFail('client_membership_foreign_id_denied', 'unexpected foreign row');

($giftRepo->findInTenantScope($gcAId, $scopeA['branch_id']) !== null)
    ? mgpPass('gift_card_read_own_scoped')
    : mgpFail('gift_card_read_own_scoped', 'expected row');
($giftRepo->findInTenantScope($gcCId, $scopeA['branch_id']) === null)
    ? mgpPass('gift_card_foreign_id_denied')
    : mgpFail('gift_card_foreign_id_denied', 'unexpected foreign row');

($pkgRepo->findInTenantScope($pkgAId, $scopeA['branch_id']) !== null)
    ? mgpPass('package_read_own_scoped')
    : mgpFail('package_read_own_scoped', 'expected row');
($pkgRepo->findInTenantScope($pkgCId, $scopeA['branch_id']) === null)
    ? mgpPass('package_foreign_id_denied')
    : mgpFail('package_foreign_id_denied', 'unexpected foreign row');

($clientPkgRepo->findInTenantScope($cpAId, $scopeA['branch_id']) !== null)
    ? mgpPass('client_package_read_own_scoped')
    : mgpFail('client_package_read_own_scoped', 'expected row');
($clientPkgRepo->findInTenantScope($cpCId, $scopeA['branch_id']) === null)
    ? mgpPass('client_package_foreign_id_denied')
    : mgpFail('client_package_foreign_id_denied', 'unexpected foreign row');

mgpExpectThrows(static fn () => $giftService->redeemGiftCard($gcCId, 1.0, ['branch_id' => $scopeA['branch_id']]))
    ? mgpPass('gift_card_cross_tenant_redeem_denied')
    : mgpFail('gift_card_cross_tenant_redeem_denied', 'expected failure');

mgpExpectThrows(static fn () => $packageService->usePackageSession($cpCId, 1, ['branch_id' => $scopeA['branch_id']]))
    ? mgpPass('package_cross_tenant_use_denied')
    : mgpFail('package_cross_tenant_use_denied', 'expected failure');

try {
    $packageService->usePackageSession($cpAId, 1, ['branch_id' => $scopeA['branch_id']]);
    mgpPass('package_in_tenant_use_still_works');
} catch (\Throwable $e) {
    mgpFail('package_in_tenant_use_still_works', $e->getMessage());
}

try {
    $giftService->redeemGiftCard($gcAId, 1.0, ['branch_id' => $scopeA['branch_id']]);
    mgpPass('gift_card_in_tenant_redeem_still_works');
} catch (\Throwable $e) {
    mgpFail('gift_card_in_tenant_redeem_still_works', $e->getMessage());
}

$branchContext->setCurrentBranchId(null);
$orgContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);
mgpExpectThrows(static fn () => $giftRepo->listInTenantScope([], $scopeA['branch_id'], 5, 0))
    ? mgpPass('unresolved_org_context_gift_cards_fail_closed')
    : mgpFail('unresolved_org_context_gift_cards_fail_closed', 'expected fail-closed exception');

$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$allowedNoContext = app(\Core\Branch\TenantBranchAccessService::class)->allowedBranchIdsForUser(0);
($allowedNoContext === [])
    ? mgpPass('regression_tenant_branch_access_invalid_user_still_empty')
    : mgpFail('regression_tenant_branch_access_invalid_user_still_empty', json_encode($allowedNoContext));

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
