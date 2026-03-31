<?php

declare(strict_types=1);

/**
 * FOUNDATION-A6 Guardrail 2: Id-Only Repository API Freeze
 *
 * Detects new public methods added to protected repository files that follow the
 * old id-only pattern (branch/client id as parameter, no TenantContext) without
 * being in the grandfathered legacy allowlist.
 *
 * Architecture rule:
 *   In protected repository files, all NEW public data access methods for tenant-owned
 *   resources MUST take TenantContext as their first parameter.
 *   New methods following the old pattern (int $branchId, no TenantContext) are FORBIDDEN.
 *   Existing legacy methods are grandfathered and listed in the allowlist below.
 *
 * Detection pattern:
 *   A method is flagged as a potential id-only violation if ALL of the following are true:
 *     1. It is a public function
 *     2. Its signature contains int $branchId (old scope parameter)
 *     3. Its signature does NOT begin with TenantContext (not a canonical method)
 *     4. Its name is NOT in the grandfathered allowlist for that file
 *
 * Root families addressed: ROOT-01 (id-only tenant scope drift)
 *
 * How to extend:
 *   When a new domain is migrated (A7 migration order), add its repository file to
 *   $protectedRepositories with an allowlist of its grandfathered legacy methods.
 *   Run the guardrail against the file BEFORE adding it to verify the allowlist is complete.
 *
 * Run from repo root: php system/scripts/ci/guardrail_id_only_repo_api_freeze.php
 */

$repoRoot = dirname(__DIR__, 3);

// ---------------------------------------------------------------------------
// Protected repository files with grandfathered legacy method allowlists.
// The allowlist is the FROZEN set of legacy methods that existed at migration time.
// Any NEW method matching the old pattern that is NOT in this list → FAIL.
//
// Allowlists frozen: 2026-03-31 (BIG-02 / FOUNDATION-A4+A5)
// Expanded:          2026-03-31 (BIG-04 / FOUNDATION-A7 Phase-1 Appointments)
// Expanded:          2026-03-31 (BIG-06 / FOUNDATION-A7 Phase-3 Sales)
// Expanded:          2026-03-31 (BIG-07 / FOUNDATION-A7 Phase-4 Client-owned resources)
// ---------------------------------------------------------------------------
$protectedRepositories = [

    'system/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php' => [
        // Grandfathered legacy methods — frozen 2026-03-31.
        // These may remain for backward compatibility with non-migrated callers.
        // Do NOT add new entries here without a corresponding canonical replacement.
        'listActiveTemplatesForBranch',
        'findActiveTemplateForBranch',
        'updateTemplateInBranch',
        'archiveTemplateInBranch',
        'listActiveImagesForBranch',
        'findActiveImageForBranch',
        'findActiveSelectableImageForBranch',
        'createImage',
        'createTemplate',
        'softDeleteImageInBranch',
        'clearArchivedTemplateImageIdForLibraryImage',
        'countActiveImagesByMediaAssetId',
        'failQueueRowsForDeletedLibraryAsset',
        'hardDeleteOrphanMediaAssetForLibrary',
        'activeTemplateCountUsingImageInBranch',
        // Infrastructure / readiness — not data access, not legacy concern
        'isStorageReady',
        'isMediaPipelinePresent',
        'isMediaBridgeReady',
    ],

    'system/modules/clients/repositories/ClientProfileImageRepository.php' => [
        // Grandfathered legacy methods — frozen 2026-03-31.
        'listActiveForClientInBranch',
        'listActiveEnrichedForClientInBranchByIds',
        'findActiveEnrichedForClientImageInBranch',
        'findActiveForClientInBranch',
        'findLatestReadyPrimaryRelativePathForClient',
        'softDeleteInBranch',
        'create',
        // Infrastructure / readiness
        'isTableReady',
        'isMediaLibraryReady',
    ],

    // APPOINTMENTS_P1 phase — frozen 2026-03-31 (BIG-04 / FOUNDATION-A7 Phase-1)
    // These repositories now have canonical TenantContext-scoped methods (loadVisible,
    // loadForUpdate, loadOwned). Legacy public methods without TenantContext are frozen
    // below. No new public methods may be added with int $branchId without TenantContext.
    'system/modules/appointments/repositories/AppointmentRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        'find',
        'findForUpdate',
        'list',
        'count',
        'create',
        'update',
        'softDelete',
        'markCheckedIn',
        'hasStaffConflict',
        'lockRoomRowForConflictCheck',
        'hasRoomConflict',
    ],

    'system/modules/appointments/repositories/BlockedSlotRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        'find',
        'create',
        'softDelete',
        'listForDate',
        'listForStaffAndDate',
        'listGroupedByStaffForDate',
    ],

    'system/modules/appointments/repositories/WaitlistRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        'find',
        'list',
        'count',
        'countActiveByClient',
        'create',
        'update',
        'findFirstWaitingForAutoOffer',
        'existsOpenOfferForSlot',
        'findExpiredOfferRows',
    ],

    'system/modules/appointments/repositories/AppointmentSeriesRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        'create',
        'find',
        'findForUpdate',
        'update',
        'listExistingStartAts',
        'countMaterializedOccurrences',
        'listCancellableAppointmentIds',
    ],

    // SALES_P3 phase — frozen 2026-03-31 (BIG-06 / FOUNDATION-A7 Phase-3)
    // These repositories now have canonical TenantContext-first methods (listOwned...,
    // findOwned..., mutateOwned...). Legacy public methods are frozen below.
    // No new public methods may be added with ?int $branchId without TenantContext.
    'system/modules/sales/repositories/PaymentMethodRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        'listActive',
        'isActiveCode',
        'listAll',
        'existsActiveNameForBranch',
        'codeExistsForBranch',
        'create',
    ],

    'system/modules/sales/repositories/VatRateRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        'listActive',
        'findByCode',
        'listAll',
        'existsActiveNameForBranch',
        'codeExistsForBranch',
        'create',
    ],

    // CLIENT_P4 phase — frozen 2026-03-31 (BIG-07 / FOUNDATION-A7 Phase-4)
    // These repositories now have canonical TenantContext-first methods.
    // Legacy public methods without TenantContext are frozen below.
    // No new public methods may be added with int $branchId without TenantContext.
    'system/modules/clients/repositories/ClientRepository.php' => [
        // Grandfathered legacy public methods — frozen 2026-03-31
        // These are anonymous public-resolution methods that intentionally use a concrete
        // branchId (not TenantContext) because they serve unauthenticated public booking flows.
        'lockActiveByEmailBranch',
        'lockActiveByPhoneDigitsBranch',
        'findActiveClientIdByPhoneDigitsExcluding',
    ],

    'system/modules/clients/repositories/ClientIssueFlagRepository.php' => [
        // No legacy int $branchId public methods — allowlist intentionally empty.
        // All existing public methods use int $id, int $clientId, or array $data patterns.
    ],

    'system/modules/clients/repositories/ClientMergeJobRepository.php' => [
        // No legacy int $branchId public methods — allowlist intentionally empty.
        // All existing public methods use int $id, int $organizationId, or array $patch patterns.
    ],

    'system/modules/clients/repositories/ClientRegistrationRequestRepository.php' => [
        // No legacy int $branchId public methods — allowlist intentionally empty.
        // All existing public methods use int $id or array $filters/data patterns.
    ],

    'system/modules/clients/repositories/ClientFieldDefinitionRepository.php' => [
        // Grandfathered legacy method — frozen 2026-03-31
        // list(?int $branchId = null, ...) uses ?int $branchId which matches the id-only
        // detection pattern. This method predates the canonical TenantContext API and remains
        // for backward compatibility with existing service callers.
        'list',
    ],
];

