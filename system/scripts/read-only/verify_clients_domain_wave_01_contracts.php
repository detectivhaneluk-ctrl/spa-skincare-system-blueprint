<?php

declare(strict_types=1);

/**
 * Wave-01 — per-contract structural proof (no DB). Exit 0 = all contracts structurally present.
 *
 *   php system/scripts/read-only/verify_clients_domain_wave_01_contracts.php
 */

$base = dirname(__DIR__, 2);
$modules = $base . '/modules/clients';

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

$out = [];
$ok = true;

// --- Contract: tenant-safe registration request read/list/count/update ---
$regRepo = readf('modules/clients/repositories/ClientRegistrationRequestRepository.php');
$c1 = str_contains($regRepo, 'clientRegistrationRequestTenantExistsClause')
    && str_contains($regRepo, 'function find(')
    && str_contains($regRepo, 'function list(')
    && str_contains($regRepo, 'function count(')
    && preg_match('/UPDATE\s+client_registration_requests\s+r\s+SET.+\s+WHERE\s+r\.id\s*=\s*\?/s', $regRepo) === 1;
$out[] = 'CONTRACT registration_request_sql_scope: ' . ($c1 ? 'PASS' : 'FAIL');
$ok = $ok && $c1;

$regSvc = readf('modules/clients/services/ClientRegistrationService.php');
$c1b = str_contains($regSvc, 'requireResolvedTenantScope');
$out[] = 'CONTRACT registration_service_tenant_guard: ' . ($c1b ? 'PASS' : 'FAIL');
$ok = $ok && $c1b;

// --- Contract: tenant-safe issue flag read/list/update ---
$ifRepo = readf('modules/clients/repositories/ClientIssueFlagRepository.php');
$c2 = str_contains($ifRepo, 'clientIssueFlagTenantJoinSql')
    && str_contains($ifRepo, 'function find(')
    && str_contains($ifRepo, 'function listByClient(')
    && str_contains($ifRepo, 'UPDATE client_issue_flags f ');
$out[] = 'CONTRACT issue_flag_sql_scope: ' . ($c2 ? 'PASS' : 'FAIL');
$ok = $ok && $c2;

$ifSvc = readf('modules/clients/services/ClientIssueFlagService.php');
$c2b = str_contains($ifSvc, 'requireResolvedTenantScope');
$out[] = 'CONTRACT issue_flag_service_tenant_guard: ' . ($c2b ? 'PASS' : 'FAIL');
$ok = $ok && $c2b;

// --- Contract: custom field definition/value isolation ---
$defRepo = readf('modules/clients/repositories/ClientFieldDefinitionRepository.php');
$valRepo = readf('modules/clients/repositories/ClientFieldValueRepository.php');
$c3 = str_contains($defRepo, 'clientFieldDefinitionTenantBranchClause')
    && str_contains($valRepo, 'clientFieldDefinitionTenantBranchClause');
$out[] = 'CONTRACT custom_field_def_value_tenant_join: ' . ($c3 ? 'PASS' : 'FAIL');
$ok = $ok && $c3;

$scope = readf('core/Organization/OrganizationRepositoryScope.php');
$c3b = str_contains($scope, 'function clientFieldDefinitionTenantBranchClause');
$out[] = 'CONTRACT org_scope_field_definition_branch_clause: ' . ($c3b ? 'PASS' : 'FAIL');
$ok = $ok && $c3b;

