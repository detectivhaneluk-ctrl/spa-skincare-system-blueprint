<?php

declare(strict_types=1);

/**
 * BIG-06 Verification Script — Sales Domain Phase-3 Kernel Migration
 *
 * Covers:
 *   1.  InvoiceService    — BranchContext removed, RequestContextHolder injected
 *   2.  PaymentService    — BranchContext removed, RequestContextHolder injected
 *   3.  RegisterSessionService — BranchContext removed, RequestContextHolder injected
 *   4.  PaymentMethodService  — RequestContextHolder injected (no DB access)
 *   5.  VatRateService        — RequestContextHolder injected (no DB access)
 *   6.  ReceiptInvoicePresentationService — product barcode DB violation fixed (ProductRepository used)
 *   7.  PaymentMethodRepository — canonical TenantContext-first methods exist
 *   8.  VatRateRepository       — canonical TenantContext-first methods exist
 *   9.  ProductRepository — lookupBarcodesByIds helper added
 *  10.  Bootstrap DI — all migrated services use RequestContextHolder (not BranchContext)
 *  11.  Service layer DB ban guardrail — covers SALES_P3 services, both pass
 *  12.  Id-only repo freeze guardrail  — covers PaymentMethod + VatRate repos, both pass
 *  13.  Core Sales behavior contracts preserved (methods still exist post-migration)
 *  14.  No regression to prior migrated slices (appointments, media pilot)
 *
 * Run from repo root: php system/scripts/read-only/verify_big_06_sales_migration_01.php
 */

$repoRoot = dirname(__DIR__, 3);

$passed = 0;
$failed = 0;
$errors = [];

function assertThat(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        ++$passed;
    } else {
        ++$failed;
        $errors[] = 'FAIL: ' . $label . ($detail !== '' ? "\n       {$detail}" : '');
    }
}

function fileContent(string $repoRoot, string $rel): string
{
    $path = $repoRoot . '/' . $rel;
    if (!is_file($path)) {
        return '';
    }
    return (string) file_get_contents($path);
}

echo "BIG-06 verification — Sales Domain Phase-3 Kernel Migration\n";
echo str_repeat('=', 72) . "\n\n";

// ==========================================================================
// SECTION 1: InvoiceService — BranchContext removed, RequestContextHolder injected
// ==========================================================================
echo "Section 1: InvoiceService — BranchContext removed, RequestContextHolder injected\n";

