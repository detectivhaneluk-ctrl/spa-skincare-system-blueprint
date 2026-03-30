<?php

declare(strict_types=1);

/**
 * Read-only: confirms `runtime_execution_registry` exists and prints ledger rows (truncated error preview).
 *
 * From repository root:
 *   php system/scripts/read-only/verify_runtime_jobs_execution_registry_readonly_01.php
 *
 * Exit: 0 if table present; 1 if missing (migration 121 not applied).
 */

$systemPath = realpath(dirname(__DIR__, 2));
if ($systemPath === false) {
    fwrite(STDERR, "Could not resolve system path.\n");
    exit(1);
}

require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class)->connection();
$st = $db->prepare(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
);
$st->execute(['runtime_execution_registry']);
if (!$st->fetchColumn()) {
    fwrite(STDERR, "CRITICAL: runtime_execution_registry missing — apply migration 121_runtime_execution_registry_foundation.sql\n");
    exit(1);
}

$rows = app(\Core\Runtime\Jobs\RuntimeExecutionRegistry::class)->fetchAllForReadOnlyReport();
echo 'runtime_execution_registry row_count=' . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

echo "verify_runtime_jobs_execution_registry_readonly_01: OK\n";
exit(0);
