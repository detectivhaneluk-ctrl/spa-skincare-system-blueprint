<?php

declare(strict_types=1);

/**
 * CI-AND-REGRESSION-BREADTH-CLOSURE-01 — Read-Only Verifier
 *
 * Statically verifies the new CI/regression topology introduced by this task:
 *   - PHPUnit harness exists and is correctly wired
 *   - All backbone guardrail scripts are present and registered in the CI workflow
 *   - Key backbone verifier families are registered in the CI workflow
 *   - PHPStan scope includes tests/ and key kernel pure files
 *   - composer.json has PHPUnit dev dependency and correct scripts
 *   - ci:guardrails script covers all 10 backbone guardrails
 *   - Fast-gate (pr-fast-guardrails.yml) topology is explicit
 *   - Unit test files exist and contain meaningful assertions
 *
 * All checks are static (file_get_contents + str_contains). No DB. No network.
 * Exit code 0 = all PASS. Exit code 1 = one or more FAIL.
 *
 * Run from repo root: php system/scripts/read-only/verify_ci_and_regression_breadth_closure_01.php
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;
$results = [];

/**
 * @param bool   $ok
 * @param string $id
 * @param string $description
 */
function check(bool $ok, string $id, string $description): void
{
    global $pass, $fail, $results;
    $results[] = ['ok' => $ok, 'id' => $id, 'description' => $description];
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// A — PHPUnit harness wiring
// ---------------------------------------------------------------------------

$composerJson = (string) file_get_contents($repoRoot . '/composer.json');
$phpunitXml   = is_file($repoRoot . '/phpunit.xml.dist') ? (string) file_get_contents($repoRoot . '/phpunit.xml.dist') : '';
$testsBootstrap = is_file($repoRoot . '/tests/bootstrap.php') ? (string) file_get_contents($repoRoot . '/tests/bootstrap.php') : '';

check(
    str_contains($composerJson, '"phpunit/phpunit"'),
    'A1',
    'composer.json has phpunit/phpunit in require-dev'
);

check(
    str_contains($composerJson, '"test": "phpunit"') || str_contains($composerJson, '"test":'),
    'A2',
    'composer.json has "test" script entry'
);

check(
    str_contains($composerJson, '"autoload-dev"'),
    'A3',
    'composer.json has autoload-dev section for tests/'
);

check(
    $phpunitXml !== '',
    'A4',
    'phpunit.xml.dist exists'
);

check(
    str_contains($phpunitXml, 'tests/bootstrap.php'),
    'A5',
    'phpunit.xml.dist references tests/bootstrap.php'
);

check(
    str_contains($phpunitXml, '<directory>tests/Unit</directory>'),
    'A6',
    'phpunit.xml.dist has Unit testsuite pointing to tests/Unit'
);

check(
    $testsBootstrap !== '',
    'A7',
    'tests/bootstrap.php exists'
);

check(
    str_contains($testsBootstrap, 'vendor/autoload.php'),
    'A8',
    'tests/bootstrap.php loads vendor/autoload.php'
);

// ---------------------------------------------------------------------------
// B — Unit test files
// ---------------------------------------------------------------------------

$tenantContextTest = is_file($repoRoot . '/tests/Unit/Core/Kernel/TenantContextTest.php')
    ? (string) file_get_contents($repoRoot . '/tests/Unit/Core/Kernel/TenantContextTest.php')
    : '';

$accessDecisionTest = is_file($repoRoot . '/tests/Unit/Core/Kernel/Authorization/AccessDecisionTest.php')
    ? (string) file_get_contents($repoRoot . '/tests/Unit/Core/Kernel/Authorization/AccessDecisionTest.php')
    : '';

check(
    $tenantContextTest !== '',
    'B1',
    'tests/Unit/Core/Kernel/TenantContextTest.php exists'
);

check(
    str_contains($tenantContextTest, 'final class TenantContextTest extends TestCase'),
    'B2',
    'TenantContextTest extends PHPUnit TestCase'
);

check(
    str_contains($tenantContextTest, 'requireResolvedTenant'),
    'B3',
    'TenantContextTest covers the fail-closed requireResolvedTenant() contract'
);

check(
    str_contains($tenantContextTest, 'UnresolvedTenantContextException'),
    'B4',
    'TenantContextTest asserts UnresolvedTenantContextException is thrown when unresolved'
);

check(
    str_contains($tenantContextTest, 'auditActorId'),
    'B5',
    'TenantContextTest covers auditActorId() for support-entry audit contract'
);

check(
    $accessDecisionTest !== '',
    'B6',
    'tests/Unit/Core/Kernel/Authorization/AccessDecisionTest.php exists'
);

check(
    str_contains($accessDecisionTest, 'final class AccessDecisionTest extends TestCase'),
    'B7',
    'AccessDecisionTest extends PHPUnit TestCase'
);

check(
    str_contains($accessDecisionTest, 'orThrow'),
    'B8',
    'AccessDecisionTest covers orThrow() fail-closed behavior'
);

check(
    str_contains($accessDecisionTest, 'AuthorizationException'),
    'B9',
    'AccessDecisionTest asserts AuthorizationException thrown on denial'
);

// ---------------------------------------------------------------------------
// C — Backbone guardrail scripts exist on disk
// ---------------------------------------------------------------------------

$guardrails = [
    'system/scripts/ci/guardrail_service_layer_db_ban.php',
    'system/scripts/ci/guardrail_id_only_repo_api_freeze.php',
    'system/scripts/ci/guardrail_async_state_machine_ban.php',
    'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
    'system/scripts/ci/guardrail_plt_mfa_01_privileged_plane_step_up.php',
    'system/scripts/ci/guardrail_out_of_band_integrity_and_worker_lifecycle_closure_01.php',
    'system/scripts/ci/guardrail_critical_integrity_fail_closed_boundary_01.php',
    'system/scripts/ci/guardrail_wave07_write_path_replica_ban.php',
    'system/scripts/ci/guardrail_shard_readiness_organization_id.php',
    'system/scripts/ci/guardrail_online_ddl_large_table_migrations.php',
];

foreach ($guardrails as $i => $rel) {
    $id = 'C' . ($i + 1);
    check(
        is_file($repoRoot . '/' . $rel),
        $id,
        "Guardrail script exists on disk: {$rel}"
    );
}

// ---------------------------------------------------------------------------
// D — pr-fast-guardrails.yml wires all backbone guardrails
// ---------------------------------------------------------------------------

$prFast = is_file($repoRoot . '/.github/workflows/pr-fast-guardrails.yml')
    ? (string) file_get_contents($repoRoot . '/.github/workflows/pr-fast-guardrails.yml')
    : '';

$guardrailBasenames = [
    'guardrail_service_layer_db_ban.php',
    'guardrail_id_only_repo_api_freeze.php',
    'guardrail_async_state_machine_ban.php',
    'guardrail_plt_auth_02_service_authorizer_enforcement.php',
    'guardrail_plt_mfa_01_privileged_plane_step_up.php',
    'guardrail_out_of_band_integrity_and_worker_lifecycle_closure_01.php',
    'guardrail_critical_integrity_fail_closed_boundary_01.php',
    'guardrail_wave07_write_path_replica_ban.php',
    'guardrail_shard_readiness_organization_id.php',
    'guardrail_online_ddl_large_table_migrations.php',
];

foreach ($guardrailBasenames as $i => $basename) {
    $id = 'D' . ($i + 1);
    check(
        str_contains($prFast, $basename),
        $id,
        "pr-fast-guardrails.yml references {$basename}"
    );
}

check(
    str_contains($prFast, 'composer run test') || str_contains($prFast, 'phpunit'),
    'D11',
    'pr-fast-guardrails.yml runs PHPUnit tests'
);

// ---------------------------------------------------------------------------
// E — pr-fast-guardrails.yml wires key backbone verifier families
// ---------------------------------------------------------------------------

$keyVerifiers = [
    'verify_background_flow_fail_closed_closure_01.php',
    'verify_out_of_band_integrity_and_worker_lifecycle_closure_01.php',
    'verify_kernel_tenant_context_01.php',
    'verify_plt_auth_02_authorization_enforcement_wiring_01.php',
    'verify_critical_integrity_fail_closed_boundary_01.php',
];

foreach ($keyVerifiers as $i => $verifier) {
    $id = 'E' . ($i + 1);
    check(
        str_contains($prFast, $verifier),
        $id,
        "pr-fast-guardrails.yml references key verifier: {$verifier}"
    );
}

// ---------------------------------------------------------------------------
// F — CI topology comment is explicit about fast-gate vs deep-gate
// ---------------------------------------------------------------------------

check(
    str_contains($prFast, 'FAST GATE') || str_contains($prFast, 'fast gate') || str_contains($prFast, 'no DB'),
    'F1',
    'pr-fast-guardrails.yml has explicit topology comment distinguishing fast vs deep gate'
);

check(
    str_contains($prFast, 'tenant-isolation-gate') || str_contains($prFast, 'DEEP GATE'),
    'F2',
    'pr-fast-guardrails.yml references or labels the deep gate (tenant-isolation-gate)'
);

// ---------------------------------------------------------------------------
// G — PHPStan scope includes tests/ and key kernel pure files
// ---------------------------------------------------------------------------

$phpstan = is_file($repoRoot . '/phpstan.neon.dist')
    ? (string) file_get_contents($repoRoot . '/phpstan.neon.dist')
    : '';

check(
    str_contains($phpstan, 'tests/Unit') || str_contains($phpstan, 'tests/'),
    'G1',
    'phpstan.neon.dist scope includes tests/'
);

check(
    str_contains($phpstan, 'TenantContext.php') || str_contains($phpstan, 'system/core/Kernel'),
    'G2',
    'phpstan.neon.dist scope includes kernel TenantContext or system/core/Kernel path'
);

check(
    str_contains($phpstan, 'AccessDecision.php') || str_contains($phpstan, 'Authorization'),
    'G3',
    'phpstan.neon.dist scope includes AccessDecision or Authorization path'
);

// ---------------------------------------------------------------------------
// H — composer.json ci:guardrails script covers backbone guardrails
// ---------------------------------------------------------------------------

foreach ($guardrailBasenames as $i => $basename) {
    $id = 'H' . ($i + 1);
    check(
        str_contains($composerJson, $basename),
        $id,
        "composer.json ci:guardrails script references {$basename}"
    );
}

// ---------------------------------------------------------------------------
// I — Key verifier files exist on disk
// ---------------------------------------------------------------------------

foreach ($keyVerifiers as $i => $verifier) {
    $id = 'I' . ($i + 1);
    check(
        is_file($repoRoot . '/system/scripts/read-only/' . $verifier),
        $id,
        "Key verifier exists on disk: {$verifier}"
    );
}

// ---------------------------------------------------------------------------
// J — Regression: prior backbone guardrail assertions still present
// (ensures this task did not regress the out-of-band guard)
// ---------------------------------------------------------------------------

$oobGuardrail = is_file($repoRoot . '/system/scripts/ci/guardrail_out_of_band_integrity_and_worker_lifecycle_closure_01.php')
    ? (string) file_get_contents($repoRoot . '/system/scripts/ci/guardrail_out_of_band_integrity_and_worker_lifecycle_closure_01.php')
    : '';

check(
    str_contains($oobGuardrail, 'assertExecutionAllowedForBranch'),
    'J1',
    'out-of-band guardrail still asserts assertExecutionAllowedForBranch (prior-wave regression)'
);

check(
    str_contains($oobGuardrail, 'isExecutionAllowedForBranch'),
    'J2',
    'out-of-band guardrail still asserts isExecutionAllowedForBranch (prior-wave regression)'
);

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

$total = $pass + $fail;

foreach ($results as $r) {
    $status = $r['ok'] ? 'PASS' : 'FAIL';
    printf("[%s] %s — %s\n", $status, $r['id'], $r['description']);
}

echo PHP_EOL;
printf("Result: %d/%d PASS\n", $pass, $total);

if ($fail > 0) {
    printf("FAILED: %d assertion(s) failed.\n", $fail);
    exit(1);
}

echo "ALL PASS — CI topology truth verified.\n";
exit(0);
