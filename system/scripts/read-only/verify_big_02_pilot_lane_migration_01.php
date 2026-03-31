<?php

/**
 * BIG-02 / FOUNDATION-A3+A4+A5 Pilot Lane Migration — Verification Script
 *
 * Proves:
 * 1. Services no longer contain direct db->fetchOne / fetchAll / query calls for protected operations.
 * 2. Canonical TenantContext-scoped repository methods are installed and match required semantics.
 * 3. Services consume TenantContext from RequestContextHolder (no BranchContext dependency).
 * 4. Canonical scoped methods fail closed when TenantContext is unresolved.
 * 5. Accepted purge / ref-count behavior is preserved.
 * 6. No new raw id-only repository path is introduced for protected operations.
 *
 * Self-contained: no PHPUnit, no live DB. Uses PHP reflection and stub objects.
 */

declare(strict_types=1);

define('SYSTEM_ROOT', dirname(__DIR__, 2));
require_once __DIR__ . '/../../bootstrap.php';

// ---------------------------------------------------------------------------
// Lightweight assertion helpers
// ---------------------------------------------------------------------------
$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$label}\n";
        ++$passed;
    } else {
        echo "[FAIL] {$label}\n";
        ++$failed;
    }
}

function assert_false(bool $condition, string $label): void
{
    assert_true(!$condition, $label);
}

function assert_throws(callable $fn, string $exceptionClass, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "[FAIL] {$label} (no exception thrown)\n";
        ++$failed;
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            echo "[PASS] {$label}\n";
            ++$passed;
        } else {
            echo "[FAIL] {$label} (threw " . get_class($e) . ": {$e->getMessage()})\n";
            ++$failed;
        }
    }
}

function assert_not_throws(callable $fn, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "[PASS] {$label}\n";
        ++$passed;
    } catch (\Throwable $e) {
        echo "[FAIL] {$label} (threw " . get_class($e) . ": {$e->getMessage()})\n";
        ++$failed;
    }
}

// ---------------------------------------------------------------------------
// Section 1: FOUNDATION-A3 — Service layer must NOT contain direct DB data calls
// ---------------------------------------------------------------------------
echo "\n--- FOUNDATION-A3: Service layer direct DB ban ---\n";

$gcTemplateServiceSrc = file_get_contents(SYSTEM_ROOT . '/modules/marketing/services/MarketingGiftCardTemplateService.php');
$clientImageServiceSrc = file_get_contents(SYSTEM_ROOT . '/modules/clients/services/ClientProfileImageService.php');

assert_false(
    (bool) preg_match('/\$this->db->(fetchOne|fetchAll|query)\s*\(/', $gcTemplateServiceSrc),
    'MarketingGiftCardTemplateService: no direct db->fetchOne/fetchAll/query for data'
);

assert_false(
    (bool) preg_match('/\$this->db->(fetchOne|fetchAll|query)\s*\(/', $clientImageServiceSrc),
    'ClientProfileImageService: no direct db->fetchOne/fetchAll/query for data'
);

assert_true(
    (bool) preg_match('/\$this->db->connection\(\)/', $gcTemplateServiceSrc),
    'MarketingGiftCardTemplateService: retains db->connection() for transaction management only'
);

assert_true(
    (bool) preg_match('/\$this->db->connection\(\)/', $clientImageServiceSrc),
    'ClientProfileImageService: retains db->connection() for transaction management only'
);

// ---------------------------------------------------------------------------
// Section 2: FOUNDATION-A3 — No BranchContext injection in pilot services
// ---------------------------------------------------------------------------
echo "\n--- FOUNDATION-A3: BranchContext removed from pilot services ---\n";

assert_false(
    (bool) preg_match('/^use Core\\\\Branch\\\\BranchContext/m', $gcTemplateServiceSrc),
    'MarketingGiftCardTemplateService: no BranchContext dependency'
);

assert_false(
    (bool) preg_match('/^use Core\\\\Branch\\\\BranchContext/m', $clientImageServiceSrc),
    'ClientProfileImageService: no BranchContext dependency'
);

assert_true(
    str_contains($gcTemplateServiceSrc, 'RequestContextHolder'),
    'MarketingGiftCardTemplateService: uses RequestContextHolder for TenantContext'
);

