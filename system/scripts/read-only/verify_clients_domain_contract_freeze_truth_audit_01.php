<?php

declare(strict_types=1);

/**
 * CLIENT-DOMAIN-CONTRACT-FREEZE-TRUTH-AUDIT-AND-GAP-CLOSURE-01 — structural + runtime proof (no DB).
 *
 *   php system/scripts/read-only/verify_clients_domain_contract_freeze_truth_audit_01.php
 */

$root = dirname(__DIR__, 2);
$modules = $root . '/modules/clients';

require_once $modules . '/support/PublicContactNormalizer.php';
require_once $modules . '/support/ClientCanonicalPhone.php';

use Modules\Clients\Support\ClientCanonicalPhone;
use Modules\Clients\Support\PublicContactNormalizer;

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

function readf(string $rel): string
{
    $p = dirname(__DIR__, 2) . '/' . $rel;
    if (!is_file($p)) {
        fail("missing {$rel}");
    }

    return (string) file_get_contents($p);
}

$lines = [];
$ok = true;

$repo = readf('modules/clients/repositories/ClientRepository.php');
$liveMerged = 'c.deleted_at IS NULL AND c.merged_into_client_id IS NULL';

foreach (
    [
        'list' => 'function list(',
        'count' => 'function count(',
        'findDuplicates' => 'function findDuplicates(',
        'searchDuplicates' => 'function searchDuplicates(',
        'countSearchDuplicates' => 'function countSearchDuplicates(',
    ] as $name => $needle
) {
    $pos = strpos($repo, $needle);
    if ($pos === false) {
        $lines[] = "SURFACE {$name}: MISSING_METHOD";
        $ok = false;
        continue;
    }
    $next = strpos($repo, "\n    public function ", $pos + 10);
    if ($next === false) {
        $next = strlen($repo);
    }
    $chunk = substr($repo, $pos, $next - $pos);
    $hit = str_contains($chunk, $liveMerged);
    $lines[] = "CHECK staff_surface_live_only_merged_null ({$name}): " . ($hit ? 'PASS' : 'FAIL');
    $ok = $ok && $hit;
}

