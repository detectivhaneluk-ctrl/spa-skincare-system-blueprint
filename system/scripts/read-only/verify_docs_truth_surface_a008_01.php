<?php

declare(strict_types=1);

/**
 * A-008 read-only: canonical maintainer truth surface exists; sales proofs track current layout.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_docs_truth_surface_a008_01.php
 */
$repoRoot = dirname(__DIR__, 3);

function src(string $relativeFromRepoRoot): string
{
    global $repoRoot;

    return (string) file_get_contents($repoRoot . '/' . $relativeFromRepoRoot);
}

$maintainer = src('system/docs/MAINTAINER-RUNTIME-TRUTH.md');
$rootReadme = src('README.md');
$sysReadme = src('system/README.md');
$bpIndex = src('archive/blueprint-reference/00-INDEX.md');
$archSum = src('archive/blueprint-reference/ARCHITECTURE-SUMMARY.md');
$cursorReadme = src('archive/cursor-context/README.md');
$proofSales = src('system/scripts/read-only/proof_sales_index_booker_style_home_surface_01.php');
$proofVis = src('system/scripts/read-only/proof_sales_index_to_cashier_visibility_fix_01.php');

$checks = [
    'MAINTAINER-RUNTIME-TRUTH.md exists and names archive/' => str_contains($maintainer, 'archive/blueprint-reference/')
        && str_contains($maintainer, 'archive/cursor-context/')
        && str_contains($maintainer, 'system/scripts/read-only'),
    'Root README points to system/ truth + archive' => str_contains($rootReadme, 'system/docs/MAINTAINER-RUNTIME-TRUTH.md')
        && str_contains($rootReadme, 'archive/blueprint-reference/'),
    'system/README links maintainer index' => str_contains($sysReadme, 'docs/MAINTAINER-RUNTIME-TRUTH.md'),
    'archive blueprint 00-INDEX marked ARCHIVAL' => str_contains($bpIndex, 'ARCHIVAL')
        && str_contains($bpIndex, 'NOT AUTHORITATIVE'),
    'ARCHITECTURE-SUMMARY marked archival package' => str_contains($archSum, 'ARCHIVAL PACKAGE')
        && str_contains($archSum, 'MAINTAINER-RUNTIME-TRUTH.md'),
    'archive/cursor-context/README is non-authoritative' => str_contains($cursorReadme, 'Not authoritative'),
    'proof_sales_index_booker asserts sales subnav IA' => str_contains($proofSales, 'workspace_subnav_links_present')
        && str_contains($proofSales, 'href="/sales/register"'),
    'proof_sales_index_to_cashier matches redirect + subnav' => str_contains($proofVis, 'sales_controller_redirects_to_orders_list')
        && str_contains($proofVis, 'workspace_subnav_sales_ia'),
    'MAINTAINER lists M-007 base_path truth' => str_contains($maintainer, 'M-007')
        && str_contains($maintainer, 'base_path'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