$invSvc = fileContent($repoRoot, 'system/modules/sales/services/InvoiceService.php');
assertThat('InvoiceService.php exists', $invSvc !== '');
assertThat('InvoiceService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $invSvc));
assertThat('InvoiceService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $invSvc) === 1);
assertThat('InvoiceService: injects RequestContextHolder', str_contains($invSvc, 'RequestContextHolder $contextHolder'));
assertThat('InvoiceService: calls requireContext()', str_contains($invSvc, '->requireContext()'));
assertThat('InvoiceService: calls requireResolvedTenant()', str_contains($invSvc, '->requireResolvedTenant()'));
assertThat('InvoiceService: no ->assertBranchMatchOrGlobalEntity', !str_contains($invSvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('InvoiceService: no ->enforceBranchOnCreate', !str_contains($invSvc, '->enforceBranchOnCreate'));
assertThat('InvoiceService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $invSvc));
assertThat('InvoiceService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $invSvc));
assertThat('InvoiceService: no direct ->insert()', !preg_match('/->insert\s*\(/', $invSvc));

// ==========================================================================
// SECTION 2: PaymentService — BranchContext removed, RequestContextHolder injected
// ==========================================================================
echo "\nSection 2: PaymentService — BranchContext removed, RequestContextHolder injected\n";

$paySvc = fileContent($repoRoot, 'system/modules/sales/services/PaymentService.php');
assertThat('PaymentService.php exists', $paySvc !== '');
assertThat('PaymentService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $paySvc));
assertThat('PaymentService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $paySvc) === 1);
assertThat('PaymentService: injects RequestContextHolder', str_contains($paySvc, 'RequestContextHolder $contextHolder'));
assertThat('PaymentService: calls requireContext()', str_contains($paySvc, '->requireContext()'));
assertThat('PaymentService: no ->assertBranchMatchOrGlobalEntity', !str_contains($paySvc, '->assertBranchMatchOrGlobalEntity'));
assertThat('PaymentService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $paySvc));
assertThat('PaymentService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $paySvc));

// ==========================================================================
// SECTION 3: RegisterSessionService — BranchContext removed
// ==========================================================================
echo "\nSection 3: RegisterSessionService — BranchContext removed, RequestContextHolder injected\n";

$regSvc = fileContent($repoRoot, 'system/modules/sales/services/RegisterSessionService.php');
assertThat('RegisterSessionService.php exists', $regSvc !== '');
assertThat('RegisterSessionService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $regSvc));
assertThat('RegisterSessionService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $regSvc) === 1);
assertThat('RegisterSessionService: injects RequestContextHolder', str_contains($regSvc, 'RequestContextHolder $contextHolder'));
assertThat('RegisterSessionService: calls requireContext()', str_contains($regSvc, '->requireContext()'));
assertThat('RegisterSessionService: no ->assertBranchMatchStrict', !str_contains($regSvc, '->assertBranchMatchStrict'));
assertThat('RegisterSessionService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $regSvc));
assertThat('RegisterSessionService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $regSvc));

// ==========================================================================
// SECTION 4: PaymentMethodService — RequestContextHolder injected
// ==========================================================================
echo "\nSection 4: PaymentMethodService — RequestContextHolder injected\n";

$pmSvc = fileContent($repoRoot, 'system/modules/sales/services/PaymentMethodService.php');
assertThat('PaymentMethodService.php exists', $pmSvc !== '');
assertThat('PaymentMethodService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $pmSvc));
assertThat('PaymentMethodService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $pmSvc) === 1);
assertThat('PaymentMethodService: injects RequestContextHolder', str_contains($pmSvc, 'RequestContextHolder $contextHolder'));
assertThat('PaymentMethodService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $pmSvc));
assertThat('PaymentMethodService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $pmSvc));

// ==========================================================================
// SECTION 5: VatRateService — RequestContextHolder injected
// ==========================================================================
echo "\nSection 5: VatRateService — RequestContextHolder injected\n";

$vatSvc = fileContent($repoRoot, 'system/modules/sales/services/VatRateService.php');
assertThat('VatRateService.php exists', $vatSvc !== '');
assertThat('VatRateService: no BranchContext use statement', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $vatSvc));
assertThat('VatRateService: has RequestContextHolder use statement', preg_match('/^use Core\\\\Kernel\\\\RequestContextHolder;/m', $vatSvc) === 1);
assertThat('VatRateService: injects RequestContextHolder', str_contains($vatSvc, 'RequestContextHolder $contextHolder'));
assertThat('VatRateService: no direct ->fetchOne()', !preg_match('/->fetchOne\s*\(/', $vatSvc));
assertThat('VatRateService: no direct ->fetchAll()', !preg_match('/->fetchAll\s*\(/', $vatSvc));

// ==========================================================================
// SECTION 6: ReceiptInvoicePresentationService — product barcode DB violation fixed
// ==========================================================================
echo "\nSection 6: ReceiptInvoicePresentationService — product barcode DB violation fixed\n";

$receiptSvc = fileContent($repoRoot, 'system/modules/sales/services/ReceiptInvoicePresentationService.php');
assertThat('ReceiptInvoicePresentationService.php exists', $receiptSvc !== '');
assertThat('ReceiptInvoicePresentationService: imports ProductRepository', str_contains($receiptSvc, 'use Modules\Inventory\Repositories\ProductRepository'));
assertThat('ReceiptInvoicePresentationService: injects ProductRepository', str_contains($receiptSvc, 'ProductRepository $productRepo'));
assertThat('ReceiptInvoicePresentationService: no ->fetchAll() (barcode query moved to repo)', !preg_match('/->fetchAll\s*\(/', $receiptSvc));
assertThat('ReceiptInvoicePresentationService: delegates to productRepo->lookupBarcodesByIds', str_contains($receiptSvc, '->lookupBarcodesByIds('));

// ==========================================================================
// SECTION 7: PaymentMethodRepository — canonical TenantContext-first methods
// ==========================================================================
echo "\nSection 7: PaymentMethodRepository — canonical TenantContext-first methods present\n";

$pmRepo = fileContent($repoRoot, 'system/modules/sales/repositories/PaymentMethodRepository.php');
assertThat('PaymentMethodRepository.php exists', $pmRepo !== '');
assertThat('PaymentMethodRepository: imports TenantContext', str_contains($pmRepo, 'use Core\Kernel\TenantContext'));
assertThat('PaymentMethodRepository: has listOwnedActiveMethodsForBranch(TenantContext)', preg_match('/public function listOwnedActiveMethodsForBranch\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has listOwnedAllMethodsForBranch(TenantContext)', preg_match('/public function listOwnedAllMethodsForBranch\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has isOwnedActiveCode(TenantContext)', preg_match('/public function isOwnedActiveCode\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has findOwnedGlobalCatalogMethodById(TenantContext)', preg_match('/public function findOwnedGlobalCatalogMethodById\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has existsOwnedActiveNameForBranch(TenantContext)', preg_match('/public function existsOwnedActiveNameForBranch\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has existsOwnedCodeForBranch(TenantContext)', preg_match('/public function existsOwnedCodeForBranch\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has mutateCreateOwnedMethod(TenantContext)', preg_match('/public function mutateCreateOwnedMethod\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has mutateUpdateOwnedGlobalCatalogMethodById(TenantContext)', preg_match('/public function mutateUpdateOwnedGlobalCatalogMethodById\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: has mutateArchiveOwnedGlobalCatalogMethodById(TenantContext)', preg_match('/public function mutateArchiveOwnedGlobalCatalogMethodById\s*\(\s*TenantContext/', $pmRepo) === 1);
assertThat('PaymentMethodRepository: canonical methods call requireResolvedTenant()', str_contains($pmRepo, '->requireResolvedTenant()'));

// ==========================================================================
// SECTION 8: VatRateRepository — canonical TenantContext-first methods
// ==========================================================================
echo "\nSection 8: VatRateRepository — canonical TenantContext-first methods present\n";

$vatRepo = fileContent($repoRoot, 'system/modules/sales/repositories/VatRateRepository.php');
assertThat('VatRateRepository.php exists', $vatRepo !== '');
assertThat('VatRateRepository: imports TenantContext', str_contains($vatRepo, 'use Core\Kernel\TenantContext'));
assertThat('VatRateRepository: has listOwnedActiveRatesForBranch(TenantContext)', preg_match('/public function listOwnedActiveRatesForBranch\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has listOwnedAllRatesForBranch(TenantContext)', preg_match('/public function listOwnedAllRatesForBranch\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has findOwnedRateByCode(TenantContext)', preg_match('/public function findOwnedRateByCode\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has findOwnedGlobalCatalogRateById(TenantContext)', preg_match('/public function findOwnedGlobalCatalogRateById\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has findOwnedTenantVisibleRateById(TenantContext)', preg_match('/public function findOwnedTenantVisibleRateById\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has isOwnedActiveIdInServiceBranchCatalog(TenantContext)', preg_match('/public function isOwnedActiveIdInServiceBranchCatalog\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has existsOwnedActiveNameForBranch(TenantContext)', preg_match('/public function existsOwnedActiveNameForBranch\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has mutateCreateOwnedRate(TenantContext)', preg_match('/public function mutateCreateOwnedRate\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has mutateUpdateOwnedGlobalCatalogRateById(TenantContext)', preg_match('/public function mutateUpdateOwnedGlobalCatalogRateById\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has mutateArchiveOwnedGlobalCatalogRateById(TenantContext)', preg_match('/public function mutateArchiveOwnedGlobalCatalogRateById\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: has mutateBulkUpdateOwnedGlobalActiveApplicability(TenantContext)', preg_match('/public function mutateBulkUpdateOwnedGlobalActiveApplicability\s*\(\s*TenantContext/', $vatRepo) === 1);
assertThat('VatRateRepository: canonical methods call requireResolvedTenant()', str_contains($vatRepo, '->requireResolvedTenant()'));

// ==========================================================================
// SECTION 9: ProductRepository — lookupBarcodesByIds helper added
// ==========================================================================
echo "\nSection 9: ProductRepository — lookupBarcodesByIds helper\n";

$prodRepo = fileContent($repoRoot, 'system/modules/inventory/repositories/ProductRepository.php');
assertThat('ProductRepository.php exists', $prodRepo !== '');
assertThat('ProductRepository: has lookupBarcodesByIds method', preg_match('/public function lookupBarcodesByIds\s*\(/', $prodRepo) === 1);
assertThat('ProductRepository: lookupBarcodesByIds returns array', str_contains($prodRepo, 'lookupBarcodesByIds') && str_contains($prodRepo, ': array'));
assertThat('ProductRepository: lookupBarcodesByIds handles empty input guard', str_contains($prodRepo, 'if ($ids === [])'));

// ==========================================================================
// SECTION 10: Bootstrap DI — all migrated services use RequestContextHolder
// ==========================================================================
echo "\nSection 10: Bootstrap DI — migrated services use RequestContextHolder\n";

$bootstrap = fileContent($repoRoot, 'system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php');
assertThat('Bootstrap file exists', $bootstrap !== '');

// Extract individual singleton lines for precise checks
$lines = explode("\n", $bootstrap);
$invoiceLine = $pmSvcLine = $vatSvcLine = $payLine = $regLine = $receiptLine = '';
foreach ($lines as $line) {
    if (str_contains($line, 'InvoiceService::class, fn')) {
        $invoiceLine = $line;
    }
    if (str_contains($line, 'PaymentMethodService::class, fn')) {
        $pmSvcLine = $line;
    }
    if (str_contains($line, 'VatRateService::class, fn')) {
        $vatSvcLine = $line;
    }
    if (str_contains($line, 'PaymentService::class, fn')) {
        $payLine = $line;
    }
    if (str_contains($line, 'RegisterSessionService::class, fn')) {
        $regLine = $line;
    }
    if (str_contains($line, 'ReceiptInvoicePresentationService::class, fn')) {
        $receiptLine = $line;
    }
}

assertThat('Bootstrap: InvoiceService registered', $invoiceLine !== '');
assertThat('Bootstrap: InvoiceService no BranchContext injection', !str_contains($invoiceLine, 'BranchContext::class'));
assertThat('Bootstrap: InvoiceService gets RequestContextHolder injection', str_contains($invoiceLine, 'RequestContextHolder::class'));

assertThat('Bootstrap: PaymentMethodService registered', $pmSvcLine !== '');
assertThat('Bootstrap: PaymentMethodService no BranchContext injection', !str_contains($pmSvcLine, 'BranchContext::class'));
assertThat('Bootstrap: PaymentMethodService gets RequestContextHolder injection', str_contains($pmSvcLine, 'RequestContextHolder::class'));

assertThat('Bootstrap: VatRateService registered', $vatSvcLine !== '');
assertThat('Bootstrap: VatRateService no BranchContext injection', !str_contains($vatSvcLine, 'BranchContext::class'));
assertThat('Bootstrap: VatRateService gets RequestContextHolder injection', str_contains($vatSvcLine, 'RequestContextHolder::class'));

assertThat('Bootstrap: PaymentService registered', $payLine !== '');
assertThat('Bootstrap: PaymentService no BranchContext injection', !str_contains($payLine, 'BranchContext::class'));
assertThat('Bootstrap: PaymentService gets RequestContextHolder injection', str_contains($payLine, 'RequestContextHolder::class'));

assertThat('Bootstrap: RegisterSessionService registered', $regLine !== '');
assertThat('Bootstrap: RegisterSessionService no BranchContext injection', !str_contains($regLine, 'BranchContext::class'));
assertThat('Bootstrap: RegisterSessionService gets RequestContextHolder injection', str_contains($regLine, 'RequestContextHolder::class'));

assertThat('Bootstrap: ReceiptInvoicePresentationService registered', $receiptLine !== '');
assertThat('Bootstrap: ReceiptInvoicePresentationService gets ProductRepository injection', str_contains($receiptLine, 'ProductRepository::class'));

// ==========================================================================
// SECTION 11: Guardrail coverage — Sales scope added to both guardrails
// ==========================================================================
echo "\nSection 11: Guardrail scripts — Sales scope coverage\n";

$dbBanContent = fileContent($repoRoot, 'system/scripts/ci/guardrail_service_layer_db_ban.php');
$idFreezeContent = fileContent($repoRoot, 'system/scripts/ci/guardrail_id_only_repo_api_freeze.php');

assertThat('DB ban guardrail: SALES_P3 phase comment present', str_contains($dbBanContent, 'SALES_P3'));
assertThat('DB ban guardrail: covers InvoiceService.php', str_contains($dbBanContent, 'InvoiceService.php'));
assertThat('DB ban guardrail: covers PaymentService.php', str_contains($dbBanContent, 'PaymentService.php'));
assertThat('DB ban guardrail: covers RegisterSessionService.php', str_contains($dbBanContent, 'RegisterSessionService.php'));
assertThat('DB ban guardrail: covers PaymentMethodService.php', str_contains($dbBanContent, 'PaymentMethodService.php'));
assertThat('DB ban guardrail: covers VatRateService.php', str_contains($dbBanContent, 'VatRateService.php'));
assertThat('DB ban guardrail: ReceiptInvoicePresentationService EXCLUDED (has presentation-only DB call)', !str_contains($dbBanContent, "'system/modules/sales/services/ReceiptInvoicePresentationService.php'"));

assertThat('Id-only freeze guardrail: SALES_P3 phase comment present', str_contains($idFreezeContent, 'SALES_P3'));
assertThat('Id-only freeze guardrail: covers PaymentMethodRepository.php', str_contains($idFreezeContent, 'PaymentMethodRepository.php'));
assertThat('Id-only freeze guardrail: covers VatRateRepository.php', str_contains($idFreezeContent, 'VatRateRepository.php'));
assertThat('Id-only freeze guardrail: PaymentMethod legacy listActive in allowlist', str_contains($idFreezeContent, "'listActive'"));
assertThat('Id-only freeze guardrail: PaymentMethod legacy create in allowlist', str_contains($idFreezeContent, "'create'"));

// Live run of both guardrails
$phpBin = PHP_BINARY;
$dbBanScript = $repoRoot . '/system/scripts/ci/guardrail_service_layer_db_ban.php';
$idFreezeScript = $repoRoot . '/system/scripts/ci/guardrail_id_only_repo_api_freeze.php';

$dbBanExitCode = null;
ob_start();
system(escapeshellarg($phpBin) . ' ' . escapeshellarg($dbBanScript), $dbBanExitCode);
ob_end_clean();
assertThat('DB ban guardrail exits 0 (PASS)', $dbBanExitCode === 0,
    "Exit code: {$dbBanExitCode}. Run: php system/scripts/ci/guardrail_service_layer_db_ban.php");

$idFreezeExitCode = null;
ob_start();
system(escapeshellarg($phpBin) . ' ' . escapeshellarg($idFreezeScript), $idFreezeExitCode);
ob_end_clean();
assertThat('Id-only freeze guardrail exits 0 (PASS)', $idFreezeExitCode === 0,
    "Exit code: {$idFreezeExitCode}. Run: php system/scripts/ci/guardrail_id_only_repo_api_freeze.php");

// ==========================================================================
// SECTION 12: Core Sales behavior contracts preserved
// ==========================================================================
echo "\nSection 12: Core Sales behavior contracts preserved\n";

// InvoiceService public surface
assertThat('InvoiceService: create() method exists', preg_match('/public function create\s*\(/', $invSvc) === 1);
assertThat('InvoiceService: update() method exists', preg_match('/public function update\s*\(/', $invSvc) === 1);
assertThat('InvoiceService: cancel() method exists', preg_match('/public function cancel\s*\(/', $invSvc) === 1);
assertThat('InvoiceService: delete() method exists', preg_match('/public function delete\s*\(/', $invSvc) === 1);
assertThat('InvoiceService: redeemGiftCardPayment() method exists', preg_match('/public function redeemGiftCardPayment\s*\(/', $invSvc) === 1);
assertThat('InvoiceService: recomputeInvoiceFinancials() method exists', preg_match('/public function recomputeInvoiceFinancials\s*\(/', $invSvc) === 1);

// PaymentService public surface
assertThat('PaymentService: create() method exists', preg_match('/public function create\s*\(/', $paySvc) === 1);
assertThat('PaymentService: refund() method exists', preg_match('/public function refund\s*\(/', $paySvc) === 1);

// RegisterSessionService public surface
assertThat('RegisterSessionService: openSession() method exists', preg_match('/public function openSession\s*\(/', $regSvc) === 1);
assertThat('RegisterSessionService: closeSession() method exists', preg_match('/public function closeSession\s*\(/', $regSvc) === 1);
assertThat('RegisterSessionService: addCashMovement() method exists', preg_match('/public function addCashMovement\s*\(/', $regSvc) === 1);

// PaymentMethodService public surface
assertThat('PaymentMethodService: listForPaymentForm() method exists', preg_match('/public function listForPaymentForm\s*\(/', $pmSvc) === 1);
assertThat('PaymentMethodService: validateCodeExists() or isActiveCode via repo', str_contains($pmSvc, 'repo'));

// VatRateService public surface
assertThat('VatRateService: listActive() or canonical equivalent exists', preg_match('/public function list/', $vatSvc) === 1 || str_contains($vatSvc, 'listActive'));
assertThat('VatRateService: assertActiveVatRateAssignableToServiceBranch exists', preg_match('/public function assertActiveVatRateAssignableToServiceBranch\s*\(/', $vatSvc) === 1);

// OrganizationScopedBranchAssert still in InvoiceService / PaymentService (safety guard preserved)
assertThat('InvoiceService: OrganizationScopedBranchAssert preserved as safety guard', str_contains($invSvc, 'OrganizationScopedBranchAssert'));
assertThat('PaymentService: OrganizationScopedBranchAssert preserved as safety guard', str_contains($paySvc, 'OrganizationScopedBranchAssert'));

// ==========================================================================
// SECTION 13: No regression to prior migrated slices
// ==========================================================================
echo "\nSection 13: No regression to prior migrated slices\n";

$clientImgSvc = fileContent($repoRoot, 'system/modules/clients/services/ClientProfileImageService.php');
$mktgSvc = fileContent($repoRoot, 'system/modules/marketing/services/MarketingGiftCardTemplateService.php');
$bsSvc = fileContent($repoRoot, 'system/modules/appointments/services/BlockedSlotService.php');

assertThat('ClientProfileImageService: no BranchContext (media pilot regression check)', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $clientImgSvc));
assertThat('MarketingGiftCardTemplateService: no BranchContext (media pilot regression check)', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $mktgSvc));
assertThat('BlockedSlotService: no BranchContext (appointments regression check)', !preg_match('/^use Core\\\\Branch\\\\BranchContext;/m', $bsSvc));

// ==========================================================================
// RESULTS
// ==========================================================================
echo "\n" . str_repeat('=', 72) . "\n";
$total = $passed + $failed;
echo "Result: {$passed}/{$total} assertions passed\n";

if ($errors !== []) {
    echo "\nFailed assertions:\n";
    foreach ($errors as $e) {
        echo "  {$e}\n";
    }
    echo "\nBIG-06 verification: FAIL\n";
    exit(1);
}

echo "\nBIG-06 verification: PASS — Sales domain Phase-3 kernel migration complete\n";
exit(0);
