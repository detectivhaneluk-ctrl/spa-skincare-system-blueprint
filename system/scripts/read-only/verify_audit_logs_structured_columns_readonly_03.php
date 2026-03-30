<?php

declare(strict_types=1);

/**
 * FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03 — audit_logs structured columns + migration 125 truth.
 *
 *   php system/scripts/read-only/verify_audit_logs_structured_columns_readonly_03.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

$m = $root . '/data/migrations/125_audit_logs_structured_tenant_context.sql';
if (!is_readable($m)) {
    $fail[] = 'Missing migration 125';
} else {
    $t = (string) file_get_contents($m);
    foreach (['request_id', 'organization_id', 'outcome', 'action_category', 'fk_audit_logs_organization'] as $n) {
        if (!str_contains($t, $n)) {
            $fail[] = "Migration 125 should reference {$n}";
        }
    }
}

$schema = $root . '/data/full_project_schema.sql';
if (is_readable($schema)) {
    $s = (string) file_get_contents($schema);
    foreach (['request_id', 'organization_id', 'outcome', 'action_category', 'idx_audit_logs_org_created'] as $n) {
        if (!str_contains($s, $n)) {
            $fail[] = "full_project_schema audit_logs should include {$n}";
        }
    }
}

$audit = $root . '/core/audit/AuditService.php';
if (!is_readable($audit)) {
    $fail[] = 'Missing AuditService';
} else {
    $a = (string) file_get_contents($audit);
    foreach (['RequestCorrelation', 'resolveOrganizationId', 'inferActionCategory', 'outcome'] as $n) {
        if (!str_contains($a, $n)) {
            $fail[] = "AuditService should reference {$n}";
        }
    }
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL audit logs structured readonly 03:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_audit_logs_structured_columns_readonly_03\n";
