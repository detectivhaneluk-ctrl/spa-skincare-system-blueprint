<?php

declare(strict_types=1);

/**
 * CLIENT-DOMAIN-BACKEND-HARDENING-AND-UI-READINESS-WAVE-01 — structural proof (no DB).
 *
 * From repository root or system/:
 *   php system/scripts/read-only/verify_clients_domain_wave_01.php
 */

$base = dirname(__DIR__, 2);

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

function read(string $path): string
{
    if (!is_file($path)) {
        fail("missing file: {$path}");
    }

    return (string) file_get_contents($path);
}

$checks = [
    'registration_repo_org_scope' => str_contains(read($base . '/modules/clients/repositories/ClientRegistrationRequestRepository.php'), 'clientRegistrationRequestTenantExistsClause'),
    'issue_flag_repo_tenant_join' => str_contains(read($base . '/modules/clients/repositories/ClientIssueFlagRepository.php'), 'clientIssueFlagTenantJoinSql'),
    'field_def_tenant_clause' => str_contains(read($base . '/modules/clients/repositories/ClientFieldDefinitionRepository.php'), 'clientFieldDefinitionTenantBranchClause'),
    'field_value_join_tenant' => str_contains(read($base . '/modules/clients/repositories/ClientFieldValueRepository.php'), 'clientFieldDefinitionTenantBranchClause'),
    'merge_public_commerce_purchases' => str_contains(read($base . '/modules/clients/repositories/ClientRepository.php'), 'public_commerce_purchases'),
    'merge_intake_form_assignments' => str_contains(read($base . '/modules/clients/repositories/ClientRepository.php'), 'intake_form_assignments'),
    'merge_document_links_client' => str_contains(read($base . '/modules/clients/repositories/ClientRepository.php'), 'document_links')
        && str_contains(read($base . '/modules/clients/repositories/ClientRepository.php'), 'owner_type'),
    'list_uses_profile_clause' => preg_match('/function\s+list\b[\s\S]*?clientProfileOrgMembershipExistsClause/', read($base . '/modules/clients/repositories/ClientRepository.php')) === 1,
    'duplicates_digit_match' => str_contains(read($base . '/modules/clients/repositories/ClientRepository.php'), 'normalizePhoneDigitsForMatch') && str_contains(read($base . '/modules/clients/repositories/ClientRepository.php'), 'appendDuplicateSearchOrConditions'),
    'canonical_phone_helper' => str_contains(read($base . '/modules/clients/support/ClientCanonicalPhone.php'), 'phone_work'),
    'registration_service_tenant_guard' => str_contains(read($base . '/modules/clients/services/ClientRegistrationService.php'), 'requireResolvedTenantScope'),
    'issue_flag_service_tenant_guard' => str_contains(read($base . '/modules/clients/services/ClientIssueFlagService.php'), 'requireResolvedTenantScope'),
    'client_input_validator_class' => str_contains(read($base . '/modules/clients/services/ClientInputValidator.php'), 'FILTER_VALIDATE_EMAIL'),
    'bootstrap_registers_validator' => str_contains(read($base . '/modules/bootstrap/register_clients.php'), 'ClientInputValidator'),
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fail("check failed: {$name}");
    }
}

fwrite(STDOUT, "OK: verify_clients_domain_wave_01 (" . count($checks) . " checks)\n");
exit(0);
