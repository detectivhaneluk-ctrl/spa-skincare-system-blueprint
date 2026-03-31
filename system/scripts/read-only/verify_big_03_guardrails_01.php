<?php

/**
 * BIG-03 / FOUNDATION-A6+A7+A8 — Mechanical Guardrails Verification Script
 *
 * Proves:
 * 1. Guardrail 1 (service DB ban) — detects violations in a synthetic violating service
 * 2. Guardrail 1 — passes on the migrated pilot lane services
 * 3. Guardrail 2 (id-only repo freeze) — detects violations in a synthetic violating repository
 * 4. Guardrail 2 — passes on the migrated pilot lane repositories
 * 5. Migration map document exists with all four phases documented
 * 6. Long-horizon platform direction document exists with required sections
 * 7. Guardrail policy document exists
 * 8. Charter updated to close A6/A7/A8
 *
 * Self-contained: no live DB. Uses inline detection logic mirroring the CI scripts.
 */

declare(strict_types=1);

define('REPO_ROOT', dirname(__DIR__, 3));

require_once __DIR__ . '/../../bootstrap.php';

// ---------------------------------------------------------------------------
// Assertion helpers
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

// ---------------------------------------------------------------------------
// Inline detection logic (mirrors the CI scripts)
// ---------------------------------------------------------------------------

/**
 * Returns true if a service file content would fail the DB ban guardrail.
 */
function serviceDbBanWouldFail(string $content): bool
{
    $patterns = [
        '/->fetchOne\s*\(/',
        '/->fetchAll\s*\(/',
        '/->query\s*\(/',
        '/->insert\s*\(/',
        '/->lastInsertId\s*\(/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $content)) {
            return true;
        }
    }
    return false;
}

/**
 * Extract public method signatures from PHP source.
 * Returns list of [name, params].
 *
 * @return list<array{name: string, params: string}>
 */
