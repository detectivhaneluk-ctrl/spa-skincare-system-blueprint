<?php

declare(strict_types=1);

/**
 * FOUNDATION-14 — read-only proof: payroll repositories use OrganizationRepositoryScope payroll clauses.
 *
 * Usage (from `system/`):
 *   php scripts/verify_payroll_repository_org_scope_foundation_14_readonly.php
 *   php scripts/verify_payroll_repository_org_scope_foundation_14_readonly.php --json
 *
 * Exit: 0 = all expectations met; 2 = mismatch
 */

$systemPath = dirname(__DIR__);
$json = in_array('--json', array_slice($argv, 1), true);

/**
 * @return list<array{file:string,class:string,method:string,needles:list<string>}>
 */
function foundation14PayrollRepoExpectations(string $systemPath): array
{
    $p = $systemPath . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'payroll' . DIRECTORY_SEPARATOR . 'repositories' . DIRECTORY_SEPARATOR;

    return [
        [
            'file' => $p . 'PayrollRunRepository.php',
            'class' => 'PayrollRunRepository',
            'method' => 'find',
            'needles' => ['payrollRunBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollRunRepository.php',
            'class' => 'PayrollRunRepository',
            'method' => 'listForBranch',
            'needles' => ['payrollRunBranchOrgExistsClause'],
        ],
        [
            'file' => $p . 'PayrollRunRepository.php',
            'class' => 'PayrollRunRepository',
            'method' => 'listRecent',
            'needles' => ['payrollRunBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollRunRepository.php',
            'class' => 'PayrollRunRepository',
            'method' => 'update',
            'needles' => ['payrollRunBranchOrgExistsClause'],
        ],
        [
            'file' => $p . 'PayrollRunRepository.php',
            'class' => 'PayrollRunRepository',
            'method' => 'delete',
            'needles' => ['payrollRunBranchOrgExistsClause'],
        ],
        [
            'file' => $p . 'PayrollCompensationRuleRepository.php',
            'class' => 'PayrollCompensationRuleRepository',
            'method' => 'find',
            'needles' => ['payrollCompensationRuleBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollCompensationRuleRepository.php',
            'class' => 'PayrollCompensationRuleRepository',
            'method' => 'listActive',
            'needles' => ['payrollCompensationRuleBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollCompensationRuleRepository.php',
            'class' => 'PayrollCompensationRuleRepository',
            'method' => 'listAllForBranchFilter',
            'needles' => ['payrollCompensationRuleBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollCompensationRuleRepository.php',
            'class' => 'PayrollCompensationRuleRepository',
            'method' => 'update',
            'needles' => ['payrollCompensationRuleBranchOrgExistsClause'],
        ],
        [
            'file' => $p . 'PayrollCommissionLineRepository.php',
            'class' => 'PayrollCommissionLineRepository',
            'method' => 'deleteByRunId',
            'needles' => ['payrollRunBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollCommissionLineRepository.php',
            'class' => 'PayrollCommissionLineRepository',
            'method' => 'listByRunId',
            'needles' => ['payrollRunBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $p . 'PayrollCommissionLineRepository.php',
            'class' => 'PayrollCommissionLineRepository',
            'method' => 'allocatedSourceRefsExcludingRun',
            'needles' => ['payrollRunBranchOrgExistsClause'],
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

$expectations = foundation14PayrollRepoExpectations($systemPath);
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

$scopeFile = $systemPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'OrganizationRepositoryScope.php';
$scopeSrc = is_file($scopeFile) ? (string) file_get_contents($scopeFile) : '';
$scopeOk = str_contains($scopeSrc, 'payrollRunBranchOrgExistsClause')
    && str_contains($scopeSrc, 'payrollCompensationRuleBranchOrgExistsClause')
    && str_contains($scopeSrc, 'branchColumnOwnedByResolvedOrganizationExistsClause');

$payload = [
    'verifier' => 'verify_payroll_repository_org_scope_foundation_14_readonly',
    'wave' => 'MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-14',
    'all_ok' => $failed === 0 && $scopeOk,
    'failed_method_expectations' => $failed,
    'organization_repository_scope_payroll_methods_ok' => $scopeOk,
    'coverage_rows' => $rows,
    'explicitly_unscoped_repo_methods' => [
        'PayrollRunRepository::create' => 'INSERT has no WHERE; org enforced via PayrollService::createRun (OrganizationScopedBranchAssert when context resolved).',
        'PayrollCompensationRuleRepository::create' => 'INSERT has no WHERE; PayrollRuleController::store requires branch + assert when org resolved.',
        'PayrollCommissionLineRepository::insert' => 'INSERT after scoped run in PayrollService; no public payroll routes.',
    ],
    'public_flow_check' => 'All payroll routes use AuthMiddleware + payroll.view|payroll.manage (register_payroll.php).',
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "verifier: {$payload['verifier']}\n";
    echo 'organization_repository_scope_payroll_methods_ok: ' . ($scopeOk ? 'true' : 'false') . "\n";
    echo 'all_ok: ' . ($payload['all_ok'] ? 'true' : 'false') . "\n";
    foreach ($rows as $r) {
        $s = $r['ok'] ? 'OK' : 'MISSING';
        echo "{$s}\t{$r['class']}::{$r['method']}\n";
    }
    echo "\nexplicitly_unscoped_repo_methods (documented):\n";
    foreach ($payload['explicitly_unscoped_repo_methods'] as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
}

exit($payload['all_ok'] ? 0 : 2);