assert_true(
    str_contains($clientImageServiceSrc, 'RequestContextHolder'),
    'ClientProfileImageService: uses RequestContextHolder for TenantContext'
);

// ---------------------------------------------------------------------------
// Section 3: FOUNDATION-A4 — Canonical scoped API installed on repositories
// ---------------------------------------------------------------------------
echo "\n--- FOUNDATION-A4: Canonical TenantContext-scoped API installed ---\n";

$gcRepoSrc = file_get_contents(SYSTEM_ROOT . '/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php');
$clientRepoSrc = file_get_contents(SYSTEM_ROOT . '/modules/clients/repositories/ClientProfileImageRepository.php');

// MarketingGiftCardTemplateRepository canonical methods
$gcCanonicalMethods = [
    'loadVisibleTemplate',
    'loadVisibleImage',
    'loadSelectableImageForTemplate',
    'loadUploadedMediaAssetInScope',
    'mutateUpdateTemplate',
    'mutateArchiveTemplate',
    'deleteOwnedImage',
    'clearArchivedTemplateImageRef',
    'countOwnedMediaAssetReferences',
];
foreach ($gcCanonicalMethods as $method) {
    assert_true(
        str_contains($gcRepoSrc, "public function {$method}("),
        "MarketingGiftCardTemplateRepository: canonical method {$method}() installed"
    );
}

// ClientProfileImageRepository canonical methods
$clientCanonicalMethods = [
    'loadVisibleImage',
    'loadVisibleEnrichedImage',
    'loadUploadedMediaAssetInScope',
    'deleteOwned',
];
foreach ($clientCanonicalMethods as $method) {
    assert_true(
        str_contains($clientRepoSrc, "public function {$method}("),
        "ClientProfileImageRepository: canonical method {$method}() installed"
    );
}

// All canonical repo methods take TenantContext as first parameter
foreach ($gcCanonicalMethods as $method) {
    assert_true(
        (bool) preg_match("/function {$method}\(TenantContext/", $gcRepoSrc),
        "MarketingGiftCardTemplateRepository::{$method}() first param is TenantContext"
    );
}
foreach ($clientCanonicalMethods as $method) {
    assert_true(
        (bool) preg_match("/function {$method}\(TenantContext/", $clientRepoSrc),
        "ClientProfileImageRepository::{$method}() first param is TenantContext"
    );
}

// ---------------------------------------------------------------------------
// Section 4: FOUNDATION-A4 — Canonical methods use requireResolvedTenant() (fail-closed)
// ---------------------------------------------------------------------------
echo "\n--- FOUNDATION-A4: Canonical methods are fail-closed via requireResolvedTenant() ---\n";

assert_true(
    (bool) preg_match('/requireResolvedTenant\(\)/', $gcRepoSrc),
    'MarketingGiftCardTemplateRepository: canonical methods call requireResolvedTenant()'
);

assert_true(
    (bool) preg_match('/requireResolvedTenant\(\)/', $clientRepoSrc),
    'ClientProfileImageRepository: canonical methods call requireResolvedTenant()'
);

// Unresolved context throws when canonical method is called
$unresolvedCtx = \Core\Kernel\TenantContext::unresolvedAuthenticated(
    actorId: 1,
    assuranceLevel: \Core\Kernel\AssuranceLevel::SESSION,
    executionSurface: \Core\Kernel\ExecutionSurface::HTTP_TENANT,
    reason: 'branch context not resolved in test'
);

assert_throws(
    fn () => $unresolvedCtx->requireResolvedTenant(),
    \Core\Kernel\UnresolvedTenantContextException::class,
    'Unresolved TenantContext throws UnresolvedTenantContextException on requireResolvedTenant()'
);

// Resolved context works fine
$resolvedCtx = \Core\Kernel\TenantContext::resolvedTenant(
    actorId: 42,
    organizationId: 1,
    branchId: 7,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: \Core\Kernel\AssuranceLevel::SESSION,
    executionSurface: \Core\Kernel\ExecutionSurface::HTTP_TENANT,
    organizationResolutionMode: 'branch_derived',
);