// ---------------------------------------------------------------------------
// Patterns that identify an old id-only public method signature.
// A method is considered "old pattern" if it has int $branchId in its params
// and does NOT have TenantContext as the first parameter type.
// ---------------------------------------------------------------------------

/**
 * Parse public method signatures from PHP source.
 * Returns list of [name, full_signature_substring].
 *
 * @return list<array{name: string, params: string}>
 */
function extractPublicMethodSignatures(string $content): array
{
    $methods = [];
    // Match public function declarations (not abstract, not in comments)
    if (!preg_match_all(
        '/^\s*(?:(?:final|static|abstract)\s+)*public\s+(?:(?:static|final)\s+)*function\s+(\w+)\s*\(([^)]*)\)/m',
        $content,
        $matches,
        PREG_SET_ORDER
    )) {
        return $methods;
    }
    foreach ($matches as $m) {
        $methods[] = ['name' => $m[1], 'params' => $m[2]];
    }
    return $methods;
}

/**
 * Returns true if the method signature is an old id-only pattern:
 *   - Has int $branchId in parameters
 *   - Does NOT have TenantContext as the first parameter
 */
function isOldIdOnlyPattern(string $params): bool
{
    $params = trim($params);
    // If TenantContext is the first param type → canonical method, not old pattern
    if (preg_match('/^\s*TenantContext\s*\$/', $params)) {
        return false;
    }
    // If it has int $branchId anywhere in the signature → old id-only scope
    if (preg_match('/\bint\s+\$branchId\b/', $params)) {
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------
$violations = [];
$checked = 0;

foreach ($protectedRepositories as $rel => $allowlist) {
    $path = $repoRoot . '/' . $rel;
    if (!is_file($path)) {
        $violations[] = "PROTECTED FILE MISSING: {$rel}\n"
            . "  → If this file was moved or renamed, update the guardrail protected list.";
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        $violations[] = "UNREADABLE: {$rel}";
        continue;
    }
    ++$checked;

    $methods = extractPublicMethodSignatures($content);
    foreach ($methods as ['name' => $name, 'params' => $params]) {
        if (!isOldIdOnlyPattern($params)) {
            continue; // canonical or infrastructure method, fine
        }
        if (in_array($name, $allowlist, true)) {
            continue; // grandfathered legacy method, fine
        }
        $violations[] = "ID-ONLY API FREEZE VIOLATED\n"
            . "  File:   {$rel}\n"
            . "  Method: public function {$name}({$params})\n"
            . "  Rule:   New public method uses old id-only scope parameter (int \$branchId)\n"
            . "          without TenantContext as the first parameter.\n"
            . "  Fix:    Add a TenantContext-scoped canonical method instead.\n"
            . "          If this is a truly legacy compat method, add it to the allowlist\n"
            . "          in this script with a dated comment explaining why.";
    }
}

if ($violations !== []) {
    fwrite(STDERR, "guardrail_id_only_repo_api_freeze: FAIL — " . count($violations) . " violation(s)\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, $v . "\n\n");
    }
    fwrite(STDERR, "Architecture rule: New protected-domain repository methods must use TenantContext scope.\n");
    fwrite(STDERR, "See: system/docs/FOUNDATION-A6-GUARDRAILS-POLICY-01.md\n");
    exit(1);
}

echo "guardrail_id_only_repo_api_freeze: PASS ({$checked} protected repository file(s) checked)\n";
exit(0);