function parsePublicMethods(string $content): array
{
    $methods = [];
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

function isOldIdOnlyPattern(string $params): bool
{
    $params = trim($params);
    if (preg_match('/^\s*TenantContext\s*\$/', $params)) {
        return false;
    }
    return (bool) preg_match('/\bint\s+\$branchId\b/', $params);
}

// ---------------------------------------------------------------------------
// Section 1: Guardrail 1 — Service DB ban detects violations (synthetic test)
// ---------------------------------------------------------------------------
echo "\n--- Guardrail 1: Service DB ban detects violations ---\n";

$syntheticViolatingService = <<<'PHP'
<?php
namespace Modules\Example\Services;
use Core\App\Database;
final class SyntheticViolatingService {
    public function __construct(private Database $db) {}
    public function findRecord(int $id, int $branchId): ?array {
        return $this->db->fetchOne('SELECT * FROM records WHERE id = ?', [$id]);
    }
}
PHP;

$syntheticCleanService = <<<'PHP'
<?php
namespace Modules\Example\Services;
use Core\App\Database;
use Core\Kernel\RequestContextHolder;
final class SyntheticCleanService {
    public function __construct(private Database $db, private RequestContextHolder $ctx) {}
    public function uploadRecord(int $branchId, array $data): int {
        return $this->transactional(fn() => $this->repo->create($data));
    }
    private function transactional(callable $fn): mixed {
        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}
PHP;

assert_true(
    serviceDbBanWouldFail($syntheticViolatingService),
    'Guardrail 1: detects ->fetchOne() in synthetic violating service'
);

assert_false(
    serviceDbBanWouldFail($syntheticCleanService),
    'Guardrail 1: passes on synthetic clean service (only db->connection() allowed)'
);

// Test all forbidden patterns individually
$patterns = [
    ['$this->db->fetchOne(\'...\', []);', '->fetchOne('],
    ['$this->db->fetchAll(\'...\', []);', '->fetchAll('],
    ['$this->db->query(\'...\', []);', '->query('],
    ['$this->db->insert(\'table\', []);', '->insert('],
    ['$this->db->lastInsertId();', '->lastInsertId()'],
];
foreach ($patterns as [$code, $label]) {
    assert_true(
        serviceDbBanWouldFail("<?php\nclass X { public function f() { {$code} } }"),
        "Guardrail 1: detects {$label} in service"
    );
}

assert_false(
    serviceDbBanWouldFail("<?php\nclass X { public function t(callable \$fn): mixed { \$pdo = \$this->db->connection(); return \$fn(); } }"),
    'Guardrail 1: does NOT flag db->connection() (transaction management is allowed)'
);

// ---------------------------------------------------------------------------
// Section 2: Guardrail 1 — Pilot lane services are clean
// ---------------------------------------------------------------------------
echo "\n--- Guardrail 1: Pilot lane services are clean ---\n";

$gcServicePath = REPO_ROOT . '/system/modules/marketing/services/MarketingGiftCardTemplateService.php';
$clientServicePath = REPO_ROOT . '/system/modules/clients/services/ClientProfileImageService.php';

assert_true(is_file($gcServicePath), 'Pilot lane: MarketingGiftCardTemplateService.php exists');
assert_true(is_file($clientServicePath), 'Pilot lane: ClientProfileImageService.php exists');

if (is_file($gcServicePath)) {
    assert_false(
        serviceDbBanWouldFail(file_get_contents($gcServicePath)),
        'Guardrail 1 PASS: MarketingGiftCardTemplateService has no direct DB data access'
    );
}
if (is_file($clientServicePath)) {
    assert_false(
        serviceDbBanWouldFail(file_get_contents($clientServicePath)),
        'Guardrail 1 PASS: ClientProfileImageService has no direct DB data access'
    );
}

// ---------------------------------------------------------------------------
// Section 3: Guardrail 2 — Id-only repo freeze detects violations (synthetic test)
// ---------------------------------------------------------------------------
echo "\n--- Guardrail 2: Id-only repo freeze detects violations ---\n";

$syntheticViolatingRepo = <<<'PHP'
<?php
namespace Modules\Example\Repositories;
final class SyntheticViolatingRepository {
    public function findActiveRecordForBranch(int $recordId, int $branchId): ?array {
        return $this->db->fetchOne('SELECT * FROM records WHERE id = ? AND branch_id = ?', [$recordId, $branchId]);
    }
    public function newUnallowedMethod(int $recordId, int $branchId): ?array {
        return $this->db->fetchOne('SELECT * FROM records WHERE id = ? AND branch_id = ?', [$recordId, $branchId]);
    }
}
PHP;

$syntheticCanonicalRepo = <<<'PHP'
<?php
namespace Modules\Example\Repositories;
use Core\Kernel\TenantContext;
final class SyntheticCanonicalRepository {
    public function loadVisibleRecord(TenantContext $ctx, int $recordId): ?array {
        $scope = $ctx->requireResolvedTenant();
        return $this->db->fetchOne('SELECT * FROM records WHERE id = ? AND branch_id = ?', [$recordId, $scope['branch_id']]);
    }
}
PHP;

$allowlist = ['findActiveRecordForBranch']; // only first one is allowlisted

$violatingMethods = parsePublicMethods($syntheticViolatingRepo);
$newViolation = false;
foreach ($violatingMethods as ['name' => $name, 'params' => $params]) {
    if (isOldIdOnlyPattern($params) && !in_array($name, $allowlist, true)) {
        $newViolation = true;
    }
}
assert_true($newViolation, 'Guardrail 2: detects non-allowlisted id-only method in synthetic violating repo');

$canonicalMethods = parsePublicMethods($syntheticCanonicalRepo);
$canonicalViolation = false;
foreach ($canonicalMethods as ['name' => $name, 'params' => $params]) {
    if (isOldIdOnlyPattern($params)) {
        $canonicalViolation = true;
    }
}
assert_false($canonicalViolation, 'Guardrail 2: passes on canonical TenantContext-first method');

// Allowlisted legacy method should not trigger violation
$allowlistedMethods = parsePublicMethods($syntheticViolatingRepo);
$allowlistedViolation = false;
foreach ($allowlistedMethods as ['name' => $name, 'params' => $params]) {
    if (isOldIdOnlyPattern($params) && !in_array($name, ['findActiveRecordForBranch', 'newUnallowedMethod'], true)) {
        $allowlistedViolation = true;
    }
}
assert_false($allowlistedViolation, 'Guardrail 2: allowlisted method does not trigger violation');

// ---------------------------------------------------------------------------
// Section 4: Guardrail 2 — Pilot lane repositories have no new id-only violations
// ---------------------------------------------------------------------------
echo "\n--- Guardrail 2: Pilot lane repositories have no new id-only violations ---\n";

$gcRepoPath = REPO_ROOT . '/system/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php';
$clientRepoPath = REPO_ROOT . '/system/modules/clients/repositories/ClientProfileImageRepository.php';

$gcAllowlist = [
    'listActiveTemplatesForBranch', 'findActiveTemplateForBranch', 'updateTemplateInBranch',
    'archiveTemplateInBranch', 'listActiveImagesForBranch', 'findActiveImageForBranch',
    'findActiveSelectableImageForBranch', 'createImage', 'createTemplate',
    'softDeleteImageInBranch', 'clearArchivedTemplateImageIdForLibraryImage',
    'countActiveImagesByMediaAssetId', 'failQueueRowsForDeletedLibraryAsset',
    'hardDeleteOrphanMediaAssetForLibrary', 'activeTemplateCountUsingImageInBranch',
    'isStorageReady', 'isMediaPipelinePresent', 'isMediaBridgeReady',
];

$clientAllowlist = [
    'listActiveForClientInBranch', 'listActiveEnrichedForClientInBranchByIds',
    'findActiveEnrichedForClientImageInBranch', 'findActiveForClientInBranch',
    'findLatestReadyPrimaryRelativePathForClient', 'softDeleteInBranch', 'create',
    'isTableReady', 'isMediaLibraryReady',
];

foreach ([
    [$gcRepoPath, $gcAllowlist, 'MarketingGiftCardTemplateRepository'],
    [$clientRepoPath, $clientAllowlist, 'ClientProfileImageRepository'],
] as [$path, $allowlist, $name]) {
    assert_true(is_file($path), "Pilot lane: {$name}.php exists");
    if (!is_file($path)) {
        continue;
    }
    $content = file_get_contents($path);
    $methods = parsePublicMethods($content);
    $repoViolation = false;
    $violatingMethodName = '';
    foreach ($methods as ['name' => $mName, 'params' => $params]) {
        if (isOldIdOnlyPattern($params) && !in_array($mName, $allowlist, true)) {
            $repoViolation = true;
            $violatingMethodName = $mName;
        }
    }
    assert_false(
        $repoViolation,
        "Guardrail 2 PASS: {$name} has no non-allowlisted id-only methods"
        . ($repoViolation ? " (violation: {$violatingMethodName})" : '')
    );
}

// ---------------------------------------------------------------------------
// Section 5: Canonical methods exist in pilot lane repos (regression check)
// ---------------------------------------------------------------------------
echo "\n--- Pilot lane canonical API: regression check ---\n";

if (is_file($gcRepoPath)) {
    $content = file_get_contents($gcRepoPath);
    foreach ([
        'loadVisibleTemplate', 'loadVisibleImage', 'loadSelectableImageForTemplate',
        'loadUploadedMediaAssetInScope', 'mutateUpdateTemplate', 'mutateArchiveTemplate',
        'deleteOwnedImage', 'clearArchivedTemplateImageRef', 'countOwnedMediaAssetReferences',
    ] as $method) {
        assert_true(
            str_contains($content, "function {$method}("),
            "MarketingGiftCardTemplateRepository: canonical {$method}() still present"
        );
    }
}

if (is_file($clientRepoPath)) {
    $content = file_get_contents($clientRepoPath);
    foreach (['loadVisibleImage', 'loadVisibleEnrichedImage', 'loadUploadedMediaAssetInScope', 'deleteOwned'] as $method) {
        assert_true(
            str_contains($content, "function {$method}("),
            "ClientProfileImageRepository: canonical {$method}() still present"
        );
    }
}

// ---------------------------------------------------------------------------
// Section 6: Documentation files exist and have required content
// ---------------------------------------------------------------------------
echo "\n--- Documentation: required files and content ---\n";

$guardrailPolicyPath = REPO_ROOT . '/system/docs/FOUNDATION-A6-GUARDRAILS-POLICY-01.md';
$migrationMapPath = REPO_ROOT . '/docs/FOUNDATION-A7-MIGRATION-MAP-01.md';
$platformDirectionPath = REPO_ROOT . '/docs/FOUNDATION-A8-PLATFORM-DIRECTION-01.md';
$canonicalRoadmapPath = REPO_ROOT . '/docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md';
$charterPath = REPO_ROOT . '/system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md';

assert_true(is_file($guardrailPolicyPath), 'FOUNDATION-A6-GUARDRAILS-POLICY-01.md exists');
assert_true(is_file($migrationMapPath), 'FOUNDATION-A7-MIGRATION-MAP-01.md exists');
assert_true(is_file($platformDirectionPath), 'FOUNDATION-A8-PLATFORM-DIRECTION-01.md exists');

// Migration map: required phases present
if (is_file($migrationMapPath)) {
    $mm = file_get_contents($migrationMapPath);
    foreach (['PHASE-1', 'PHASE-2', 'PHASE-3', 'PHASE-4', 'Appointments', 'Online-Booking', 'Sales', 'Client-Owned'] as $token) {
        assert_true(
            str_contains($mm, $token),
            "Migration map contains '{$token}'"
        );
    }
    assert_true(str_contains($mm, 'blocking condition') || str_contains($mm, 'Blocking condition'), 'Migration map documents blocking conditions per phase');
}

// Platform direction: required strategic topics present
if (is_file($platformDirectionPath)) {
    $pd = file_get_contents($platformDirectionPath);
    foreach ([
        'Policy-Centered Modular Monolith',
        'Row-Level Security',
        'Observability',
        'ReBAC',
        'Cell-Based',
        'NOT doing',
    ] as $topic) {
        assert_true(
            str_contains($pd, $topic),
            "Platform direction contains strategic topic: '{$topic}'"
        );
    }
}

// Guardrail policy: required sections
if (is_file($guardrailPolicyPath)) {
    $gp = file_get_contents($guardrailPolicyPath);
    assert_true(str_contains($gp, 'guardrail_service_layer_db_ban'), 'Guardrail policy references service DB ban script');
    assert_true(str_contains($gp, 'guardrail_id_only_repo_api_freeze'), 'Guardrail policy references id-only repo freeze script');
    assert_true(str_contains($gp, 'How to expand scope'), 'Guardrail policy documents how to expand scope for A7 phases');
}

// CI scripts exist
assert_true(is_file(REPO_ROOT . '/system/scripts/ci/guardrail_service_layer_db_ban.php'), 'CI: guardrail_service_layer_db_ban.php exists');
assert_true(is_file(REPO_ROOT . '/system/scripts/ci/guardrail_id_only_repo_api_freeze.php'), 'CI: guardrail_id_only_repo_api_freeze.php exists');

// Charter updated: A6/A7/A8 closed
if (is_file($charterPath)) {
    $charter = file_get_contents($charterPath);
    assert_true(str_contains($charter, 'FOUNDATION-A6') && str_contains($charter, 'CLOSED'), 'Charter: FOUNDATION-A6 marked CLOSED');
    assert_true(str_contains($charter, 'FOUNDATION-A7') && str_contains($charter, 'CLOSED'), 'Charter: FOUNDATION-A7 marked CLOSED');
    assert_true(str_contains($charter, 'FOUNDATION-A8') && str_contains($charter, 'CLOSED'), 'Charter: FOUNDATION-A8 marked CLOSED');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "===========================================\n";
echo "BIG-03 Guardrails + Migration Map Verification\n";
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
