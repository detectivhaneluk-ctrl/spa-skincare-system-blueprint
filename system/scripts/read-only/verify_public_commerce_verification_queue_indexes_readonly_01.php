<?php

declare(strict_types=1);

/**
 * PUBLIC-COMMERCE-QUEUE-INDEX-HARDENING-01 — static contract + optional EXPLAIN evidence for staff verification queue indexes.
 *
 * Hot SQL: {@see \Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::listAwaitingVerificationWithInvoices}
 *
 * Static checks (always run): migration 117 + full_project_schema + repository ORDER BY use verification_queue_sort_at.
 *
 * Optional runtime: with working DB from .env, runs EXPLAIN on parametrized queue-shaped SELECTs and expects
 * `public_commerce_purchases` row to reference idx_pc_verification_queue_branch_status (branch path) or
 * idx_pc_verification_queue_status_sort (org path). If DB unavailable or migration not applied, prints SKIP (exit 0).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_public_commerce_verification_queue_indexes_readonly_01.php
 *
 * Exit: 0 = pass (or SKIP runtime), 1 = fail static or runtime proof failure.
 */

$system = dirname(__DIR__, 2);
$m117 = $system . '/data/migrations/117_public_commerce_verification_queue_indexes.sql';
$schema = $system . '/data/full_project_schema.sql';
$repoPath = $system . '/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php';

$m117Body = is_file($m117) ? (string) file_get_contents($m117) : '';
$schemaBody = is_file($schema) ? (string) file_get_contents($schema) : '';
$repo = is_file($repoPath) ? (string) file_get_contents($repoPath) : '';

$checks = [];

$checks['Migration 117 exists and names task'] = $m117Body !== ''
    && str_contains($m117Body, 'PUBLIC-COMMERCE-QUEUE-INDEX-HARDENING-01')
    && str_contains($m117Body, 'verification_queue_sort_at')
    && str_contains($m117Body, 'idx_pc_verification_queue_branch_status')
    && str_contains($m117Body, 'idx_pc_verification_queue_status_sort');

$checks['Migration 117: STORED generated column matches legacy COALESCE sort'] = str_contains(
    $m117Body,
    'GENERATED ALWAYS AS (COALESCE(finalize_last_received_at, updated_at)) STORED'
);

$checks['full_project_schema: verification_queue_sort_at + both queue indexes'] = str_contains($schemaBody, 'verification_queue_sort_at')
    && str_contains($schemaBody, 'idx_pc_verification_queue_branch_status')
    && str_contains($schemaBody, 'idx_pc_verification_queue_status_sort');

$checks['PublicCommercePurchaseRepository: ORDER BY verification_queue_sort_at (not inline COALESCE)'] =
    str_contains($repo, 'ORDER BY p.verification_queue_sort_at DESC, p.id DESC')
    && !str_contains($repo, 'ORDER BY COALESCE(p.finalize_last_received_at, p.updated_at)');

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Static verification queue index contract passed.' . PHP_EOL;

// Optional EXPLAIN (evidence path; non-fatal if DB missing)
$explainBranchSql = <<<'SQL'
EXPLAIN SELECT p.id
FROM public_commerce_purchases p
INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
WHERE p.status = 'awaiting_verification'
  AND i.status <> 'cancelled'
  AND p.branch_id = ?
ORDER BY p.verification_queue_sort_at DESC, p.id DESC
LIMIT 10
SQL;

$explainOrgSql = <<<'SQL'
EXPLAIN SELECT p.id
FROM public_commerce_purchases p
INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
WHERE p.status = 'awaiting_verification'
  AND i.status <> 'cancelled'
  AND EXISTS (
    SELECT 1 FROM branches b
    INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
    WHERE b.id = p.branch_id AND b.deleted_at IS NULL AND o.id = ?
  )
ORDER BY p.verification_queue_sort_at DESC, p.id DESC
LIMIT 10
SQL;

try {
    require_once $system . '/core/app/helpers.php';
    \Core\App\Env::load($system);
    $config = new \Core\App\Config($system . '/config');
    $db = new \Core\App\Database($config);
    $rowsB = $db->fetchAll($explainBranchSql, [1]);
    $rowsO = $db->fetchAll($explainOrgSql, [1]);
    $pcKeyB = explainPurchaseRowSummary($rowsB);
    $pcKeyO = explainPurchaseRowSummary($rowsO);
    echo PHP_EOL . 'Runtime EXPLAIN (branch_id=1) row `p`: key=' . ($pcKeyB['key'] ?? '(null)') . ' possible_keys=' . ($pcKeyB['possible_keys'] ?? '(null)') . PHP_EOL;
    echo 'Runtime EXPLAIN (org EXISTS) row `p`: key=' . ($pcKeyO['key'] ?? '(null)') . ' possible_keys=' . ($pcKeyO['possible_keys'] ?? '(null)') . PHP_EOL;
    $branchOk = explainRowUsesIndex($pcKeyB, 'idx_pc_verification_queue_branch_status');
    $orgOk = explainRowUsesIndex($pcKeyO, 'idx_pc_verification_queue_status_sort');
    if (!$branchOk || !$orgOk) {
        fwrite(STDERR, 'EXPLAIN proof: expected branch index on branch path and status_sort index on org path.' . PHP_EOL);
        fwrite(STDERR, 'If migration 117 is not applied yet, run migrations then re-run.' . PHP_EOL);
        exit(1);
    }
    echo 'EXPLAIN proof: OK (indexable access on p for both shapes).' . PHP_EOL;
} catch (\Throwable $e) {
    echo PHP_EOL . 'Runtime EXPLAIN: SKIP (' . $e->getMessage() . ')' . PHP_EOL;
}

exit(0);

/**
 * @param list<array<string, mixed>> $explainRows
 * @return array{key: ?string, possible_keys: ?string}
 */
function explainPurchaseRowSummary(array $explainRows): array
{
    $out = ['key' => null, 'possible_keys' => null];
    foreach ($explainRows as $row) {
        $tbl = isset($row['table']) ? (string) $row['table'] : '';
        if ($tbl !== 'p') {
            continue;
        }
        $k = $row['key'] ?? null;
        $out['key'] = ($k !== null && $k !== '') ? (string) $k : null;
        $pk = $row['possible_keys'] ?? null;
        $out['possible_keys'] = ($pk !== null && $pk !== '') ? (string) $pk : null;
        break;
    }

    return $out;
}

/**
 * @param array{key: ?string, possible_keys: ?string} $summary
 */
function explainRowUsesIndex(array $summary, string $indexName): bool
{
    $k = $summary['key'] ?? '';
    if ($k !== null && $k !== '' && str_contains($k, $indexName)) {
        return true;
    }
    $pk = $summary['possible_keys'] ?? '';

    return $pk !== null && $pk !== '' && str_contains($pk, $indexName);
}
