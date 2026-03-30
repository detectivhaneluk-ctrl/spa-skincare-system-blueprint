<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-TEMPLATES-ZIP-TRUTH-RECOVERY-02
 * MARKETING-GIFT-CARD-TEMPLATES-REAL-BACKEND-INTEGRATION-03 (ZIP / route / DI contract gate)
 *
 * Treats the working tree as source of truth: fails (exit 1) if expected paths or
 * register/bootstrap/nav contracts are missing — so the next ZIP cannot silently drop them.
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_gift_card_templates_zip_truth_recovery_02.php
 */

$base = dirname(__DIR__, 2);

$requiredFiles = [
    'repo' => $base . '/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php',
    'service' => $base . '/modules/marketing/services/MarketingGiftCardTemplateService.php',
    'controller' => $base . '/modules/marketing/controllers/MarketingGiftCardTemplatesController.php',
    'view_index' => $base . '/modules/marketing/views/gift-card-templates/index.php',
    'view_create' => $base . '/modules/marketing/views/gift-card-templates/create.php',
    'view_edit' => $base . '/modules/marketing/views/gift-card-templates/edit.php',
    'view_images' => $base . '/modules/marketing/views/gift-card-templates/images.php',
    'view_partial_storage_notice' => $base . '/modules/marketing/views/gift-card-templates/partials/storage-not-ready-notice.php',
    'migration_102' => $base . '/data/migrations/102_marketing_gift_card_templates_foundation.sql',
    'verifier_foundation_01' => $base . '/scripts/read-only/verify_marketing_gift_card_templates_backend_foundation_01.php',
    'verifier_storage_honesty_hotfix_04' => $base . '/scripts/read-only/verify_marketing_gift_card_templates_storage_honesty_hotfix_04.php',
    'verifier_post_migration_runtime_05' => $base . '/scripts/read-only/verify_marketing_gift_card_templates_post_migration_runtime_05.php',
    'ops_note_foundation_01' => $base . '/docs/MARKETING-GIFT-CARD-TEMPLATES-BACKEND-FOUNDATION-01-OPS.md',
    'ops_note_integration_03' => $base . '/docs/MARKETING-GIFT-CARD-TEMPLATES-REAL-BACKEND-INTEGRATION-03-OPS.md',
];

$failed = false;
foreach ($requiredFiles as $key => $path) {
    $ok = is_file($path);
    echo 'physical_file_' . $key . '=' . ($ok ? 'yes' : 'no') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

$routeFile = $base . '/routes/web/register_marketing.php';
$routeText = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
$requiredRoutes = [
    'get_index' => "/marketing/gift-card-templates'",
    'get_create' => "/marketing/gift-card-templates/create'",
    'post_store' => "->post('/marketing/gift-card-templates'",
    'get_edit' => "/marketing/gift-card-templates/{id:\\d+}/edit'",
    'post_update' => "->post('/marketing/gift-card-templates/{id:\\d+}'",
    'post_archive' => "/marketing/gift-card-templates/{id:\\d+}/archive'",
    'get_images' => "/marketing/gift-card-templates/images'",
    'post_images' => "->post('/marketing/gift-card-templates/images'",
    'post_image_delete' => "/marketing/gift-card-templates/images/{id:\\d+}/delete'",
];
foreach ($requiredRoutes as $k => $needle) {
    $ok = str_contains($routeText, $needle);
    echo 'route_' . $k . '=' . ($ok ? 'yes' : 'no') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

$bootstrapFile = $base . '/modules/bootstrap/register_marketing.php';
$bootstrapText = is_file($bootstrapFile) ? (string) file_get_contents($bootstrapFile) : '';
$diNeedles = [
    'MarketingGiftCardTemplateRepository',
    'MarketingGiftCardTemplateService',
    'MarketingGiftCardTemplatesController',
];
foreach ($diNeedles as $classSuffix) {
    $ok = str_contains($bootstrapText, $classSuffix);
    echo 'di_registers_' . str_replace('\\', '_', strtolower($classSuffix)) . '=' . ($ok ? 'yes' : 'no') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

$navFile = $base . '/modules/marketing/views/partials/marketing-top-nav.php';
$navText = is_file($navFile) ? (string) file_get_contents($navFile) : '';
$navLive = str_contains($navText, "['id' => 'gift_cards', 'href' => '/marketing/gift-card-templates'");
echo 'nav_gift_cards_href_live=' . ($navLive ? 'yes' : 'no') . PHP_EOL;
if (!$navLive) {
    $failed = true;
}

$schemaFile = $base . '/data/full_project_schema.sql';
$schemaText = is_file($schemaFile) ? (string) file_get_contents($schemaFile) : '';
$schemaTemplates = str_contains($schemaText, 'CREATE TABLE marketing_gift_card_templates');
$schemaImages = str_contains($schemaText, 'CREATE TABLE marketing_gift_card_images');
echo 'canonical_schema_templates_table=' . ($schemaTemplates ? 'yes' : 'no') . PHP_EOL;
echo 'canonical_schema_images_table=' . ($schemaImages ? 'yes' : 'no') . PHP_EOL;
if (!$schemaTemplates || !$schemaImages) {
    $failed = true;
}

$migrationText = is_file($requiredFiles['migration_102']) ? (string) file_get_contents($requiredFiles['migration_102']) : '';
echo 'migration_102_defines_templates=' . (str_contains($migrationText, 'CREATE TABLE marketing_gift_card_templates') ? 'yes' : 'no') . PHP_EOL;
echo 'migration_102_defines_images=' . (str_contains($migrationText, 'CREATE TABLE marketing_gift_card_images') ? 'yes' : 'no') . PHP_EOL;
if (!str_contains($migrationText, 'CREATE TABLE marketing_gift_card_templates') || !str_contains($migrationText, 'CREATE TABLE marketing_gift_card_images')) {
    $failed = true;
}

if ($failed) {
    echo 'zip_truth_recovery_status=FAIL' . PHP_EOL;
    exit(1);
}

echo 'zip_truth_recovery_status=PASS' . PHP_EOL;

// Optional: storage_not_ready guard (needs DB + full bootstrap; same as foundation verifier).
try {
    require $base . '/bootstrap.php';
    require $base . '/modules/bootstrap.php';
    $db = app(\Core\App\Database::class);
    $pdo = $db->connection();
    $tables = ['marketing_gift_card_templates', 'marketing_gift_card_images'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table]);
        echo 'runtime_table_' . $table . '_exists=' . ($stmt->fetch(\PDO::FETCH_ASSOC) ? 'yes' : 'no') . PHP_EOL;
    }
    $service = app(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class);
    $ready = $service->isStorageReady();
    echo 'storage_ready=' . ($ready ? 'yes' : 'no') . PHP_EOL;
    if (!$ready) {
        echo 'storage_not_ready_skip_mutations=yes' . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'runtime_storage_probe=skipped' . PHP_EOL;
    echo 'runtime_storage_probe_reason=' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL;
}

exit(0);
