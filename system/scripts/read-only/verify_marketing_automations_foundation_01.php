<?php

declare(strict_types=1);

/**
 * MARKETING-AUTOMATIONS-MIGRATION-AND-GRACEFUL-BOOT-HOTFIX-01 readiness proof.
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_automations_foundation_01.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();

$m = $pdo->prepare('SELECT migration FROM migrations WHERE migration = ? LIMIT 1');
$m->execute(['099_marketing_automations_foundation.sql']);
$migrationRecorded = $m->fetch(\PDO::FETCH_ASSOC) ?: null;

$t = $pdo->prepare(
    'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
);
$t->execute(['marketing_automations']);
$tableExists = $t->fetch(\PDO::FETCH_ASSOC) ?: null;

$u = $pdo->prepare(
    "SELECT INDEX_NAME
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
       AND INDEX_NAME = ?
     LIMIT 1"
);
$u->execute(['marketing_automations', 'uq_marketing_automation_branch_key']);
$uniqueExists = $u->fetch(\PDO::FETCH_ASSOC) ?: null;

echo 'migration_recorded=' . ($migrationRecorded ? 'yes' : 'no') . PHP_EOL;
echo 'table_exists=' . ($tableExists ? 'yes' : 'no') . PHP_EOL;
echo 'unique_branch_key_exists=' . ($uniqueExists ? 'yes' : 'no') . PHP_EOL;

$keys = array_keys(\Modules\Marketing\Services\MarketingAutomationService::catalog());
echo 'catalog_keys=' . implode(',', $keys) . PHP_EOL;

