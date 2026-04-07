<?php

declare(strict_types=1);

/**
 * Static read-only verifier: services trash / bulk permanent delete parity with staff hardening.
 *
 *   php system/scripts/read-only/verify_services_trash_permanent_delete_readonly_01.php
 */

$repoRoot = dirname(__DIR__, 3);
$system   = $repoRoot . '/system';

$routes = (string) file_get_contents($system . '/routes/web/register_services_resources.php');
$ctl    = (string) file_get_contents($system . '/modules/services-resources/controllers/ServiceController.php');
$svc    = (string) file_get_contents($system . '/modules/services-resources/services/ServiceService.php');
$repo   = (string) file_get_contents($system . '/modules/services-resources/repositories/ServiceRepository.php');
$httpPf = (string) file_get_contents($system . '/scripts/dev-only/smoke_services_trash_http_permanent_delete_proof_01.php');
$bulkOut = (string) file_get_contents($system . '/scripts/dev-only/smoke_services_bulk_permanent_outcome_01.php');

$checks = [
    'Routes: bulk-permanent-delete + permanent-delete' =>
        str_contains($routes, 'bulk-permanent-delete')
        && str_contains($routes, 'permanentDelete'),
    'ServiceRepository hardDeleteTrashed physical DELETE on trashed' =>
        str_contains($repo, 'DELETE') && str_contains($repo, 'hardDeleteTrashed')
        && str_contains($repo, 'deleted_at IS NOT NULL'),
    'ServiceService bulkPermanentlyDelete returns deleted + blocked' =>
        str_contains($svc, "'deleted'")
        && str_contains($svc, "'blocked'")
        && str_contains($svc, 'serviceLabelForBulkOutcome'),
    'ServiceService transactional maps service permanent delete surprises' =>
        str_contains($svc, "'service permanent delete'")
        && str_contains($svc, 'related records still exist'),
    'ServiceController bulk partial flash helpers' =>
        str_contains($ctl, 'formatBulkPermanentPartialSummary')
        && str_contains($ctl, 'formatBulkPermanentAllBlockedSummary'),
    'HTTP services permanent-delete proof orchestrator' =>
        str_contains($httpPf, '_services_trash_http_proof_case.php'),
    'Services bulk outcome CLI proof' =>
        str_contains($bulkOut, 'bulkPermanentlyDelete'),
];

$fail = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail = true;
    }
}

exit($fail ? 1 : 0);
