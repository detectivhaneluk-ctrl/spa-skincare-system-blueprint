<?php

declare(strict_types=1);

/**
 * FOUNDATION-11 — read-only inventory: minimal org-scoped enforcement on accepted choke points.
 *
 * Usage (from `system/`):
 *   php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php
 *   php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php --json
 *
 * Exit codes:
 *   0 — all mapped mutating paths contain OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization
 *   1 — system path resolution failure
 *   2 — one or more expectations failed
 */

$systemPath = dirname(__DIR__);
$json = in_array('--json', array_slice($argv, 1), true);

/**
 * @return list<array{file:string,class:string,method:string}>
 */
function foundation11OrgGuardExpectations(string $systemPath): array
{
    $base = $systemPath . DIRECTORY_SEPARATOR;
    return [
        ['file' => $base . 'core' . DIRECTORY_SEPARATOR . 'Branch' . DIRECTORY_SEPARATOR . 'BranchDirectory.php', 'class' => 'BranchDirectory', 'method' => 'updateBranch'],
        ['file' => $base . 'core' . DIRECTORY_SEPARATOR . 'Branch' . DIRECTORY_SEPARATOR . 'BranchDirectory.php', 'class' => 'BranchDirectory', 'method' => 'softDeleteBranch'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'InvoiceService.php', 'class' => 'InvoiceService', 'method' => 'create'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'InvoiceService.php', 'class' => 'InvoiceService', 'method' => 'update'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'InvoiceService.php', 'class' => 'InvoiceService', 'method' => 'cancel'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'InvoiceService.php', 'class' => 'InvoiceService', 'method' => 'delete'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'InvoiceService.php', 'class' => 'InvoiceService', 'method' => 'recomputeInvoiceFinancials'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'InvoiceService.php', 'class' => 'InvoiceService', 'method' => 'redeemGiftCardPayment'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'PaymentService.php', 'class' => 'PaymentService', 'method' => 'create'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'sales' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'PaymentService.php', 'class' => 'PaymentService', 'method' => 'refund'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'create'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'update'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'delete'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'addClientNote'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'deleteClientNote'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'mergeClients'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'createCustomFieldDefinition'],
        ['file' => $base . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientService.php', 'class' => 'ClientService', 'method' => 'updateCustomFieldDefinition'],
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

function methodBodyContainsNeedle(string $src, string $method, string $needle): bool
{
    $start = methodDeclOffset($src, $method);
    if ($start === null) {
        return false;
    }
    $end = nextPeerMethodOffset($src, $start);
    $chunk = substr($src, $start, $end - $start);

    return str_contains($chunk, $needle);
}

$needle = 'assertBranchOwnedByResolvedOrganization';
$expectations = foundation11OrgGuardExpectations($systemPath);
$rows = [];
$failed = 0;

foreach ($expectations as $exp) {
    if (!is_file($exp['file'])) {
        $rows[] = [
            'class' => $exp['class'],
            'method' => $exp['method'],
            'guarded' => false,
            'reason' => 'file_missing',
        ];
        ++$failed;
        continue;
    }
    $src = (string) file_get_contents($exp['file']);
    $ok = methodBodyContainsNeedle($src, $exp['method'], $needle);
    if (!$ok) {
        ++$failed;
    }
    $rows[] = [
        'class' => $exp['class'],
        'method' => $exp['method'],
        'guarded' => $ok,
        'reason' => $ok ? null : 'needle_missing_in_method',
    ];
}

$extra = [
    'helper_class' => 'Core\\Organization\\OrganizationScopedBranchAssert',
    'helper_method' => $needle,
    'branch_directory_createBranch' => 'WAVE-02: tenant createBranch() requires branch-derived org context; bootstrap uses createBranchPinningLowestActiveOrganizationWhenContextUnresolved() explicitly.',
    'branch_admin_controller' => 'Catches DomainException from BranchDirectory org/BranchDirectory guards for staff UX.',
];

$payload = [
    'verifier' => 'verify_organization_scoped_choke_points_foundation_11_readonly',
    'wave' => 'MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-11',
    'all_guarded' => $failed === 0,
    'failed_count' => $failed,
    'coverage_rows' => $rows,
    'notes' => $extra,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "verifier: {$payload['verifier']}\n";
    echo 'all_guarded: ' . ($payload['all_guarded'] ? 'true' : 'false') . "\n";
    foreach ($rows as $r) {
        $g = $r['guarded'] ? 'OK' : 'MISSING';
        echo "{$g}\t{$r['class']}::{$r['method']}\n";
    }
    echo "\nnotes:\n";
    foreach ($extra as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
}

exit($failed === 0 ? 0 : 2);
