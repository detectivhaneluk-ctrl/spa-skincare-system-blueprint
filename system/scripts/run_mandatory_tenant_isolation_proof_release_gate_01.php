<?php

declare(strict_types=1);

/**
 * PLT-REL-01 — Mandatory tenant-isolation proof release gate (canonical runner).
 *
 * Tier A — Static (no DB, deterministic order; matches ZIP-TRUTH seal + charter footguns):
 *   1. verify_cross_platform_autoload_case_canonicalization_01.php (PLT-TNT-03 cross-platform autoload/path-case truth blocker)
 *   2. verify_sales_invoice_payment_tenant_mutation_guard_readonly_01.php
 *   3. verify_cross_module_invoice_payment_read_guard_readonly_01.php
 *   4. verify_sales_invoice_payment_tenant_read_guard_readonly_01.php
 *   5. verify_public_commerce_json_controller_staff_boundary_wave_01.php
 *   6. verify_payroll_invoice_payment_tenant_guard_readonly_01.php
 *   7. verify_tenant_repository_footguns.php
 *   8. verify_client_merge_job_repository_org_scope_plt_tnt_01.php (PLT-TNT-01 merge-job org predicates)
 *   9. verify_tenant_branch_access_legacy_suspended_org_plt_lc_01.php (PLT-LC-01 legacy pin + branch-context choke)
 *  10. verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05.php (FND-TNT-05 PC purchase ↔ invoice correlation)
 *  11. verify_invoice_client_read_envelope_fnd_tnt_06.php (FND-TNT-06 invoice/cashier client read envelope)
 *  10a. verify_invoice_number_sequence_hotspot_readonly_01.php (PLT-TNT-01 CLOSURE-15 invoice sequence branch-derived org basis)
 *  10a2. verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php (PLT-TNT-01 CLOSURE-16 register cash aggregate tenant proof)
 *  10a3. verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php (PLT-TNT-01 CLOSURE-17 InvoiceRepository::count branch-derived + conditional clients join)
 *  10a4. verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php (PLT-TNT-01 CLOSURE-18 InvoiceRepository::list parity with count)
 *  10a5. verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php (PLT-TNT-01 CLOSURE-19 InvoiceRepository::find / findForUpdate explicit branch-derived entry)
 *  10a6. verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php (PLT-TNT-01 CLOSURE-20 PaymentRepository::find / findForUpdate explicit branch-derived entry)
 *  10a7. verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php (PLT-TNT-01 CLOSURE-21 PaymentRepository::getByInvoiceId explicit branch-derived entry)
 *  10a8. verify_payment_repository_get_completed_total_by_invoice_id_invoice_plane_closure_22_readonly_01.php (PLT-TNT-01 CLOSURE-22 PaymentRepository::getCompletedTotalByInvoiceId explicit branch-derived entry)
 *  10a9. verify_payment_repository_helper_invoice_plane_closure_23_readonly_01.php (PLT-TNT-01 CLOSURE-23 PaymentRepository helper trio explicit branch-derived entry parity)
 *  10a10. verify_root_04_strict_repair_split_membership_invoice_plane_readonly_01.php (ROOT-04 strict tenant vs explicit repair/global split for membership invoice-plane helpers)
 *  10a11. verify_root_02_null_branch_semantic_normalization_readonly_01.php (ROOT-02 explicit BRANCH_OWNED / ORG_GLOBAL / REPAIR_ONLY split in target membership + availability methods)
 *  10a12. verify_membership_repository_contract_self_defense_readonly_01.php (PLT-TNT-02 client-membership explicit runtime vs repair split + billing-cycle mutation symmetry hard-stop)
 *  10a13. verify_plt_tnt_03_full_self_defending_boundary_rollout_01.php (PLT-TNT-03 membership-sale + invoice-plane aggregate/existence self-defense rollout)
 *  10b. verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php (FND-TNT-07 PC purchase + membership_sale scoped UPDATE)
 *  10c. verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php (FND-TNT-08 membership_sale scoped find/findForUpdate/blocking)
 *  10d. verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php (FND-TNT-09 refund-review lists + invoice-branch definition catalog)
 *  10e. verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php (FND-TNT-10 membership definition find/list/count + billing cycle invoice-plane find)
 *  10f. verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php (FND-TNT-11 client_memberships id-read/lock + renewal branch pin)
 *  10g. verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php (FND-TNT-12 client_memberships list/count removal + issuance overlap + cycle period lookup)
 *  10h. verify_tenant_closure_wave_fnd_tnt_13_readonly_01.php (FND-TNT-13 client_memberships scoped UPDATE + repair path + global ops renewal scan name)
 *  10i. verify_tenant_closure_wave_fnd_tnt_14_readonly_01.php (FND-TNT-14 expiry pass cron listing anchored + tenant-scoped lock only)
 *  10j. verify_tenant_closure_wave_fnd_tnt_15_readonly_01.php (FND-TNT-15 public commerce invoice read branch-correlated)
 *  10j2. verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php (FND-TNT-16 public client email lock live branch/org proof)
 *  10j3. verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php (FND-TNT-17 public client phone lock + excluding read parity)
 *  10j4. verify_tenant_closure_wave_fnd_tnt_18_readonly_01.php (FND-TNT-18 appointment room FOR UPDATE tenant-scoped)
 *  10j5. verify_tenant_closure_wave_fnd_tnt_19_readonly_01.php (FND-TNT-19 hasRoomConflict org-scoped overlap scan)
 *  10j6. verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php (FND-TNT-21 hasStaffConflict org-scoped overlap scan; tenant-closure series)
 *  10i. verify_gift_card_tenant_scope_closure_readonly_01.php (FND-TNT-14 gift_cards tenant visibility fragments + no hand-rolled client/org EXISTS)
 *  10j. verify_staff_group_tenant_scope_closure_readonly_01.php (FND-TNT-15 staff_groups tenant visibility fragment + assignable/id paths gated)
 *  10k. verify_inventory_product_category_residual_tenancy_closure_readonly_08.php (FND-TNT-16 category duplicate/parent repair paths union-backed)
 *  10l. verify_inventory_product_brand_residual_tenancy_closure_readonly_09.php (FND-TNT-17 brand duplicate-name family union-backed)
 *  10m. verify_inventory_product_repository_residual_tenancy_closure_readonly_10.php (FND-TNT-18 product detach/count/relink + tenant catalog visibility)
 *  10n. verify_inventory_product_repository_search_taxonomy_tenancy_closure_readonly_11.php (FND-TNT-19 tenant list/count search + taxonomy EXISTS use taxonomy union)
 *  10n2. verify_root_02_inventory_product_legacy_read_path_lockdown_01.php (ROOT-02 product legacy read-path lockdown: weak read helpers locked + broad runtime caller deny-proof)
 *  10o. verify_inventory_product_repository_weak_list_count_runtime_closure_readonly_12.php (FND-TNT-20 no tenant module calls weak ProductRepository list/count / unscoped catalog / genericSearchCondition escape)
 *  10p. verify_inventory_product_repository_deprecated_mutation_read_runtime_closure_readonly_13.php (FND-TNT-21 no tenant module calls id-only ProductRepository find/findLocked/update/softDelete; backfill uses resolved-catalog taxonomy patch)
 *  10q. verify_inventory_stock_movement_and_count_repository_deprecated_read_runtime_closure_readonly_14.php (FND-TNT-22 no tenant module calls unscoped StockMovementRepository / InventoryCountRepository find/list/count)
 *  10r. verify_inventory_product_stock_quantity_mutation_resolved_scope_closure_readonly_15.php (FND-TNT-24 stock movement applies on-hand via ProductRepository union-scoped UPDATE, not id-only)
 *  10s. verify_inventory_product_stock_quantity_mutation_policy_closure_readonly_16.php (FND-TNT-25 generic product update normalizer excludes stock_quantity; INSERT-only normalizeForCreate)
 *  10t. verify_inventory_supplier_repository_weak_list_count_runtime_closure_readonly_17.php (FND-TNT-23 no tenant module calls unscoped SupplierRepository find/list/count or id-only update/softDelete)
 *  10u. verify_support_entry_password_step_up_barrier_readonly_18.php (CLOSURE-18 support-entry start requires password step-up, not ambient session alone)
 *  10u2. verify_privileged_plane_mfa_lockdown_readonly_03.php (PRIVILEGED-PLANE-03 support-entry + HIGH/CRITICAL MFA strict, no not-enrolled bypass)
 *  10v. verify_platform_manage_password_step_up_barrier_readonly_20.php (CLOSURE-20–20D: full platform.manage POST inventory = password step-up except support-entry; registry store/update step-up + CSRF)
 *  10v2. verify_repository_contract_canonicalization_gate_readonly_01.php (PLT-TNT-01 canonical repository contract gate: explicit runtime/non-runtime families + locked mixed-semantics generic verbs for the first enforcement slice)
 *  11. verify_inventory_taxonomy_tenant_scope_readonly_01.php (INVENTORY-TENANT-DATA-PLANE-HARDENING-01 taxonomy findInTenantScope)
 *  11b. verify_root_02_inventory_taxonomy_legacy_read_path_lockdown_01.php (ROOT-02 taxonomy legacy read-path lockdown: weak helpers locked + broad runtime caller deny-proof)
 *  12. verify_inventory_tenant_scope_followon_wave_02_readonly_01.php (INVENTORY follow-on wave 02 index/batch/internal reads)
 *  13. verify_inventory_tenant_scope_followon_wave_03_readonly_01.php (INVENTORY follow-on wave 03 tree/HQ/select-list scope)
 *  14. verify_inventory_tenant_scope_followon_wave_04_readonly_01.php (INVENTORY follow-on wave 04 invoice/catalog/supplier/taxonomy repair scope)
 *  15. verify_inventory_tenant_scope_followon_wave_05_readonly_01.php (INVENTORY follow-on wave 05 product writes + settlement aggregates + backfill/orphan scope)
 *  16. verify_documents_intake_marketing_tenant_scope_foundation_01.php (documents/intake/marketing org-scope fragments; wave 03–04 intake counts, submissions, template fields)
 *  17. verify_null_branch_catalog_patterns.php
 *  18. verify_foundation_platform_invariants_readonly_01.php (FOUNDATION-PLATFORM-INVARIANTS: marketing repo names + founder MFA artifacts)
 *  19. verify_protected_marketing_branch_null_or_eq_readonly_01.php (hand-rolled branch_id = ? OR NULL in protected marketing trees)
 *  19b. verify_marketing_appointments_final_tenant_invariant_wave_02_readonly_01.php (marketing lists/audience + blocked slots + availability staff SQL)
 *
 * Tier B — Integration (seeded DB; cross-org + wrong-branch runtime invariants):
 *  17. smoke_sales_tenant_data_plane_hardening_01.php
 *  18. smoke_foundation_minimal_regression_wave_01.php
 *
 * From repository root:
 *   php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php
 *   php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php --with-integration
 *
 * Env (optional): TENANT_ISOLATION_GATE_INTEGRATION=1 enables Tier B (same as --with-integration).
 *
 * Exit: 0 = all executed tiers passed; non-zero = first failure (fail-closed).
 */

