<?php

declare(strict_types=1);

/**
 * FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02 — durable queue contract (retry, dead-letter, state) truth.
 *
 *   php system/scripts/read-only/verify_runtime_async_jobs_queue_contract_readonly_02.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

$m = $root . '/data/migrations/124_runtime_async_jobs_foundation.sql';
if (!is_readable($m)) {
    $fail[] = 'Missing migration 124';
} else {
    $t = (string) file_get_contents($m);
    foreach (['runtime_async_jobs', 'dead', 'pending', 'processing', 'max_attempts', 'available_at'] as $n) {
        if (!str_contains($t, $n)) {
            $fail[] = "Migration 124 should mention {$n}";
        }
    }
}

$repo = $root . '/core/Runtime/Queue/RuntimeAsyncJobRepository.php';
if (!is_readable($repo)) {
    $fail[] = 'Missing RuntimeAsyncJobRepository';
} else {
    $r = (string) file_get_contents($repo);
    foreach (['markFailedRetryOrDead', 'markSucceeded', 'reserveNext', 'STATUS_DEAD', 'STALE_PROCESSING_SECONDS'] as $n) {
        if (!str_contains($r, $n)) {
            $fail[] = "RuntimeAsyncJobRepository should contain {$n}";
        }
    }
}

$worker = $root . '/scripts/worker_runtime_async_jobs_cli_02.php';
if (!is_readable($worker) || !str_contains((string) file_get_contents($worker), 'RuntimeAsyncJobRepository')) {
    $fail[] = 'worker_runtime_async_jobs_cli_02.php must use RuntimeAsyncJobRepository';
}

$schema = $root . '/data/full_project_schema.sql';
if (is_readable($schema) && !str_contains((string) file_get_contents($schema), 'CREATE TABLE runtime_async_jobs')) {
    $fail[] = 'full_project_schema.sql should define runtime_async_jobs';
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL queue contract readonly 02:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_runtime_async_jobs_queue_contract_readonly_02\n";