assert_not_throws(
    function () use ($resolvedCtx): void {
        $scope = $resolvedCtx->requireResolvedTenant();
        if ($scope['organization_id'] !== 1 || $scope['branch_id'] !== 7) {
            throw new \RuntimeException('scope mismatch');
        }
    },
    'Resolved TenantContext::requireResolvedTenant() returns correct org_id and branch_id'
);

// ---------------------------------------------------------------------------
// Section 5: FOUNDATION-A5 — Services use canonical scoped methods
// ---------------------------------------------------------------------------
echo "\n--- FOUNDATION-A5: Services use canonical scoped repository methods ---\n";

// GiftCard service uses canonical scoped methods
foreach (['loadVisibleTemplate', 'loadSelectableImageForTemplate', 'mutateUpdateTemplate',
          'mutateArchiveTemplate', 'loadVisibleImage', 'deleteOwnedImage',
          'clearArchivedTemplateImageRef', 'countOwnedMediaAssetReferences',
          'loadUploadedMediaAssetInScope'] as $method) {
    assert_true(
        str_contains($gcTemplateServiceSrc, $method),
        "MarketingGiftCardTemplateService: calls \$this->repo->{$method}()"
    );
}

// ClientImage service uses canonical scoped methods
foreach (['loadVisibleImage', 'loadVisibleEnrichedImage', 'deleteOwned', 'loadUploadedMediaAssetInScope'] as $method) {
    assert_true(
        str_contains($clientImageServiceSrc, $method),
        "ClientProfileImageService: calls \$this->repo->{$method}()"
    );
}

// Services use requireTenantContext() internal helper (context-first pattern)
assert_true(
    str_contains($gcTemplateServiceSrc, 'requireTenantContext'),
    'MarketingGiftCardTemplateService: has requireTenantContext() internal helper'
);
assert_true(
    str_contains($clientImageServiceSrc, 'requireTenantContext'),
    'ClientProfileImageService: has requireTenantContext() internal helper'
);

// ---------------------------------------------------------------------------
// Section 6: Preserved accepted ROOT-01 purge behavior
// ---------------------------------------------------------------------------
echo "\n--- Preserved ROOT-01: purge / ref-count behavior intact ---\n";

assert_true(
    str_contains($gcTemplateServiceSrc, 'purgeOrphanMediaAssetIfUnreferenced'),
    'MarketingGiftCardTemplateService: purgeOrphanMediaAssetIfUnreferenced() preserved'
);

assert_true(
    str_contains($gcTemplateServiceSrc, 'countOwnedMediaAssetReferences'),
    'MarketingGiftCardTemplateService: purge path uses countOwnedMediaAssetReferences()'
);

assert_true(
    str_contains($clientImageServiceSrc, 'purgeOrphanMediaAssetIfUnreferenced'),
    'ClientProfileImageService: delegates media purge to MarketingGiftCardTemplateService'
);

assert_true(
    str_contains($gcRepoSrc, 'hardDeleteOrphanMediaAssetForLibrary'),
    'MarketingGiftCardTemplateRepository: hardDeleteOrphanMediaAssetForLibrary() preserved'
);

assert_true(
    str_contains($gcRepoSrc, 'failQueueRowsForDeletedLibraryAsset'),
    'MarketingGiftCardTemplateRepository: failQueueRowsForDeletedLibraryAsset() preserved'
);

// countOwnedMediaAssetReferences crosses both tables (org-scoped)
assert_true(
    str_contains($gcRepoSrc, 'client_profile_images') && str_contains($gcRepoSrc, 'countOwnedMediaAssetReferences'),
    'MarketingGiftCardTemplateRepository: countOwnedMediaAssetReferences() checks client_profile_images table too'
);

// ---------------------------------------------------------------------------
// Section 7: No raw id-only patterns introduced in pilot lane
// ---------------------------------------------------------------------------
echo "\n--- No new raw id-only acquisition patterns in pilot lane ---\n";

