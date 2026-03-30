<?php

declare(strict_types=1);

/**
 * MARKETING-CONTACT-LISTS-BACKEND-FOUNDATION-01 verifier.
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_contact_lists_backend_foundation_01.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';

$db = app(\Core\App\Database::class);
$pdo = $db->connection();

$routeFile = $base . '/routes/web/register_marketing.php';
$routeText = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
echo 'route_contact_lists_page_exists=' . (str_contains($routeText, "/marketing/contact-lists'") ? 'yes' : 'no') . PHP_EOL;
echo 'route_contact_lists_audience_exists=' . (str_contains($routeText, "/marketing/contact-lists/audience'") ? 'yes' : 'no') . PHP_EOL;
echo 'route_contact_lists_manual_create_exists=' . (str_contains($routeText, "/marketing/contact-lists/manual-lists/create'") ? 'yes' : 'no') . PHP_EOL;

$controllerFile = $base . '/modules/marketing/controllers/MarketingContactListsController.php';
$controllerText = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
echo 'controller_default_all_contacts_fallback=' . (str_contains($controllerText, "AUDIENCE_ALL_CONTACTS") ? 'yes' : 'no') . PHP_EOL;
echo 'controller_selected_state_contract_manual_colon=' . (str_contains($controllerText, "manual:") ? 'yes' : 'no') . PHP_EOL;

$viewFile = $base . '/modules/marketing/views/contact-lists/index.php';
$viewText = is_file($viewFile) ? (string) file_get_contents($viewFile) : '';
echo 'view_uses_selected_state_query_param=' . (str_contains($viewText, '?selected=') ? 'yes' : 'no') . PHP_EOL;
echo 'view_contextual_selected_actions_toggle=' . (str_contains($viewText, 'selected-actions') ? 'yes' : 'no') . PHP_EOL;

$tables = ['marketing_contact_lists', 'marketing_contact_list_members'];
foreach ($tables as $table) {
    $stmt = $pdo->prepare('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    echo 'table_' . $table . '_exists=' . ($stmt->fetch(\PDO::FETCH_ASSOC) ? 'yes' : 'no') . PHP_EOL;
}
$schemaFile = $base . '/data/full_project_schema.sql';
$schemaText = is_file($schemaFile) ? (string) file_get_contents($schemaFile) : '';
echo 'canonical_schema_contains_marketing_contact_lists=' . (str_contains($schemaText, 'CREATE TABLE marketing_contact_lists') ? 'yes' : 'no') . PHP_EOL;
echo 'canonical_schema_contains_marketing_contact_list_members=' . (str_contains($schemaText, 'CREATE TABLE marketing_contact_list_members') ? 'yes' : 'no') . PHP_EOL;

$dupStmt = $pdo->prepare(
    "SELECT INDEX_NAME
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'marketing_contact_list_members'
       AND INDEX_NAME = 'uq_marketing_contact_list_members_list_client'
     LIMIT 1"
);
$dupStmt->execute();
echo 'membership_unique_constraint_exists=' . ($dupStmt->fetch(\PDO::FETCH_ASSOC) ? 'yes' : 'no') . PHP_EOL;

$branchRow = $db->fetchOne('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
$branchId = $branchRow ? (int) ($branchRow['id'] ?? 0) : 0;
echo 'fixture_branch_id=' . $branchId . PHP_EOL;
if ($branchId <= 0) {
    echo 'abort=no_branch_fixture' . PHP_EOL;
    exit(0);
}

$audienceService = app(\Modules\Marketing\Services\MarketingContactAudienceService::class);
$listService = app(\Modules\Marketing\Services\MarketingContactListService::class);
echo 'manual_list_storage_ready=' . ($listService->isStorageReady() ? 'yes' : 'no') . PHP_EOL;

$allContacts = $audienceService->readAudience($branchId, 'all_contacts', null, '', 50, 0);
$emailEligible = $audienceService->readAudience($branchId, 'marketing_email_eligible', null, '', 50, 0);
$smsEligible = $audienceService->readAudience($branchId, 'marketing_sms_eligible', null, '', 50, 0);
$searchProbe = $audienceService->readAudience($branchId, 'all_contacts', null, 'a', 20, 0);

echo 'all_contacts_total=' . (int) ($allContacts['total'] ?? 0) . PHP_EOL;
echo 'marketing_email_eligible_total=' . (int) ($emailEligible['total'] ?? 0) . PHP_EOL;
echo 'marketing_sms_eligible_total=' . (int) ($smsEligible['total'] ?? 0) . PHP_EOL;
echo 'search_probe_total=' . (int) ($searchProbe['total'] ?? 0) . PHP_EOL;

$emailSqlTruth = $db->fetchOne(
    "SELECT COUNT(*) AS c
     FROM clients c
     WHERE c.deleted_at IS NULL
       AND c.merged_into_client_id IS NULL
       AND (c.branch_id = ? OR c.branch_id IS NULL)
       AND c.marketing_opt_in = 1
       AND TRIM(COALESCE(c.email, '')) <> ''
       AND INSTR(TRIM(COALESCE(c.email, '')), '@') > 1",
    [$branchId]
);
$smsSqlTruth = $db->fetchOne(
    "SELECT COUNT(*) AS c
     FROM clients c
     WHERE c.deleted_at IS NULL
       AND c.merged_into_client_id IS NULL
       AND (c.branch_id = ? OR c.branch_id IS NULL)
       AND c.marketing_opt_in = 1
       AND TRIM(COALESCE(c.phone, '')) <> ''",
    [$branchId]
);
echo 'email_eligible_sql_truth_total=' . (int) ($emailSqlTruth['c'] ?? 0) . PHP_EOL;
echo 'sms_eligible_sql_truth_total=' . (int) ($smsSqlTruth['c'] ?? 0) . PHP_EOL;
echo 'email_eligible_count_matches_sql_truth=' . (((int) ($emailEligible['total'] ?? 0) === (int) ($emailSqlTruth['c'] ?? 0)) ? 'yes' : 'no') . PHP_EOL;
echo 'sms_eligible_count_matches_sql_truth=' . (((int) ($smsEligible['total'] ?? 0) === (int) ($smsSqlTruth['c'] ?? 0)) ? 'yes' : 'no') . PHP_EOL;

$emailRowsContractOk = true;
foreach ((array) ($emailEligible['contacts'] ?? []) as $row) {
    $eligible = !empty($row['email_marketing_eligible']);
    if (!$eligible) {
        $emailRowsContractOk = false;
        break;
    }
}
$smsRowsContractOk = true;
foreach ((array) ($smsEligible['contacts'] ?? []) as $row) {
    $eligible = !empty($row['sms_marketing_eligible']);
    if (!$eligible) {
        $smsRowsContractOk = false;
        break;
    }
}
echo 'email_eligible_rows_match_boolean_contract=' . ($emailRowsContractOk ? 'yes' : 'no') . PHP_EOL;
echo 'sms_eligible_rows_match_boolean_contract=' . ($smsRowsContractOk ? 'yes' : 'no') . PHP_EOL;

$smartCounts = $audienceService->smartListCounts($branchId);
echo 'smart_list_counts=' . json_encode($smartCounts, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$firstClientId = 0;
if (!empty($allContacts['contacts'][0]['client_id'])) {
    $firstClientId = (int) $allContacts['contacts'][0]['client_id'];
}
if ($firstClientId <= 0) {
    $probe = $db->fetchOne(
        'SELECT id FROM clients WHERE deleted_at IS NULL AND merged_into_client_id IS NULL AND (branch_id = ? OR branch_id IS NULL) ORDER BY id ASC LIMIT 1',
        [$branchId]
    );
    $firstClientId = $probe ? (int) ($probe['id'] ?? 0) : 0;
}
echo 'fixture_client_id=' . $firstClientId . PHP_EOL;

if (!$listService->isStorageReady()) {
    $graceful = false;
    try {
        $lists = $listService->listManualListsWithCounts($branchId);
        $graceful = is_array($lists);
    } catch (\Throwable) {
        $graceful = false;
    }
    echo 'manual_lists_absent_schema_graceful=' . ($graceful ? 'yes' : 'no') . PHP_EOL;
    echo 'manual_list_repo_runtime_checks=skipped_storage_not_ready' . PHP_EOL;
    exit(0);
}

$listName = 'Verifier List ' . date('YmdHis');
$listId = $listService->createList($branchId, $listName, null);
echo 'manual_list_created_id=' . $listId . PHP_EOL;

$listService->renameList($branchId, $listId, $listName . ' Renamed', null);
echo 'manual_list_renamed=yes' . PHP_EOL;

if ($firstClientId > 0) {
    $listService->addContacts($branchId, $listId, [$firstClientId, $firstClientId], null);
    $memberCountRow = $db->fetchOne('SELECT COUNT(*) AS c FROM marketing_contact_list_members WHERE list_id = ?', [$listId]);
    echo 'manual_list_members_after_add=' . (int) ($memberCountRow['c'] ?? 0) . PHP_EOL;
    $dupRow = $db->fetchOne(
        'SELECT COUNT(*) AS c
         FROM (
             SELECT list_id, client_id, COUNT(*) AS dup_c
             FROM marketing_contact_list_members
             WHERE list_id = ?
             GROUP BY list_id, client_id
             HAVING dup_c > 1
         ) d',
        [$listId]
    );
    echo 'duplicate_membership_rows=' . (int) ($dupRow['c'] ?? 0) . PHP_EOL;
    $manualAudience = $audienceService->readAudience($branchId, 'manual_list', $listId, '', 50, 0);
    echo 'manual_list_audience_total=' . (int) ($manualAudience['total'] ?? 0) . PHP_EOL;
    $listService->removeContacts($branchId, $listId, [$firstClientId]);
    $memberCountAfterRemove = $db->fetchOne('SELECT COUNT(*) AS c FROM marketing_contact_list_members WHERE list_id = ?', [$listId]);
    echo 'manual_list_members_after_remove=' . (int) ($memberCountAfterRemove['c'] ?? 0) . PHP_EOL;

    $listService->addContacts($branchId, $listId, [$firstClientId], null);
    $db->query('UPDATE clients SET deleted_at = NOW() WHERE id = ?', [$firstClientId]);
    $manualListsAfterClientDeleted = $listService->listManualListsWithCounts($branchId);
    $activeManualCount = null;
    foreach ($manualListsAfterClientDeleted as $row) {
        if ((int) ($row['id'] ?? 0) === $listId) {
            $activeManualCount = (int) ($row['member_count'] ?? 0);
            break;
        }
    }
    echo 'manual_list_count_ignores_deleted_client=' . (($activeManualCount === 0) ? 'yes' : 'no') . PHP_EOL;
    $db->query('UPDATE clients SET deleted_at = NULL WHERE id = ?', [$firstClientId]);
    $listService->removeContacts($branchId, $listId, [$firstClientId]);
}

$otherBranch = $db->fetchOne(
    'SELECT id FROM branches WHERE deleted_at IS NULL AND id != ? ORDER BY id ASC LIMIT 1',
    [$branchId]
);
if ($otherBranch) {
    $otherBranchId = (int) ($otherBranch['id'] ?? 0);
    $blocked = false;
    try {
        $listService->renameList($otherBranchId, $listId, 'Cross Branch Rename Should Fail', null);
    } catch (\Throwable) {
        $blocked = true;
    }
    echo 'cross_branch_scope_enforced=' . ($blocked ? 'yes' : 'no') . PHP_EOL;
} else {
    echo 'cross_branch_scope_enforced=not_tested_single_branch_fixture' . PHP_EOL;
}

$listService->archiveList($branchId, $listId, null);
echo 'manual_list_archived=yes' . PHP_EOL;