$argv = $_SERVER['argv'] ?? [];
$withIntegration = in_array('--with-integration', $argv, true)
    || (string) getenv('TENANT_ISOLATION_GATE_INTEGRATION') === '1';

$scriptsDir = __DIR__;
$repoRoot = dirname($scriptsDir, 2);
if (!is_dir($repoRoot . '/system')) {
    fwrite(STDERR, "PLT-REL-01: could not resolve repository root (expected system/ under repo).\n");
    exit(1);
}

$php = PHP_BINARY;

/** @var list<array{label:string, path:string}> */
$tierA = [
    ['label' => 'cross_platform_autoload_case_canonicalization_01', 'path' => $scriptsDir . '/read-only/verify_cross_platform_autoload_case_canonicalization_01.php'],
    ['label' => 'sales_invoice_payment_tenant_mutation_guard_readonly', 'path' => $scriptsDir . '/read-only/verify_sales_invoice_payment_tenant_mutation_guard_readonly_01.php'],
    ['label' => 'cross_module_invoice_payment_read_guard_readonly', 'path' => $scriptsDir . '/read-only/verify_cross_module_invoice_payment_read_guard_readonly_01.php'],
    ['label' => 'sales_invoice_payment_tenant_read_guard_readonly', 'path' => $scriptsDir . '/read-only/verify_sales_invoice_payment_tenant_read_guard_readonly_01.php'],
    ['label' => 'public_commerce_json_controller_staff_boundary', 'path' => $scriptsDir . '/read-only/verify_public_commerce_json_controller_staff_boundary_wave_01.php'],
    ['label' => 'payroll_invoice_payment_tenant_guard_readonly', 'path' => $scriptsDir . '/read-only/verify_payroll_invoice_payment_tenant_guard_readonly_01.php'],
    ['label' => 'tenant_repository_footguns', 'path' => $scriptsDir . '/verify_tenant_repository_footguns.php'],
    ['label' => 'client_merge_job_repository_org_scope_plt_tnt_01', 'path' => $scriptsDir . '/read-only/verify_client_merge_job_repository_org_scope_plt_tnt_01.php'],
    ['label' => 'tenant_branch_access_legacy_suspended_org_plt_lc_01', 'path' => $scriptsDir . '/read-only/verify_tenant_branch_access_legacy_suspended_org_plt_lc_01.php'],
    ['label' => 'public_commerce_purchase_invoice_correlation_fnd_tnt_05', 'path' => $scriptsDir . '/read-only/verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05.php'],
    ['label' => 'invoice_client_read_envelope_fnd_tnt_06', 'path' => $scriptsDir . '/read-only/verify_invoice_client_read_envelope_fnd_tnt_06.php'],
    ['label' => 'invoice_number_sequence_branch_derived_plt_tnt_15', 'path' => $scriptsDir . '/read-only/verify_invoice_number_sequence_hotspot_readonly_01.php'],
    ['label' => 'payment_register_session_cash_aggregate_closure_16', 'path' => $scriptsDir . '/read-only/verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php'],
    ['label' => 'invoice_repository_count_invoice_plane_closure_17', 'path' => $scriptsDir . '/read-only/verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php'],
    ['label' => 'invoice_repository_list_invoice_plane_closure_18', 'path' => $scriptsDir . '/read-only/verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php'],
    ['label' => 'invoice_repository_find_find_for_update_invoice_plane_closure_19', 'path' => $scriptsDir . '/read-only/verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php'],
    ['label' => 'payment_repository_find_find_for_update_invoice_plane_closure_20', 'path' => $scriptsDir . '/read-only/verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php'],
    ['label' => 'payment_repository_get_by_invoice_id_invoice_plane_closure_21', 'path' => $scriptsDir . '/read-only/verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php'],
    ['label' => 'payment_repository_get_completed_total_by_invoice_id_invoice_plane_closure_22', 'path' => $scriptsDir . '/read-only/verify_payment_repository_get_completed_total_by_invoice_id_invoice_plane_closure_22_readonly_01.php'],
    ['label' => 'payment_repository_helper_invoice_plane_closure_23', 'path' => $scriptsDir . '/read-only/verify_payment_repository_helper_invoice_plane_closure_23_readonly_01.php'],
    ['label' => 'root_04_strict_repair_split_membership_invoice_plane', 'path' => $scriptsDir . '/read-only/verify_root_04_strict_repair_split_membership_invoice_plane_readonly_01.php'],
    ['label' => 'root_02_null_branch_semantic_normalization', 'path' => $scriptsDir . '/read-only/verify_root_02_null_branch_semantic_normalization_readonly_01.php'],
    ['label' => 'membership_repository_contract_self_defense_readonly_01', 'path' => $scriptsDir . '/read-only/verify_membership_repository_contract_self_defense_readonly_01.php'],
    ['label' => 'plt_tnt_03_full_self_defending_boundary_rollout_01', 'path' => $scriptsDir . '/read-only/verify_plt_tnt_03_full_self_defending_boundary_rollout_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_07_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_08_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_09_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_10_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_11_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_12_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_13_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_13_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_14_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_14_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_15_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_15_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_16_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_17_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_18_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_18_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_19_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_19_readonly_01.php'],
    ['label' => 'tenant_closure_wave_fnd_tnt_21_readonly', 'path' => $scriptsDir . '/read-only/verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php'],
    ['label' => 'inventory_taxonomy_tenant_scope_readonly', 'path' => $scriptsDir . '/read-only/verify_inventory_taxonomy_tenant_scope_readonly_01.php'],
    ['label' => 'root_02_inventory_taxonomy_legacy_read_path_lockdown_01', 'path' => $scriptsDir . '/read-only/verify_root_02_inventory_taxonomy_legacy_read_path_lockdown_01.php'],
    ['label' => 'inventory_tenant_scope_followon_wave_02', 'path' => $scriptsDir . '/read-only/verify_inventory_tenant_scope_followon_wave_02_readonly_01.php'],
    ['label' => 'inventory_tenant_scope_followon_wave_03', 'path' => $scriptsDir . '/read-only/verify_inventory_tenant_scope_followon_wave_03_readonly_01.php'],
    ['label' => 'inventory_tenant_scope_followon_wave_04', 'path' => $scriptsDir . '/read-only/verify_inventory_tenant_scope_followon_wave_04_readonly_01.php'],
    ['label' => 'inventory_tenant_scope_followon_wave_05', 'path' => $scriptsDir . '/read-only/verify_inventory_tenant_scope_followon_wave_05_readonly_01.php'],
    ['label' => 'inventory_catalog_tenant_union_fragments_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_catalog_tenant_union_fragments_closure_readonly_01.php'],
    ['label' => 'settings_backed_vat_payment_tenant_scope', 'path' => $scriptsDir . '/read-only/verify_settings_backed_vat_payment_tenant_scope_readonly_01.php'],
    ['label' => 'notifications_tenant_scope_closure', 'path' => $scriptsDir . '/read-only/verify_notifications_tenant_scope_closure_readonly_01.php'],
    ['label' => 'client_membership_tenant_scope_closure', 'path' => $scriptsDir . '/read-only/verify_client_membership_tenant_scope_closure_readonly_01.php'],
    ['label' => 'gift_card_tenant_scope_closure', 'path' => $scriptsDir . '/read-only/verify_gift_card_tenant_scope_closure_readonly_01.php'],
    ['label' => 'staff_group_tenant_scope_closure', 'path' => $scriptsDir . '/read-only/verify_staff_group_tenant_scope_closure_readonly_01.php'],
    ['label' => 'inventory_product_category_residual_tenancy_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_category_residual_tenancy_closure_readonly_08.php'],
    ['label' => 'inventory_product_brand_residual_tenancy_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_brand_residual_tenancy_closure_readonly_09.php'],
    ['label' => 'inventory_product_repository_residual_tenancy_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_repository_residual_tenancy_closure_readonly_10.php'],
    ['label' => 'inventory_product_repository_search_taxonomy_tenancy_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_repository_search_taxonomy_tenancy_closure_readonly_11.php'],
    ['label' => 'root_02_inventory_product_legacy_read_path_lockdown_01', 'path' => $scriptsDir . '/read-only/verify_root_02_inventory_product_legacy_read_path_lockdown_01.php'],
    ['label' => 'inventory_product_repository_weak_list_count_runtime_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_repository_weak_list_count_runtime_closure_readonly_12.php'],
    ['label' => 'inventory_product_repository_deprecated_mutation_read_runtime_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_repository_deprecated_mutation_read_runtime_closure_readonly_13.php'],
    ['label' => 'inventory_stock_movement_and_count_repository_deprecated_read_runtime_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_stock_movement_and_count_repository_deprecated_read_runtime_closure_readonly_14.php'],
    ['label' => 'inventory_product_stock_quantity_mutation_resolved_scope_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_stock_quantity_mutation_resolved_scope_closure_readonly_15.php'],
    ['label' => 'inventory_product_stock_quantity_mutation_policy_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_product_stock_quantity_mutation_policy_closure_readonly_16.php'],
    ['label' => 'inventory_supplier_repository_weak_list_count_runtime_closure', 'path' => $scriptsDir . '/read-only/verify_inventory_supplier_repository_weak_list_count_runtime_closure_readonly_17.php'],
    ['label' => 'support_entry_password_step_up_barrier_readonly_18', 'path' => $scriptsDir . '/read-only/verify_support_entry_password_step_up_barrier_readonly_18.php'],
    ['label' => 'privileged_plane_mfa_lockdown_readonly_03', 'path' => $scriptsDir . '/read-only/verify_privileged_plane_mfa_lockdown_readonly_03.php'],
    ['label' => 'platform_manage_password_step_up_barrier_readonly_20', 'path' => $scriptsDir . '/read-only/verify_platform_manage_password_step_up_barrier_readonly_20.php'],
    ['label' => 'repository_contract_canonicalization_gate_readonly_01', 'path' => $scriptsDir . '/read-only/verify_repository_contract_canonicalization_gate_readonly_01.php'],
    ['label' => 'documents_intake_marketing_tenant_scope_foundation_01', 'path' => $scriptsDir . '/read-only/verify_documents_intake_marketing_tenant_scope_foundation_01.php'],
    ['label' => 'null_branch_catalog_patterns', 'path' => $scriptsDir . '/verify_null_branch_catalog_patterns.php'],
    ['label' => 'foundation_platform_invariants_readonly_01', 'path' => $scriptsDir . '/read-only/verify_foundation_platform_invariants_readonly_01.php'],
    ['label' => 'protected_marketing_branch_null_or_eq_readonly_01', 'path' => $scriptsDir . '/read-only/verify_protected_marketing_branch_null_or_eq_readonly_01.php'],
    ['label' => 'marketing_appointments_final_tenant_invariant_wave_02_readonly_01', 'path' => $scriptsDir . '/read-only/verify_marketing_appointments_final_tenant_invariant_wave_02_readonly_01.php'],
];

