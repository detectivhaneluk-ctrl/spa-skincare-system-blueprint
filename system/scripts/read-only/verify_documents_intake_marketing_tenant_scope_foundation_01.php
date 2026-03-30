<?php

declare(strict_types=1);

/**
 * FOUNDATION-TENANT-DATA-PLANE-ASYMMETRY-DOCUMENTS-INTAKE-MARKETING-01 / -02 — static proof:
 * documents + intake + marketing hotspot repos use OrganizationRepositoryScope catalog / client fragments;
 * no hand-rolled client (branch_id = ? OR branch_id IS NULL) in MarketingAutomationExecutionRepository or MarketingSegmentEvaluator;
 * wave 02: public intake token reads use graph cohesion + token-bound updates; ClientConsentRepository INSERT is tenant-asserted.
 * wave 03: appointment-scoped required-intake counts join appointments with branch org proof; submission/value staff reads are tenant-scoped;
 * public submission lookup/create methods are explicitly named (token flow).
 * wave 04: template field staff CRUD/list joins parent template with same catalog visibility as IntakeFormTemplateRepository; public field list is explicitly named (token flow).
 */

$root = dirname(__DIR__, 3);
$scope = (string) file_get_contents($root . '/system/core/Organization/OrganizationRepositoryScope.php');
$dd = (string) file_get_contents($root . '/system/modules/documents/repositories/DocumentDefinitionRepository.php');
$cc = (string) file_get_contents($root . '/system/modules/documents/repositories/ClientConsentRepository.php');
$consentSvc = (string) file_get_contents($root . '/system/modules/documents/services/ConsentService.php');
$ift = (string) file_get_contents($root . '/system/modules/intake/repositories/IntakeFormTemplateRepository.php');
$iftf = (string) file_get_contents($root . '/system/modules/intake/repositories/IntakeFormTemplateFieldRepository.php');
$ifa = (string) file_get_contents($root . '/system/modules/intake/repositories/IntakeFormAssignmentRepository.php');
$ifsub = (string) file_get_contents($root . '/system/modules/intake/repositories/IntakeFormSubmissionRepository.php');
$ifval = (string) file_get_contents($root . '/system/modules/intake/repositories/IntakeFormSubmissionValueRepository.php');
$intakeSvc = (string) file_get_contents($root . '/system/modules/intake/services/IntakeFormService.php');
$mse = (string) file_get_contents($root . '/system/modules/marketing/services/MarketingSegmentEvaluator.php');
$mae = (string) file_get_contents($root . '/system/modules/marketing/repositories/MarketingAutomationExecutionRepository.php');

$ok = true;

if (!str_contains($scope, 'function clientMarketingBranchScopedOrBranchlessTenantMemberClause(')) {
    fwrite(STDERR, "FAIL: OrganizationRepositoryScope must define clientMarketingBranchScopedOrBranchlessTenantMemberClause.\n");
    $ok = false;
}

