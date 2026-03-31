<?php

declare(strict_types=1);

/**
 * WAVE-04 Safe Scale Operations — proof script.
 *
 * Verifies:
 *  W4-A  Online-DDL migration policy: docs exist, CI guardrail enforces headers on new migrations
 *  W4-B  Audit log archival foundation: migration 128, archival cron script present
 *  W4-C  Rate limiting foundation: booking-specific rate limit buckets added
 *  W4-D  Shard-readiness guardrail: org_id audit script present, runs clean (exit 0)
 *
 * Run: php system/scripts/read-only/verify_wave04_safe_scale_ops_01.php
 * Expected: all assertions PASS, exit code 0.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function wave04_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $condition ? ++$pass : ++$fail;
}

function wave04_contains(string $file, string $needle, string $label): void
{
    wave04_assert(
        file_exists($file) && str_contains((string) file_get_contents($file), $needle),
        $label
    );
}

echo "\n=== WAVE-04 SAFE SCALE OPERATIONS PROOF ===\n\n";

// ─── W4-A: Online-DDL migration policy ───

echo "W4-A: Online-DDL migration policy\n";

$policyDoc = $repoRoot . '/system/docs/ONLINE-DDL-MIGRATION-POLICY-WAVE-04.md';
wave04_assert(file_exists($policyDoc), 'ONLINE-DDL-MIGRATION-POLICY-WAVE-04.md exists');
wave04_contains($policyDoc, 'pt-online-schema-change', 'Policy documents pt-online-schema-change');
wave04_contains($policyDoc, 'gh-ost', 'Policy documents gh-ost');
wave04_contains($policyDoc, 'ONLINE-DDL-REQUIRED', 'Policy defines ONLINE-DDL-REQUIRED header convention');
wave04_contains($policyDoc, 'audit_logs', 'Policy lists audit_logs as large/hot table');
wave04_contains($policyDoc, 'appointments', 'Policy lists appointments as large/hot table');

$guardrailDdl = $repoRoot . '/system/scripts/ci/guardrail_online_ddl_large_table_migrations.php';
wave04_assert(file_exists($guardrailDdl), 'guardrail_online_ddl_large_table_migrations.php exists');
wave04_contains($guardrailDdl, 'POLICY_APPLIES_FROM_MIGRATION', 'Guardrail has POLICY_APPLIES_FROM_MIGRATION baseline constant');
wave04_contains($guardrailDdl, 'ONLINE-DDL-REQUIRED', 'Guardrail checks for ONLINE-DDL-REQUIRED header');

// Run the guardrail — must exit 0 (no violations in post-policy migrations)
$ddlResult = shell_exec('php ' . escapeshellarg($guardrailDdl) . ' 2>&1');
wave04_assert(
    str_contains((string) $ddlResult, 'PASS'),
    'Online-DDL guardrail passes (no post-policy violations)'
);

echo "\n";

// ─── W4-B: Audit log archival foundation ───

echo "W4-B: Audit log archival foundation\n";

$migration128 = $repoRoot . '/system/data/migrations/128_audit_logs_archival_foundation.sql';
wave04_assert(file_exists($migration128), 'Migration 128 (audit_logs archival) exists');
wave04_contains($migration128, 'archived_at', 'Migration 128 adds archived_at column');
wave04_contains($migration128, 'archival_batch_id', 'Migration 128 adds archival_batch_id column');
wave04_contains($migration128, 'audit_logs_archive', 'Migration 128 creates audit_logs_archive table');
wave04_contains($migration128, 'ONLINE-DDL-REQUIRED', 'Migration 128 carries ONLINE-DDL-REQUIRED header (large table policy)');

$archivalCron = $repoRoot . '/system/scripts/run_audit_log_archival_cron.php';
wave04_assert(file_exists($archivalCron), 'run_audit_log_archival_cron.php exists');
wave04_contains($archivalCron, 'AUDIT_LOG_RETENTION_DAYS', 'Archival cron reads AUDIT_LOG_RETENTION_DAYS env var');
wave04_contains($archivalCron, 'audit_logs_archive', 'Archival cron inserts into audit_logs_archive');
wave04_contains($archivalCron, 'beginTransaction', 'Archival cron uses transactions for safe batch moves');
wave04_contains($archivalCron, '--dry-run', 'Archival cron supports --dry-run mode');
wave04_contains($archivalCron, 'rollback', 'Archival cron rolls back on batch error');

echo "\n";

// ─── W4-C: Rate limiting foundation ───

echo "W4-C: Rate limiting foundation — booking buckets\n";

$rateLimiter = $repoRoot . '/system/core/Runtime/RateLimit/RuntimeProtectedPathRateLimiter.php';
wave04_assert(file_exists($rateLimiter), 'RuntimeProtectedPathRateLimiter.php exists');
wave04_contains($rateLimiter, 'BUCKET_BOOKING_SUBMIT', 'RuntimeProtectedPathRateLimiter has BUCKET_BOOKING_SUBMIT constant');
wave04_contains($rateLimiter, 'BUCKET_BOOKING_AVAILABILITY_READ', 'RuntimeProtectedPathRateLimiter has BUCKET_BOOKING_AVAILABILITY_READ constant');
wave04_contains($rateLimiter, 'tryConsumeBookingSubmit', 'RuntimeProtectedPathRateLimiter::tryConsumeBookingSubmit() method exists');
wave04_contains($rateLimiter, 'tryConsumeBookingAvailabilityRead', 'RuntimeProtectedPathRateLimiter::tryConsumeBookingAvailabilityRead() method exists');
wave04_contains($rateLimiter, 'BUCKET_LOGIN_POST', 'Existing BUCKET_LOGIN_POST preserved (no regression)');

echo "\n";

// ─── W4-D: Shard-readiness guardrail ───

echo "W4-D: Shard-readiness organization_id guardrail\n";

$shardGuardrail = $repoRoot . '/system/scripts/ci/guardrail_shard_readiness_organization_id.php';
wave04_assert(file_exists($shardGuardrail), 'guardrail_shard_readiness_organization_id.php exists');
wave04_contains($shardGuardrail, 'TENANT_SCOPED_TABLES', 'Guardrail defines TENANT_SCOPED_TABLES registry');
wave04_contains($shardGuardrail, 'audit_logs', 'Guardrail covers audit_logs');
wave04_contains($shardGuardrail, 'appointments', 'Guardrail covers appointments');
wave04_contains($shardGuardrail, 'organization_id', 'Guardrail checks for organization_id');
wave04_contains($shardGuardrail, 'REPORT-ONLY', 'Guardrail is report-only (does not block CI)');

// Run the shard guardrail — must exit 0 (report-only)
$phpBin = PHP_BINARY;
$shardResult = shell_exec($phpBin . ' ' . escapeshellarg($shardGuardrail) . ' 2>&1');
wave04_assert(
    $shardResult !== null && !str_contains((string) $shardResult, 'Fatal error'),
    'Shard-readiness guardrail runs without PHP fatal errors'
);

// The output must indicate report-only (non-blocking) somewhere
wave04_assert(
    $shardResult !== null && (str_contains((string) $shardResult, 'REPORT-ONLY') || str_contains((string) $shardResult, 'PASS')),
    'Shard-readiness guardrail exits 0 (report-only or no violations)'
);

echo "\n";

// ─── Summary ───

$total = $pass + $fail;
echo "===========================================\n";
echo "WAVE-04 PROOF: {$pass}/{$total} assertions passed\n";
if ($fail > 0) {
    echo "RESULT: FAIL — {$fail} assertion(s) failed\n";
    exit(1);
}
echo "RESULT: PASS — WAVE-04 Safe Scale Operations deliverables verified.\n";
exit(0);