// --- Contract: list/profile use same org-membership clause; NULL-branch-friendly vs provider ---
$repo = readf('modules/clients/repositories/ClientRepository.php');
$c4_list = preg_match('/function\s+list\s*\([^)]*\)[^{]*\{[^}]*clientProfileOrgMembershipExistsClause/s', $repo) === 1;
$c4_env = str_contains($repo, 'appendStaffClientRowBranchEnvelope') && str_contains($repo, 'BranchContext');
$out[] = 'CONTRACT list_count_duplicate_branch_envelope_matches_profile_provider: ' . ($c4_env ? 'PASS' : 'FAIL');
$ok = $ok && $c4_env;
$c4_find = str_contains($repo, 'function find(') && str_contains(substr($repo, strpos($repo, 'function find('), 800), 'clientProfileOrgMembershipExistsClause');
$c4_live = str_contains($repo, 'findLiveReadableForProfile') && str_contains($repo, 'c.branch_id IS NULL OR c.branch_id = ?');
$out[] = 'CONTRACT list_uses_clientProfileOrgMembershipExistsClause: ' . ($c4_list ? 'PASS' : 'FAIL');
$out[] = 'CONTRACT find_uses_clientProfileOrgMembershipExistsClause: ' . ($c4_find ? 'PASS' : 'FAIL');
$out[] = 'STRUCT_NOTE findLiveReadableForProfile_adds_branch_predicate_when_context_branch_set: ' . ($c4_live ? 'PRESENT' : 'MISSING');
$ok = $ok && $c4_list && $c4_find && $c4_live;

$prov = readf('modules/clients/providers/ClientListProviderImpl.php');
$c4p = str_contains($prov, '->list(');
$out[] = 'CONTRACT client_list_provider_delegates_repo_list: ' . ($c4p ? 'PASS' : 'FAIL');
$ok = $ok && $c4p;

$prof = readf('modules/clients/services/ClientProfileAccessService.php');
$c4pr = str_contains($prof, 'findLiveReadableForProfile');
$out[] = 'STRUCT_NOTE client_profile_access_uses_findLiveReadableForProfile: ' . ($c4pr ? 'PRESENT' : 'MISSING');

// --- Contract: duplicate matching normalized digits ---
$c5_findDup = str_contains($repo, 'normalizePhoneDigitsForMatch') && str_contains($repo, 'function findDuplicates');
$c5_append = str_contains($repo, 'phone_home_digits') && str_contains($repo, 'appendDuplicateSearchOrConditions');
$out[] = 'CONTRACT findDuplicates_uses_digit_normalization: ' . ($c5_findDup ? 'PASS' : 'FAIL');
$out[] = 'CONTRACT searchDuplicates_uses_stored_phone_digits: ' . ($c5_append ? 'PASS' : 'FAIL');
$ok = $ok && $c5_findDup && $c5_append;

$d1 = PublicContactNormalizer::normalizePhoneDigitsForMatch('+1 (555) 123-4567');
$c5rt = ($d1 === '15551234567');
$out[] = 'RUNTIME_SAMPLE normalizePhoneDigitsForMatch tel: ' . ($c5rt ? 'PASS' : 'FAIL');
$ok = $ok && $c5rt;

// --- Contract: canonical phone rule (mobile > home > work > legacy) ---
$canon = readf('modules/clients/support/ClientCanonicalPhone.php');
$c6 = str_contains($canon, 'phone_mobile') && str_contains($canon, 'phone_home') && str_contains($canon, 'phone_work');
$svc = readf('modules/clients/services/ClientService.php');
$c6b = str_contains($svc, 'ClientCanonicalPhone::resolvePrimaryForPersistence');
$out[] = 'CONTRACT canonical_helper_covers_mobile_home_work: ' . ($c6 ? 'PASS' : 'FAIL');
$out[] = 'CONTRACT client_service_finalize_uses_canonical_helper: ' . ($c6b ? 'PASS' : 'FAIL');
$ok = $ok && $c6 && $c6b;

$p = ClientCanonicalPhone::resolvePrimaryForPersistence([
    'phone_mobile' => '',
    'phone_home' => '  ',
    'phone_work' => '(555) 000-1111',
], null);
$c6rt = ($p !== null && str_contains($p, '555'));
$out[] = 'RUNTIME_SAMPLE canonical_falls_through_to_work: ' . ($c6rt ? 'PASS' : 'FAIL');
$ok = $ok && $c6rt;

