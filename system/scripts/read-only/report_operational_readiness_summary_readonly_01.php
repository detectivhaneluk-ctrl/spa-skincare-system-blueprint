<?php

declare(strict_types=1);

/**
 * Runs key read-only verifiers/reports for operational hardening (non-destructive).
 *
 * From repository root:
 *   php system/scripts/read-only/report_operational_readiness_summary_readonly_01.php
 *
 * Exit: 0 if all subprocesses exit 0, else 1.
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$php = PHP_BINARY !== '' && is_executable(PHP_BINARY) ? PHP_BINARY : 'php';

$steps = [
    ['Elite backend maturity anchors', 'system/scripts/read-only/verify_elite_backend_maturity_anchors_readonly_01.php'],
    ['Structured logging hotspots', 'system/scripts/read-only/report_structured_logging_hotspots_readonly_01.php'],
    ['Shared cache fallback + metrics anchors', 'system/scripts/read-only/verify_shared_cache_fallback_visibility_readonly_01.php'],
    ['Deployment docroot hardening', 'system/scripts/read-only/verify_deployment_docroot_hardening_readonly_01.php'],
    ['PAGE_EXPIRED HTTP mapping', 'system/scripts/read-only/verify_page_expired_http_mapping_readonly_01.php'],
    ['SQL identifier safety', 'system/scripts/read-only/verify_sql_identifier_safety_readonly_01.php'],
    ['API JSON error contract', 'system/scripts/read-only/report_api_json_error_contract_readonly_01.php'],
    ['Session runtime configuration', 'system/scripts/read-only/verify_session_runtime_configuration_readonly_01.php'],
    ['Session ini after bootstrap (masked)', 'system/scripts/read-only/verify_session_ini_after_bootstrap_readonly_01.php'],
    ['Runtime jobs execution registry', 'system/scripts/read-only/verify_runtime_jobs_execution_registry_readonly_01.php'],
    ['Image pipeline runtime health (queue + worker heartbeat)', 'system/scripts/read-only/report_image_pipeline_runtime_health_readonly_01.php'],
    ['Backend health consolidated (OBS-FND-01: session, storage, registry, image, cache)', 'system/scripts/read-only/report_backend_health_critical_readonly_01.php'],
    ['Tenant closure wave FND-TNT-07 (purchase + membership_sale UPDATE)', 'system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php'],
    ['Tenant closure wave FND-TNT-08 (membership_sale find/findForUpdate/blocking)', 'system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php'],
    ['Tenant closure wave FND-TNT-09 (refund-review lists + invoice-branch definitions)', 'system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php'],
    ['Storage abstraction wave STG-03 (checksum + stream validation)', 'system/scripts/read-only/verify_foundation_storage_abstraction_wave_03_readonly_01.php'],
    ['Storage abstraction wave 01 (local provider + Tier A callers)', 'system/scripts/read-only/verify_foundation_storage_abstraction_wave_01_readonly_01.php'],
];

$overall = 0;
echo "=== FINAL-ELITE-BACKEND-MATURITY-WAVE-01 — operational readiness toolkit (read-only) ===\n\n";

foreach ($steps as [$label, $rel]) {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    echo "--- {$label} ---\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($path);
    passthru($cmd, $code);
    echo "\n";
    if ($code !== 0) {
        $overall = 1;
    }
}

echo "Optional (needs DB): php system/scripts/read-only/report_shared_cache_operational_readonly_01.php\n";
echo "Optional (log digest): php system/scripts/read-only/report_structured_log_operator_digest_readonly_01.php --file=...\n";
echo "Optional (log tail): php system/scripts/read-only/report_structured_log_recent_readonly_01.php --file=...\n";

echo "\n--- Justified residual caveats (post-check) ---\n";
echo "- Primary log transport remains PHP error_log (JSON lines); ship stderr/file to your aggregator.\n";
echo "- Shared cache metrics are per PHP process; use for probes and relative health, not long-window SLIs without an external sink.\n";
echo "- Root .htaccess deny rules are Apache-only; Nginx requires explicit location blocks (see deployment snippets).\n";
echo "- APP_ENV production blocks repository root index.php only; mis-set docroot can still leak static files if rules are absent.\n";

exit($overall);
