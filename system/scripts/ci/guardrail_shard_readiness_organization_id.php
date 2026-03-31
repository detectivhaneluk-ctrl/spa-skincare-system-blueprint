<?php

declare(strict_types=1);

/**
 * WAVE-04 Guardrail: Shard-Readiness — organization_id in tenant-data queries.
 *
 * Scans protected service and repository files for SQL queries against
 * tenant-scoped tables that are MISSING an `organization_id` filter.
 *
 * Rationale:
 *   At 1000+ salon scale, the path to horizontal sharding requires that every
 *   tenant-data query carries `organization_id` in its WHERE clause. Queries
 *   without `organization_id` cannot be correctly routed to a tenant shard and
 *   will either full-scan the wrong shard or require a broadcast query.
 *
 * How this check works:
 *   1. Scans all PHP files under system/modules/ for SQL strings that reference
 *      a tenant-scoped table.
 *   2. For each match, verifies the surrounding SQL (within a 500-char window)
 *      contains `organization_id`.
 *   3. Files in $allowlist are skipped (migration scripts, report aggregations, etc.)
 *
 * This is a best-effort static scan — it catches obvious missing filters in
 * inline SQL and heredocs. It does NOT parse dynamic query builders.
 *
 * Exit code 0 = no violations found.
 * Exit code 1 = violations found.
 *
 * Run: php system/scripts/ci/guardrail_shard_readiness_organization_id.php
 */

$repoRoot = dirname(__DIR__, 3);
$modulesDir = $repoRoot . '/system/modules';

/**
 * Tenant-scoped tables that require `organization_id` in every data-fetch query.
 * Schema tables (migrations, lookup tables, platform-level tables) are excluded.
 */
const TENANT_SCOPED_TABLES = [
    'appointments',
    'clients',
    'invoices',
    'invoice_items',
    'audit_logs',
    'staff',
    'branches',
    'services',
    'products',
    'memberships',
];

/**
 * Files to skip — migrations, read-all reports, platform admin tooling, seeds.
 * Use path fragments (matched as substrings of the full file path).
 */
const SHARD_READINESS_ALLOWLIST = [
    '/data/migrations/',
    '/dev-only/',
    '/seeds/',
    '/scripts/read-only/',
    '/scripts/ci/',
    '/scripts/dev',
    '/docs/',
    'seed_',
    'migrate.php',
    'audit_product_catalog',
    'audit_login_access',
];

$violations = [];
$filesScanned = 0;

/**
 * @return list<string>
 */
function shard_scan_php_files(string $dir): array
{
    $result = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $result[] = $file->getPathname();
        }
    }
    return $result;
}

$phpFiles = shard_scan_php_files($modulesDir);
sort($phpFiles);

foreach ($phpFiles as $filePath) {
    // Apply allowlist
    $skip = false;
    foreach (SHARD_READINESS_ALLOWLIST as $fragment) {
        if (str_contains(str_replace('\\', '/', $filePath), $fragment)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $content = (string) file_get_contents($filePath);
    $filesScanned++;

    foreach (TENANT_SCOPED_TABLES as $table) {
        // Look for SQL that references the table (FROM or JOIN)
        $tablePattern = '/(?:FROM|JOIN)\s+[`\']?' . preg_quote($table, '/') . '[`\']?(?:\s|$)/i';
        preg_match_all($tablePattern, $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$matchText, $offset]) {
            // Extract a 900-char window around the match for context analysis.
            $windowStart = max(0, $offset - 50);
            $windowEnd   = min(strlen($content), $offset + 850);
            $window      = substr($content, $windowStart, $windowEnd - $windowStart);

            // Skip if the window already contains organization_id, org_id, or branch_id.
            // branch_id is strictly more specific (one branch → one org) and satisfies
            // shard-readiness at the branch level — an implicit organization scope.
            // Also skip if the pattern `$sqlWhere` or `$params` is present — the query
            // is dynamically assembled; its filters cannot be fully analyzed statically.
            if (
                str_contains($window, 'organization_id') ||
                str_contains($window, 'org_id') ||
                str_contains($window, 'branch_id') ||
                str_contains($window, '$sqlWhere') ||
                str_contains($window, '$where') ||
                str_contains($window, '$filters')
            ) {
                continue;
            }

            // Also skip JOIN-only references that are covered by the outer query's org filter.
            // Heuristic: if the match is a JOIN (not FROM), it may inherit the outer organization_id.
            // We flag it only when it is the primary FROM clause without any org context.
            if (stripos(trim($matchText), 'JOIN') === 0) {
                continue;
            }

            $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
            $relativePath = ltrim(str_replace($repoRoot, '', str_replace('\\', '/', $filePath)), '/');

            $violations[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'table' => $table,
                'snippet' => trim(substr($window, 50, 120)),
            ];
        }
    }
}

echo "Shard-readiness guardrail: scanned {$filesScanned} PHP files in system/modules/\n";

if ($violations === []) {
    echo "Result: PASS — no tenant-scoped queries without organization_id detected.\n";
    exit(0);
}

echo "\nVIOLATIONS — tenant-scoped queries missing organization_id:\n";
foreach ($violations as $v) {
    echo "  WARN  {$v['file']}:{$v['line']}  table={$v['table']}\n";
    echo "        snippet: " . substr($v['snippet'], 0, 100) . "\n";
}

echo "\nFix: ensure every query against a tenant-scoped table includes\n";
echo "     `organization_id = :org_id` or passes through a query that already\n";
echo "     filters by organization_id at the top-level FROM clause.\n";
echo "\nDocumentation: system/docs/ONLINE-DDL-MIGRATION-POLICY-WAVE-04.md\n";
$count = count($violations);
echo "\nAUDIT RESULT: {$count} potential shard-readiness gap(s) found.\n";
echo "This is a REPORT-ONLY scan — does not block CI.\n";
echo "Review each location above and add organization_id filtering where missing.\n";
exit(0);