/** @var list<array{label:string, path:string}> */
$tierB = [
    ['label' => 'smoke_sales_tenant_data_plane_hardening_01', 'path' => $scriptsDir . '/smoke_sales_tenant_data_plane_hardening_01.php'],
    ['label' => 'smoke_foundation_minimal_regression_wave_01', 'path' => $scriptsDir . '/smoke_foundation_minimal_regression_wave_01.php'],
];

$runScript = static function (string $label, string $absPath) use ($php): int {
    if (!is_file($absPath)) {
        fwrite(STDERR, "PLT-REL-01 FAIL: missing script file for {$label}: {$absPath}\n");

        return 1;
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($absPath);
    $output = [];
    $code = 1;
    exec($cmd . ' 2>&1', $output, $code);
    echo implode("\n", $output) . (count($output) > 0 ? "\n" : '');

    return $code;
};

echo "=== PLT-REL-01 tenant-isolation proof gate ===\n";
echo 'Repository root: ' . $repoRoot . "\n";
echo "Tier A (static): " . count($tierA) . " steps\n";
echo 'Tier B (integration): ' . ($withIntegration ? 'enabled' : 'skipped (use --with-integration or TENANT_ISOLATION_GATE_INTEGRATION=1)') . "\n\n";

$step = 0;
foreach ($tierA as $item) {
    $step++;
    echo "--- Tier A {$step}/" . count($tierA) . ": {$item['label']} ---\n";
    $code = $runScript($item['label'], $item['path']);
    if ($code !== 0) {
        fwrite(STDERR, "PLT-REL-01: Tier A failed on {$item['label']} (exit {$code}).\n");

        exit($code);
    }
}

if ($withIntegration) {
    $i = 0;
    foreach ($tierB as $item) {
        $i++;
        echo "--- Tier B {$i}/" . count($tierB) . ": {$item['label']} ---\n";
        $code = $runScript($item['label'], $item['path']);
        if ($code !== 0) {
            fwrite(STDERR, "PLT-REL-01: Tier B failed on {$item['label']} (exit {$code}).\n");

            exit($code);
        }
    }
}

echo "\nPLT-REL-01: OK (Tier A complete" . ($withIntegration ? '; Tier B complete' : '') . ").\n";
exit(0);
