<?php

declare(strict_types=1);

/**
 * FOUNDATION-16 — read-only proof: ClientRepository ID loads use OrganizationRepositoryScope when org can resolve.
 *
 * Usage (from `system/`):
 *   php scripts/verify_client_repository_org_scope_foundation_16_readonly.php
 *   php scripts/verify_client_repository_org_scope_foundation_16_readonly.php --json
 *
 * Exit: 0 = all expectations met; 2 = mismatch
 */

$systemPath = dirname(__DIR__);
$json = in_array('--json', array_slice($argv, 1), true);

/**
 * @return list<array{file:string,class:string,method:string,needles:list<string>}>
 */
function foundation16ClientRepoExpectations(string $systemPath): array
{
    $c = $systemPath . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'repositories' . DIRECTORY_SEPARATOR . 'ClientRepository.php';

    return [
        [
            'file' => $c,
            'class' => 'ClientRepository',
            'method' => 'find',
            'needles' => ['branchColumnOwnedByResolvedOrganizationExistsClause', '$this->orgScope', 'FROM clients c'],
        ],
        [
            'file' => $c,
            'class' => 'ClientRepository',
            'method' => 'findForUpdate',
            'needles' => ['branchColumnOwnedByResolvedOrganizationExistsClause', '$this->orgScope', 'FOR UPDATE'],
        ],
    ];
}

function methodDeclOffset(string $src, string $method): ?int
{
    if (preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    return (int) $m[0][1];
}

function nextPeerMethodOffset(string $src, int $from): int
{
    $len = strlen($src);
    if (preg_match_all('/^\s+(?:public|private|protected)\s+function\s+\w+/m', $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $hit) {
            $pos = (int) $hit[1];
            if ($pos > $from) {
                return $pos;
            }
        }
    }

    return $len;
}

function methodBodyContainsAllNeedles(string $src, string $method, array $needles): bool
{
    $start = methodDeclOffset($src, $method);
    if ($start === null) {
        return false;
    }
    $end = nextPeerMethodOffset($src, $start);
    $chunk = substr($src, $start, $end - $start);
    foreach ($needles as $n) {
        if (!str_contains($chunk, $n)) {
            return false;
        }
    }

    return true;
}

$expectations = foundation16ClientRepoExpectations($systemPath);
$rows = [];
$failed = 0;

foreach ($expectations as $exp) {
    if (!is_file($exp['file'])) {
        $rows[] = ['class' => $exp['class'], 'method' => $exp['method'], 'ok' => false, 'reason' => 'file_missing'];
        ++$failed;
        continue;
    }
    $src = (string) file_get_contents($exp['file']);
    $ok = methodBodyContainsAllNeedles($src, $exp['method'], $exp['needles']);
    if (!$ok) {
        ++$failed;
    }
    $rows[] = ['class' => $exp['class'], 'method' => $exp['method'], 'ok' => $ok, 'reason' => $ok ? null : 'needles_missing'];
}

$registerFile = $systemPath . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'register_clients.php';
$registerSrc = is_file($registerFile) ? (string) file_get_contents($registerFile) : '';
$registerOk = str_contains($registerSrc, 'OrganizationRepositoryScope::class')
    && str_contains($registerSrc, 'ClientRepository::class');

$scopeFile = $systemPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'OrganizationRepositoryScope.php';
$scopeSrc = is_file($scopeFile) ? (string) file_get_contents($scopeFile) : '';
$scopeOk = str_contains($scopeSrc, 'branchColumnOwnedByResolvedOrganizationExistsClause')
    && str_contains($scopeSrc, 'resolvedOrganizationId');

$payload = [
    'verifier' => 'verify_client_repository_org_scope_foundation_16_readonly',
    'wave' => 'MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-16',
    'all_ok' => $failed === 0 && $registerOk && $scopeOk,
    'failed_method_expectations' => $failed,
    'register_clients_org_scope_di_ok' => $registerOk,
    'organization_repository_scope_helper_ok' => $scopeOk,
    'coverage_rows' => $rows,
    'explicitly_unscoped_repo_methods' => [
        'ClientRepository::list' => 'Org scoping added in FOUNDATION-18 — verify_client_repository_org_scope_foundation_18_readonly.php.',
        'ClientRepository::count' => 'Org scoping added in FOUNDATION-18 — verify_client_repository_org_scope_foundation_18_readonly.php.',
        'ClientRepository::lockActiveByEmailBranch' => 'FOUNDATION-TENANT-REPOSITORY-CLOSURE-10: positive branch_id + publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause (Tier A verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php).',
        'ClientRepository::lockActiveByPhoneDigitsBranch' => 'FOUNDATION-TENANT-REPOSITORY-CLOSURE-11: same public contract as email lock (Tier A verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php).',
        'ClientRepository::findActiveClientIdByPhoneDigitsExcluding' => 'FOUNDATION-TENANT-REPOSITORY-CLOSURE-11: same public contract as email lock (verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php).',
    ],
    'null_branch_id_when_org_resolved' => 'branchColumnOwnedByResolvedOrganizationExistsClause requires non-null branch_id tied to resolved org; clients with NULL branch_id return no row (fail-closed) when OrganizationContext resolves.',
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "verifier: {$payload['verifier']}\n";
    echo 'register_clients_org_scope_di_ok: ' . ($registerOk ? 'true' : 'false') . "\n";
    echo 'organization_repository_scope_helper_ok: ' . ($scopeOk ? 'true' : 'false') . "\n";
    echo 'all_ok: ' . ($payload['all_ok'] ? 'true' : 'false') . "\n";
    foreach ($rows as $r) {
        $s = $r['ok'] ? 'OK' : 'MISSING';
        echo "{$s}\t{$r['class']}::{$r['method']}\n";
    }
    echo "\nexplicitly_unscoped_repo_methods (documented):\n";
    foreach ($payload['explicitly_unscoped_repo_methods'] as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
    echo "\nnull_branch_id_when_org_resolved: {$payload['null_branch_id_when_org_resolved']}\n";
}

exit($payload['all_ok'] ? 0 : 2);