// Old branch-only patterns must not appear in the canonical (new) service methods
// The services should NOT call findActiveTemplateForBranch / findActiveImageForBranch etc.
assert_false(
    str_contains($gcTemplateServiceSrc, 'findActiveTemplateForBranch'),
    'MarketingGiftCardTemplateService: no longer calls findActiveTemplateForBranch (id-only pattern eliminated)'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'findActiveImageForBranch'),
    'MarketingGiftCardTemplateService: no longer calls findActiveImageForBranch (id-only pattern eliminated)'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'findActiveSelectableImageForBranch'),
    'MarketingGiftCardTemplateService: no longer calls findActiveSelectableImageForBranch (id-only pattern eliminated)'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'updateTemplateInBranch'),
    'MarketingGiftCardTemplateService: no longer calls updateTemplateInBranch (id-only mutation eliminated)'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'archiveTemplateInBranch'),
    'MarketingGiftCardTemplateService: no longer calls archiveTemplateInBranch (id-only mutation eliminated)'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'softDeleteImageInBranch'),
    'MarketingGiftCardTemplateService: no longer calls softDeleteImageInBranch (id-only mutation eliminated)'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'clearArchivedTemplateImageIdForLibraryImage'),
    'MarketingGiftCardTemplateService: no longer calls clearArchivedTemplateImageIdForLibraryImage'
);

assert_false(
    str_contains($gcTemplateServiceSrc, 'countActiveImagesByMediaAssetId'),
    'MarketingGiftCardTemplateService: no longer calls countActiveImagesByMediaAssetId (replaced by canonical)'
);

assert_false(
    str_contains($clientImageServiceSrc, 'findActiveForClientInBranch'),
    'ClientProfileImageService: no longer calls findActiveForClientInBranch (id-only pattern eliminated)'
);

assert_false(
    str_contains($clientImageServiceSrc, 'findActiveEnrichedForClientImageInBranch'),
    'ClientProfileImageService: no longer calls findActiveEnrichedForClientImageInBranch (id-only pattern eliminated)'
);

assert_false(
    str_contains($clientImageServiceSrc, 'softDeleteInBranch'),
    'ClientProfileImageService: no longer calls softDeleteInBranch (id-only mutation eliminated)'
);

// ---------------------------------------------------------------------------
// Section 8: Bootstrap registration correctness
// ---------------------------------------------------------------------------
echo "\n--- Bootstrap: correct injection registered ---\n";

$bootstrapSrc = file_get_contents(SYSTEM_ROOT . '/modules/bootstrap/register_marketing.php');

assert_true(
    str_contains($bootstrapSrc, 'Core\\Kernel\\RequestContextHolder::class') &&
    str_contains($bootstrapSrc, 'MarketingGiftCardTemplateService'),
    'register_marketing.php: MarketingGiftCardTemplateService registered with RequestContextHolder'
);

assert_true(
    str_contains($bootstrapSrc, 'Core\\Kernel\\RequestContextHolder::class') &&
    str_contains($bootstrapSrc, 'ClientProfileImageService'),
    'register_marketing.php: ClientProfileImageService registered with RequestContextHolder'
);

assert_false(
    (bool) preg_match('/ClientProfileImageService.*BranchContext/', $bootstrapSrc),
    'register_marketing.php: ClientProfileImageService no longer injects BranchContext'
);

assert_false(
    (bool) preg_match('/MarketingGiftCardTemplateService.*BranchContext/', $bootstrapSrc),
    'register_marketing.php: MarketingGiftCardTemplateService no longer injects BranchContext'
);

// ---------------------------------------------------------------------------
// Section 9: RequestContextHolder fail-closed behavior
// ---------------------------------------------------------------------------
echo "\n--- RequestContextHolder: fail-closed gate ---\n";

$holder = new \Core\Kernel\RequestContextHolder();

assert_throws(
    fn () => $holder->requireContext(),
    \RuntimeException::class,
    'RequestContextHolder::requireContext() throws when no context is set'
);

$holder->set($resolvedCtx);
assert_not_throws(
    fn () => $holder->requireContext(),
    'RequestContextHolder::requireContext() succeeds after context is set'
);

$holder->reset();
assert_throws(
    fn () => $holder->requireContext(),
    \RuntimeException::class,
    'RequestContextHolder::requireContext() throws again after reset()'
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "===========================================\n";
echo "BIG-02 Pilot Lane Migration Verification\n";
echo "===========================================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total:  " . ($passed + $failed) . "\n";

if ($failed > 0) {
    echo "\nRESULT: FAIL — {$failed} assertion(s) did not pass.\n";
    exit(1);
} else {
    echo "\nRESULT: PASS — All assertions satisfied.\n";
    exit(0);
}
