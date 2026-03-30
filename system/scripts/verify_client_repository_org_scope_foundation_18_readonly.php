<?php

declare(strict_types=1);

/**
 * FOUNDATION-18 — read-only proof: ClientRepository::list / ::count use OrganizationRepositoryScope with filter parity.
 *
 * Usage (from `system/`):
 *   php scripts/verify_client_repository_org_scope_foundation_18_readonly.php
 *   php scripts/verify_client_repository_org_scope_foundation_18_readonly.php --json
 *
 * Exit: 0 = all expectations met; 2 = mismatch
 */

$systemPath = dirname(__DIR__);
$json = in_array('--json', array_slice($argv, 1), true);

/**
 * @return list<array{file:string,class:string,method:string,needles:list<string>}>
 */
function foundation18ClientListCountExpectations(string $systemPath): array
{
    $c = $systemPath . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'repositories' . DIRECTORY_SEPARATOR . 'ClientRepository.php';

    return [
        [
            'file' => $c,
            'class' => 'ClientRepository',
            'method' => 'list',
            'needles' => [
                'branchColumnOwnedByResolvedOrganizationExistsClause',
                '$this->orgScope',
                'FROM clients c',
                'applyClientListFilters',
                '$frag[\'sql\']',
                'array_merge($params, $frag[\'params\'])',
            ],
        ],
        [
            'file' => $c,
            'class' => 'ClientRepository',
            'method' => 'count',
            'needles' => [
                'branchColumnOwnedByResolvedOrganizationExistsClause',
                '$this->orgScope',
                'FROM clients c',
                'applyClientListFilters',
                '$frag[\'sql\']',
                'array_merge($params, $frag[\'params\'])',
            ],
        ],
        [
            'file' => $c,
            'class' => 'ClientRepository',
            'method' => 'applyClientListFilters',
            'needles' => [
                'c.first_name LIKE',
                '$filters[\'search\']',
            ],
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

$expectations = foundation18ClientListCountExpectations($systemPath);
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
$scopeOk = str_contains($scopeSrc, 'branchColumnOwnedByResolvedOrganizationExistsClause')
    && str_contains($scopeSrc, 'resolvedOrganizationId');

$controllerFile = $systemPath . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'ClientController.php';
$controllerSrc = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
$indexOk = str_contains($controllerSrc, '$this->repo->list(') && str_contains($controllerSrc, '$this->repo->count(');
$regShowOk = str_contains($controllerSrc, 'registrationsShow') && str_contains($controllerSrc, '$this->repo->list([');

$payload = [
    'verifier' => 'verify_client_repository_org_scope_foundation_18_readonly',
    'wave' => 'MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-18',
    'all_ok' => $failed === 0 && $scopeOk && $indexOk && $regShowOk,
    'failed_method_expectations' => $failed,
    'organization_repository_scope_helper_ok' => $scopeOk,
    'client_controller_index_repo_list_count_ok' => $indexOk,
    'client_controller_registrations_show_repo_list_ok' => $regShowOk,
    'coverage_rows' => $rows,
    'smoke_documentation' => 'Manual staff smoke (not executed by this script): authenticated GET /clients (list+count) and GET /clients/registrations/{id} with client_search query when org context resolves; expect no cross-org clients and NULL branch_id rows omitted. Cross-module ClientListProvider consumers not claimed in F-18 — see ops doc waiver.',
    'deferred' => [
        'ClientListProviderImpl' => 'File unchanged; still calls ClientRepository::list — runtime now inherits org predicate when context resolves. Invoice/appointment/package/gift/membership dropdowns not smoke-tested this wave.',
    ],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "verifier: {$payload['verifier']}\n";
    echo 'organization_repository_scope_helper_ok: ' . ($scopeOk ? 'true' : 'false') . "\n";
    echo 'client_controller_index_repo_list_count_ok: ' . ($indexOk ? 'true' : 'false') . "\n";
    echo 'client_controller_registrations_show_repo_list_ok: ' . ($regShowOk ? 'true' : 'false') . "\n";
    echo 'all_ok: ' . ($payload['all_ok'] ? 'true' : 'false') . "\n";
    foreach ($rows as $r) {
        $s = $r['ok'] ? 'OK' : 'MISSING';
        echo "{$s}\t{$r['class']}::{$r['method']}\n";
    }
    echo "\n{$payload['smoke_documentation']}\n";
}

exit($payload['all_ok'] ? 0 : 2);