$lines[] = 'CHECK findLiveReadableForProfile_has_merged_null: '
    . (str_contains($repo, 'merged_into_client_id IS NULL') ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($repo, 'merged_into_client_id IS NULL');

$prov = readf('modules/clients/providers/ClientListProviderImpl.php');
$lines[] = 'CHECK ClientListProviderImpl_delegates_repo_list: ' . (str_contains($prov, '->list(') ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($prov, '->list(');

$scope = readf('core/Organization/OrganizationRepositoryScope.php');
$lines[] = 'CHECK registration_orphan_requires_linked_client: '
    . ((preg_match('/branch_id\s+IS\s+NULL\s+AND\s+\{\$a\}\.linked_client_id\s+IS\s+NOT\s+NULL/s', $scope) === 1) ? 'PASS' : 'FAIL');
$ok = $ok && preg_match('/branch_id\s+IS\s+NULL\s+AND\s+\{\$a\}\.linked_client_id\s+IS\s+NOT\s+NULL/s', $scope) === 1;

$lines[] = 'CHECK field_def_clause_excludes_null_branch: '
    . ((preg_match('/function clientFieldDefinitionTenantBranchClause[\s\S]+?branch_id IS NOT NULL AND EXISTS/s', $scope) === 1) ? 'PASS' : 'FAIL');
$ok = $ok && preg_match('/function clientFieldDefinitionTenantBranchClause[\s\S]+?branch_id IS NOT NULL AND EXISTS/s', $scope) === 1;

$ifRepo = readf('modules/clients/repositories/ClientIssueFlagRepository.php');
$lines[] = 'CHECK issue_flag_uses_tenant_join: ' . (str_contains($ifRepo, 'clientIssueFlagTenantJoinSql') ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($ifRepo, 'clientIssueFlagTenantJoinSql');

$valRepo = readf('modules/clients/repositories/ClientFieldValueRepository.php');
$lines[] = 'CHECK field_value_upsert_noop_alien_def: '
    . (str_contains($valRepo, 'if ($def === null)') && str_contains($valRepo, 'return;') ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($valRepo, 'if ($def === null)');

$validator = readf('modules/clients/services/ClientInputValidator.php');
$lines[] = 'CHECK input_validator_required_custom_fields: '
    . (str_contains($validator, 'is_required') && str_contains($validator, 'custom_field_') ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($validator, 'is_required');

$ctrl = readf('modules/clients/controllers/ClientController.php');
$lines[] = 'CHECK index_sets_display_phone_from_canonical: '
    . (str_contains($ctrl, "getCanonicalPrimaryPhone(\$c)") ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($ctrl, 'getCanonicalPrimaryPhone($c)');

$svc = readf('modules/clients/services/ClientService.php');
$lines[] = 'CHECK service_finalize_uses_canonical: '
    . (str_contains($svc, 'ClientCanonicalPhone::resolvePrimaryForPersistence') ? 'PASS' : 'FAIL');
$ok = $ok && str_contains($svc, 'ClientCanonicalPhone::resolvePrimaryForPersistence');

$expectedParseKeys = [
    'first_name', 'last_name', 'email', 'phone_home', 'phone_mobile', 'mobile_operator', 'phone_work', 'phone_work_ext',
    'home_address_1', 'home_address_2', 'home_city', 'home_postal_code', 'home_country',
    'delivery_same_as_home', 'delivery_address_1', 'delivery_address_2', 'delivery_city', 'delivery_postal_code', 'delivery_country',
    'birth_date', 'anniversary', 'gender', 'occupation', 'language', 'preferred_contact_method',
    'marketing_opt_in', 'receive_emails', 'receive_sms', 'booking_alert', 'check_in_alert', 'check_out_alert',
    'referral_information', 'referral_history', 'referred_by', 'customer_origin',
    'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
    'inactive_flag', 'notes', 'custom_fields',
];
if (!preg_match('/private function parseInput\([^)]*\): array\s*\{([\s\S]*?)^\s*private function validate/ms', $ctrl, $m)) {
    $lines[] = 'CHECK parseInput_block_extract: FAIL';
    $ok = false;
} else {
    $block = $m[1];
    if (!preg_match_all("/'([\w]+)'\s*=>/", $block, $km)) {
        $lines[] = 'CHECK parseInput_keys_regex: FAIL';
        $ok = false;
    } else {
        $found = array_unique($km[1]);
        sort($found);
        $exp = $expectedParseKeys;
        sort($exp);
        $parseOk = $found === $exp;
        $lines[] = 'CHECK parseInput_keys_match_freeze_doc_set: ' . ($parseOk ? 'PASS' : 'FAIL');
        if (!$parseOk) {
            fwrite(STDERR, '  expected: ' . json_encode($exp) . "\n");
            fwrite(STDERR, '  found:    ' . json_encode($found) . "\n");
        }
        $ok = $ok && $parseOk;
    }
}

$mergeNeedles = [
    'mergeCustomFieldValues', 'client_field_values', 'document_links', 'outbound_notification_messages',
    'client_consents_dedup_deleted', 'marketing_contact_list_members_dedup_deleted', 'public_commerce_purchases',
];
foreach ($mergeNeedles as $n) {
    $hit = str_contains($repo, $n);
    $lines[] = "CHECK merge_path_contains ({$n}): " . ($hit ? 'PASS' : 'FAIL');
    $ok = $ok && $hit;
}

$persist = ClientCanonicalPhone::resolvePrimaryForPersistence([
    'phone_mobile' => '111',
    'phone_home' => '222',
], null);
$lines[] = 'RUNTIME canonical_prefers_mobile_over_home: ' . ($persist === '111' ? 'PASS' : 'FAIL');
$ok = $ok && $persist === '111';

$display = ClientCanonicalPhone::displayPrimary(['phone_mobile' => '', 'phone_home' => '  999  ']);
$lines[] = 'RUNTIME displayPrimary_same_as_persist_order: ' . (trim($display ?? '') === '999' ? 'PASS' : 'FAIL');
$ok = $ok && trim($display ?? '') === '999';

$d1 = PublicContactNormalizer::normalizePhoneDigitsForMatch('+1 (555) 123-4567');
$lines[] = 'RUNTIME duplicate_normalize_digits: ' . ($d1 === '15551234567' ? 'PASS' : 'FAIL');
$ok = $ok && $d1 === '15551234567';

$lines[] = 'CHECK findDuplicates_digit_or_on_four_columns: '
    . ((preg_match('/phone_home[\s\S]{0,200}phone_mobile[\s\S]{0,200}phone_work/', $repo) === 1) ? 'PASS' : 'FAIL');
$ok = $ok && preg_match('/c\.phone_home[\s\S]{0,120}c\.phone_mobile/s', $repo) === 1;

foreach ($lines as $line) {
    fwrite(STDOUT, $line . "\n");
}

fwrite(STDOUT, ($ok ? "OK: verify_clients_domain_contract_freeze_truth_audit_01\n" : "INCOMPLETE\n"));
exit($ok ? 0 : 2);