// --- Contract: merge remap vs schema snapshot (client_id tables) ---
$schema = readf('data/full_project_schema.sql');
$expectedRemapSimple = [
    'appointments', 'invoices', 'gift_cards', 'client_packages', 'appointment_waitlist',
    'client_notes', 'client_issue_flags', 'client_consents', 'client_memberships',
    'membership_sales', 'membership_benefit_usages', 'intake_form_assignments',
    'intake_form_submissions', 'public_commerce_purchases',
];
foreach ($expectedRemapSimple as $t) {
    if (!preg_match('/CREATE TABLE\s+' . preg_quote($t, '/') . '\s*\(/i', $schema)) {
        $out[] = "MERGE_SCHEMA_TABLE_MISSING_IN_SNAPSHOT: {$t}";
        $ok = false;
    }
}
if (!preg_match('/CREATE TABLE\s+marketing_contact_list_members\s*\(/i', $schema)) {
    $out[] = 'MERGE_SCHEMA_TABLE_MISSING: marketing_contact_list_members';
    $ok = false;
}
if (!preg_match('/CREATE TABLE\s+document_links\s*\(/i', $schema)) {
    $out[] = 'MERGE_SCHEMA_TABLE_MISSING: document_links';
    $ok = false;
}
if (!preg_match('/CREATE TABLE\s+outbound_notification_messages\s*\(/i', $schema)) {
    $out[] = 'MERGE_SCHEMA_TABLE_MISSING: outbound_notification_messages';
    $ok = false;
}

if (!preg_match("/UPDATE\s+(" . implode('|', $expectedRemapSimple) . ")\s+SET\s+client_id\s*=\s*\?\s+WHERE\s+client_id\s*=\s*\?/s", $repo)) {
    // allow multiple UPDATE lines — check each table appears in remapClientReferences
    foreach ($expectedRemapSimple as $t) {
        if (!str_contains($repo, "'{$t}'") && !str_contains($repo, "'{$t}',")) {
            // tables listed in array single-quoted
            if (!preg_match("/'" . preg_quote($t, '/') . "'/", $repo)) {
                $out[] = "MERGE_CODE_MISSING_TABLE: {$t}";
                $ok = false;
            }
        }
    }
}
$c7 = str_contains($repo, 'marketing_contact_list_members_dedup_deleted')
    && str_contains($repo, 'client_consents_dedup_deleted')
    && str_contains($repo, 'UPDATE document_links SET owner_id')
    && str_contains($repo, 'outbound_notification_messages')
    && str_contains($repo, 'UPDATE clients SET merged_into_client_id')
    && str_contains($repo, 'client_registration_requests SET linked_client_id');
$out[] = 'CONTRACT merge_remap_special_tables_and_dedupe_keys: ' . ($c7 ? 'PASS' : 'FAIL');
$ok = $ok && $c7;

$c7exempt = str_contains($repo, 'mergeCustomFieldValues') && str_contains($repo, 'client_field_values');
$out[] = 'CONTRACT merge_exempt_client_field_values_documented: ' . ($c7exempt ? 'PASS' : 'FAIL');
$ok = $ok && $c7exempt;

// --- Contract: destructive dedupe SQL shape (client_consents, marketing_contact_list_members) ---
$c8a = preg_match('/DELETE\s+s\s+FROM\s+client_consents\s+s\s+INNER\s+JOIN\s+client_consents\s+p\s+ON\s+p\.document_definition_id\s*=\s*s\.document_definition_id\s+AND\s+p\.client_id\s*=\s*\?\s+WHERE\s+s\.client_id\s*=\s*\?/s', $repo) === 1;
$c8b = preg_match('/DELETE\s+m2\s+FROM\s+marketing_contact_list_members\s+m2\s+INNER\s+JOIN\s+marketing_contact_list_members\s+m1\s+ON\s+m1\.list_id\s*=\s*m2\.list_id\s+AND\s+m1\.client_id\s*=\s*\?\s+WHERE\s+m2\.client_id\s*=\s*\?/s', $repo) === 1;
$out[] = 'CONTRACT dedupe_sql_client_consents_shape: ' . ($c8a ? 'PASS' : 'FAIL');
$out[] = 'CONTRACT dedupe_sql_marketing_list_members_shape: ' . ($c8b ? 'PASS' : 'FAIL');
$ok = $ok && $c8a && $c8b;

foreach ($out as $line) {
    fwrite(STDOUT, $line . "\n");
}

fwrite(STDOUT, $ok ? "OK: verify_clients_domain_wave_01_contracts\n" : "INCOMPLETE\n");
exit($ok ? 0 : 2);
