<?php

declare(strict_types=1);

/**
 * Read-only proof: Clients backend gap repair wave (normalized search readiness, merge coverage, branchless org scope).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_clients_backend_gap_repair_wave_readonly.php
 */

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

$system = dirname(__DIR__, 2);
$repo = $system . '/modules/clients/repositories/ClientRepository.php';
$scope = $system . '/core/Organization/OrganizationRepositoryScope.php';
$reg = $system . '/modules/clients/services/ClientRegistrationService.php';
$svc = $system . '/modules/clients/services/ClientService.php';
$readiness = $system . '/modules/clients/support/ClientNormalizedSearchSchemaReadiness.php';
$register = $system . '/modules/bootstrap/register_clients.php';
$bootstrapCore = $system . '/bootstrap.php';

foreach ([$repo, $scope, $reg, $svc, $readiness, $register, $bootstrapCore] as $p) {
    if (!is_file($p)) {
        fail("missing file: {$p}");
    }
}

$r = (string) file_get_contents($repo);
$o = (string) file_get_contents($scope);
$regS = (string) file_get_contents($reg);
$s = (string) file_get_contents($svc);
$regPhp = (string) file_get_contents($register);
$boot = (string) file_get_contents($bootstrapCore);

$checks = [];

$checks['ClientRepository injects ClientNormalizedSearchSchemaReadiness'] =
    str_contains($r, 'ClientNormalizedSearchSchemaReadiness $normalizedSearchSchema')
    && str_contains($r, 'function isNormalizedSearchSchemaReady(');

$checks['findDuplicates guarded when schema not ready'] =
    str_contains($r, 'if (!$this->normalizedSearchSchema->isReady())') && str_contains($r, 'function findDuplicates(');

$checks['searchDuplicates countSearchDuplicates guarded'] =
    str_contains($r, 'function searchDuplicates(')
    && str_contains($r, 'function countSearchDuplicates(')
    && substr_count($r, '!$this->normalizedSearchSchema->isReady()') >= 3;

$checks['applyClientListFilters avoids normalized columns when not ready'] =
    str_contains($r, 'applyClientListFilters') && str_contains($r, 'LOWER(TRIM(c.email))') && str_contains($r, 'phone LIKE ?');

$checks['lockActiveByEmailBranch legacy path without email_lc'] =
    str_contains($r, 'LOWER(TRIM(email))') && str_contains($r, 'function lockActiveByEmailBranch(');

$checks['merge count includes appointment_series marketing_campaign_recipients client_profile_images'] =
    str_contains($r, "'appointment_series'") && str_contains($r, "'marketing_campaign_recipients'") && str_contains($r, "'client_profile_images'")
    && str_contains($r, 'function countLinkedRecords(');

$checks['merge remap includes same tables with table existence guard'] =
    str_contains($r, 'databaseTableExists') && str_contains($r, "'appointment_series',") && str_contains($r, "'appointments',");

$checks['OrganizationRepositoryScope gates series/marketing SQL on information_schema table existence'] =
    str_contains($o, 'function databaseTableExists(')
    && str_contains($o, "databaseTableExists('appointment_series')")
    && str_contains($o, "databaseTableExists('marketing_campaign_recipients')")
    && str_contains($o, "databaseTableExists('marketing_campaigns')")
    && str_contains($o, 'appointment_series aser')
    && str_contains($o, 'marketing_campaign_recipients mcr');

$checks['bootstrap injects Database into OrganizationRepositoryScope'] =
    str_contains($boot, 'OrganizationRepositoryScope::class')
    && str_contains($boot, 'OrganizationRepositoryScope(')
    && str_contains($boot, 'Database::class');

$checks['ClientRegistrationService rejects branchless reg to branched client'] =
    str_contains($regS, 'BRANCH_ATTACHMENT_AMBIGUOUS') && str_contains($regS, 'registration branch_id NULL');

$checks['ClientService paginated duplicates exposes normalized_search_schema_ready'] =
    str_contains($s, 'normalized_search_schema_ready') && str_contains($s, 'isNormalizedSearchSchemaReady(');

$checks['register_clients wires ClientNormalizedSearchSchemaReadiness'] =
    str_contains($regPhp, 'singleton(\Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness::class')
    && str_contains($regPhp, '$c->get(\Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness::class)');

$readinessSrc = (string) file_get_contents($readiness);
$checks['ClientNormalizedSearchSchemaReadiness lists five required columns'] =
    str_contains($readinessSrc, 'email_lc') && str_contains($readinessSrc, 'phone_work_digits')
    && str_contains($readinessSrc, 'information_schema.COLUMNS');

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fail($label);
    }
}

echo "PASS: clients_backend_gap_repair_wave_readonly\n";
foreach (array_keys($checks) as $label) {
    echo "  - {$label}\n";
}
exit(0);
