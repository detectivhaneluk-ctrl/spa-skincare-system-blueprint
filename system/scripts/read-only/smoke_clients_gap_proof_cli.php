<?php

declare(strict_types=1);

/**
 * Minimal CLI smoke (DB + DI): normalized readiness, optional table presence,
 * {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()} builds without fatal.
 *
 * Run from repo root: php system/scripts/read-only/smoke_clients_gap_proof_cli.php
 */

$system = dirname(__DIR__, 2);
chdir($system);
require $system . '/bootstrap.php';
require $system . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$norm = app(\Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness::class);
echo 'normalized_columns_ready=' . ($norm->isReady() ? '1' : '0') . PHP_EOL;

foreach (['appointment_series', 'marketing_campaign_recipients', 'marketing_campaigns', 'clients'] as $t) {
    $row = $db->fetchOne(
        'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$t]
    );
    echo 'table_' . $t . '=' . ($row !== null ? '1' : '0') . PHP_EOL;
}

$ctx = app(\Core\Organization\OrganizationContext::class);
$ctx->setFromResolution(1, \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED);
$scope = app(\Core\Organization\OrganizationRepositoryScope::class);
$frag = $scope->clientProfileOrgMembershipExistsClause('c');
echo 'org_clause_sql_bytes=' . strlen($frag['sql']) . PHP_EOL;
echo 'org_clause_param_count=' . count($frag['params']) . PHP_EOL;
echo 'SMOKE_CLI_OK' . PHP_EOL;