foreach (['DocumentDefinitionRepository' => $dd, 'ClientConsentRepository' => $cc] as $label => $src) {
    if (!str_contains($src, 'OrganizationRepositoryScope')) {
        fwrite(STDERR, "FAIL: {$label} must inject OrganizationRepositoryScope.\n");
        $ok = false;
    }
    if (!str_contains($src, 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')) {
        fwrite(STDERR, "FAIL: {$label} must use productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause for branch ∪ global-null semantics.\n");
        $ok = false;
    }
}

if (!str_contains($ift, 'findInTenantScopeForStaff') || !str_contains($ift, 'listInTenantScopeForStaff')) {
    fwrite(STDERR, "FAIL: IntakeFormTemplateRepository must expose findInTenantScopeForStaff + listInTenantScopeForStaff.\n");
    $ok = false;
}

if (!str_contains($iftf, 'OrganizationRepositoryScope')) {
    fwrite(STDERR, "FAIL: IntakeFormTemplateFieldRepository must inject OrganizationRepositoryScope.\n");
    $ok = false;
}

if (!str_contains($iftf, 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')
    || !str_contains($iftf, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause')) {
    fwrite(STDERR, "FAIL: IntakeFormTemplateFieldRepository must use the same template catalog visibility fragments as templates.\n");
    $ok = false;
}

if (!str_contains($iftf, 'listByTemplateIdInTenantScopeForStaff')
    || !str_contains($iftf, 'listByTemplateIdForPublicTokenFlow')
    || !str_contains($iftf, 'findInTenantScopeForStaff')
    || !str_contains($iftf, 'createInTenantScopeForStaff')
    || !str_contains($iftf, 'deleteByIdInTenantScopeForStaff')) {
    fwrite(STDERR, "FAIL: IntakeFormTemplateFieldRepository must expose split staff-tenant vs public-token field surfaces.\n");
    $ok = false;
}

if (!str_contains($iftf, 'INNER JOIN intake_form_templates t ON t.id = f.template_id')) {
    fwrite(STDERR, "FAIL: IntakeFormTemplateFieldRepository staff paths must join fields to parent template for visibility.\n");
    $ok = false;
}

if (str_contains($iftf, 'public function listByTemplateId(int')
    || str_contains($iftf, 'public function find(int')
    || str_contains($iftf, 'public function create(')
    || str_contains($iftf, 'public function delete(int')
    || str_contains($iftf, 'deleteForTemplate')) {
    fwrite(STDERR, "FAIL: IntakeFormTemplateFieldRepository must not expose unscoped list/find/create/delete/deleteForTemplate.\n");
    $ok = false;
}

if (!str_contains($ifa, 'findInTenantScopeForStaff') || !str_contains($ifa, 'listInTenantScopeForStaff')) {
    fwrite(STDERR, "FAIL: IntakeFormAssignmentRepository must expose findInTenantScopeForStaff + listInTenantScopeForStaff.\n");
    $ok = false;
}

if (!str_contains($ifa, 'clientProfileOrgMembershipExistsClause')) {
    fwrite(STDERR, "FAIL: IntakeFormAssignmentRepository tenant paths must use clientProfileOrgMembershipExistsClause on clients.\n");
    $ok = false;
}

if (!str_contains($ifa, 'findByTokenHashWithPublicGraphOrgCohesion')
    || !str_contains($ifa, 'findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion')
    || !str_contains($ifa, 'updateColumnsWhereIdAndTokenHashForPublicTokenFlow')
    || !str_contains($ifa, 'updateColumnsInTenantScopeForStaff')
    || !str_contains($ifa, 'publicGraphOrgCohesionSql')) {
    fwrite(STDERR, "FAIL: IntakeFormAssignmentRepository must expose public graph-cohesion token reads + split UPDATE paths.\n");
    $ok = false;
}

if (str_contains($ifa, 'findByIdWithTemplateMetaUnscoped') || str_contains($ifa, 'public function findByTokenHash(')) {
    fwrite(STDERR, "FAIL: IntakeFormAssignmentRepository must not keep unscoped token reload or generic findByTokenHash.\n");
    $ok = false;
}

if (str_contains($ifa, 'public function update(')) {
    fwrite(STDERR, "FAIL: IntakeFormAssignmentRepository must not expose ambiguous id-only update().\n");
    $ok = false;
}

if (!str_contains($ifa, 'countIncompleteRequiredPriorForAppointmentInTenantScope')
    || !str_contains($ifa, "branchColumnOwnedByResolvedOrganizationExistsClause('ap')")
    || !str_contains($ifa, 'INNER JOIN appointments ap ON ap.id = a.appointment_id')) {
    fwrite(STDERR, "FAIL: IntakeFormAssignmentRepository must count appointment-scoped required intakes with appointments org proof join.\n");
    $ok = false;
}

if (!str_contains($ifsub, 'OrganizationRepositoryScope')) {
    fwrite(STDERR, "FAIL: IntakeFormSubmissionRepository must inject OrganizationRepositoryScope.\n");
    $ok = false;
}

if (!str_contains($ifsub, 'findInTenantScopeForStaff')
    || !str_contains($ifsub, 'findByAssignmentIdForPublicTokenFlow')
    || !str_contains($ifsub, 'createAfterPublicTokenFlow')
    || !str_contains($ifsub, 'findSubmissionIdByAssignmentIdInTenantScopeForStaff')) {
    fwrite(STDERR, "FAIL: IntakeFormSubmissionRepository must expose split staff vs public-token read/create entry points.\n");
    $ok = false;
}

if (str_contains($ifsub, 'public function find(')
    || str_contains($ifsub, 'public function findByAssignmentId(')
    || str_contains($ifsub, 'public function create(')) {
    fwrite(STDERR, "FAIL: IntakeFormSubmissionRepository must not expose ambiguous id-only find/findByAssignmentId/create.\n");
    $ok = false;
}

if (!str_contains($ifval, 'OrganizationRepositoryScope')
    || !str_contains($ifval, 'listBySubmissionIdInTenantScopeForStaff')) {
    fwrite(STDERR, "FAIL: IntakeFormSubmissionValueRepository must scope staff value lists via OrganizationRepositoryScope.\n");
    $ok = false;
}

if (str_contains($ifval, 'public function listBySubmissionId(int')) {
    fwrite(STDERR, "FAIL: IntakeFormSubmissionValueRepository must not expose unscoped listBySubmissionId(int).\n");
    $ok = false;
}

if (!str_contains($intakeSvc, 'appointments->find($appointmentId)')
    || !str_contains($intakeSvc, 'countIncompleteRequiredPriorForAppointmentInTenantScope')) {
    fwrite(STDERR, "FAIL: IntakeFormService::countIncompletePriorIntakeAssignmentsForAppointment must gate via AppointmentRepository + assignment repo count.\n");
    $ok = false;
}

if (!str_contains($intakeSvc, 'findInTenantScopeForStaff')
    || !str_contains($intakeSvc, 'listBySubmissionIdInTenantScopeForStaff')) {
    fwrite(STDERR, "FAIL: IntakeFormService staff submission detail must use tenant-scoped submission + value reads.\n");
    $ok = false;
}

if (!str_contains($intakeSvc, 'listByTemplateIdInTenantScopeForStaff')
    || !str_contains($intakeSvc, 'listByTemplateIdForPublicTokenFlow')) {
    fwrite(STDERR, "FAIL: IntakeFormService must use split staff vs public-token template field list methods.\n");
    $ok = false;
}

if (str_contains($intakeSvc, 'fields->listByTemplateId(')) {
    fwrite(STDERR, "FAIL: IntakeFormService must not call legacy fields->listByTemplateId(.\n");
    $ok = false;
}

if (!str_contains($cc, 'createInTenantScope') || !str_contains($cc, 'assertClientAndDocumentDefinitionVisibleForConsentInsert')) {
    fwrite(STDERR, "FAIL: ClientConsentRepository must use createInTenantScope + insert visibility assertion.\n");
    $ok = false;
}

if (str_contains($cc, 'public function create(')) {
    fwrite(STDERR, "FAIL: ClientConsentRepository must not expose unscoped create().\n");
    $ok = false;
}

if (str_contains($consentSvc, 'SELECT branch_id FROM clients') || str_contains($consentSvc, '->get(\\Core\\App\\Database::class)->fetchOne')) {
    fwrite(STDERR, "FAIL: ConsentService must not raw-read clients via Database::fetchOne.\n");
    $ok = false;
}

if (!str_contains($consentSvc, 'ClientRepository') || !str_contains($consentSvc, '->find($clientId)')) {
    fwrite(STDERR, "FAIL: ConsentService must use ClientRepository::find for client branch envelope reads.\n");
    $ok = false;
}

if (!str_contains($mse, 'clientMarketingBranchScopedOrBranchlessTenantMemberClause')
    || !str_contains($mse, 'clientProfileOrgMembershipExistsClause')) {
    fwrite(STDERR, "FAIL: MarketingSegmentEvaluator must use client marketing + profile org fragments.\n");
    $ok = false;
}

if (str_contains($mse, 'branch_id = ? OR') && str_contains($mse, 'branch_id IS NULL')) {
    fwrite(STDERR, "FAIL: MarketingSegmentEvaluator must not hand-roll client branch_id = ? OR branch_id IS NULL.\n");
    $ok = false;
}

if (!str_contains($mae, 'clientMarketingBranchScopedOrBranchlessTenantMemberClause')) {
    fwrite(STDERR, "FAIL: MarketingAutomationExecutionRepository must use clientMarketingBranchScopedOrBranchlessTenantMemberClause.\n");
    $ok = false;
}

if (str_contains($mae, '(c.branch_id = ? OR c.branch_id IS NULL)')) {
    fwrite(STDERR, "FAIL: MarketingAutomationExecutionRepository must not use raw (c.branch_id = ? OR c.branch_id IS NULL).\n");
    $ok = false;
}

if ($ok) {
    echo "verify_documents_intake_marketing_tenant_scope_foundation_01: OK\n";
}

exit($ok ? 0 : 1);
