<?php

declare(strict_types=1);

/**
 * FOUNDATION-13 — read-only proof: marketing repositories use OrganizationRepositoryScope / org-resolved branching.
 *
 * Usage (from `system/`):
 *   php scripts/verify_marketing_repository_org_scope_foundation_13_readonly.php
 *   php scripts/verify_marketing_repository_org_scope_foundation_13_readonly.php --json
 *
 * Exit: 0 = all expectations met; 2 = mismatch
 */

$systemPath = dirname(__DIR__);
$json = in_array('--json', array_slice($argv, 1), true);

/**
 * @return list<array{file:string,class:string,method:string,needles:list<string>}>
 */
function foundation13MarketingRepoExpectations(string $systemPath): array
{
    $m = $systemPath . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'marketing' . DIRECTORY_SEPARATOR . 'repositories' . DIRECTORY_SEPARATOR;

    return [
        [
            'file' => $m . 'MarketingCampaignRepository.php',
            'class' => 'MarketingCampaignRepository',
            'method' => 'findInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRepository.php',
            'class' => 'MarketingCampaignRepository',
            'method' => 'listInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRepository.php',
            'class' => 'MarketingCampaignRepository',
            'method' => 'countInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRepository.php',
            'class' => 'MarketingCampaignRepository',
            'method' => 'updateInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause'],
        ],
        [
            'file' => $m . 'MarketingCampaignRunRepository.php',
            'class' => 'MarketingCampaignRunRepository',
            'method' => 'findInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRunRepository.php',
            'class' => 'MarketingCampaignRunRepository',
            'method' => 'findForUpdateInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRunRepository.php',
            'class' => 'MarketingCampaignRunRepository',
            'method' => 'listByCampaignIdInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRunRepository.php',
            'class' => 'MarketingCampaignRunRepository',
            'method' => 'updateInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRecipientRepository.php',
            'class' => 'MarketingCampaignRecipientRepository',
            'method' => 'findForUpdateInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRecipientRepository.php',
            'class' => 'MarketingCampaignRecipientRepository',
            'method' => 'listByRunIdInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRecipientRepository.php',
            'class' => 'MarketingCampaignRecipientRepository',
            'method' => 'listPendingForRunInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRecipientRepository.php',
            'class' => 'MarketingCampaignRecipientRepository',
            'method' => 'updateInTenantScopeForStaff',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
        ],
        [
            'file' => $m . 'MarketingCampaignRecipientRepository.php',
            'class' => 'MarketingCampaignRecipientRepository',
            'method' => 'cancelAllPendingForRun',
            'needles' => ['marketingCampaignBranchOrgExistsClause', '$this->orgScope'],
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

$expectations = foundation13MarketingRepoExpectations($systemPath);
$rows = [];
$failed = 0;

foreach ($expectations as $exp) {
    if (!is_file($exp['file'])) {
        $rows[] = [
            'class' => $exp['class'],
            'method' => $exp['method'],
            'ok' => false,
            'reason' => 'file_missing',
        ];
        ++$failed;
        continue;
    }
    $src = (string) file_get_contents($exp['file']);
    $ok = methodBodyContainsAllNeedles($src, $exp['method'], $exp['needles']);
    if (!$ok) {
        ++$failed;
    }
    $rows[] = [
        'class' => $exp['class'],
        'method' => $exp['method'],
        'ok' => $ok,
        'reason' => $ok ? null : 'needles_missing',
    ];
}

$helperFile = $systemPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Organization' . DIRECTORY_SEPARATOR . 'OrganizationRepositoryScope.php';
$helperOk = is_file($helperFile) && str_contains((string) file_get_contents($helperFile), 'marketingCampaignBranchOrgExistsClause');

$payload = [
    'verifier' => 'verify_marketing_repository_org_scope_foundation_13_readonly',
    'wave' => 'MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-13',
    'all_ok' => $failed === 0 && $helperOk,
    'failed_method_expectations' => $failed,
    'organization_repository_scope_file_ok' => $helperOk,
    'coverage_rows' => $rows,
    'explicitly_unscoped_repo_methods' => [
        'MarketingCampaignRepository::insert' => 'INSERT has no WHERE; org enforced in MarketingCampaignService::createCampaign when OrganizationContext resolved.',
        'MarketingCampaignRunRepository::insert' => 'INSERT has no WHERE; only called after campaign scoped find in MarketingCampaignService::freezeRecipientSnapshot.',
        'MarketingCampaignRecipientRepository::insertBatch' => 'Batch insert after scoped campaign/run path in service; no public marketing routes.',
    ],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "verifier: {$payload['verifier']}\n";
    echo 'organization_repository_scope_file_ok: ' . ($helperOk ? 'true' : 'false') . "\n";
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
