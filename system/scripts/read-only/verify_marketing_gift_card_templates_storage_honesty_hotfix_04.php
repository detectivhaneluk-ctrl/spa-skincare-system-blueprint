<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-TEMPLATES-STORAGE-HONESTY-HOTFIX-04
 *
 * Static proof: storage-not-ready is distinct from empty catalog in views, mutations are
 * guarded in the controller, and the shared notice partial exists.
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_gift_card_templates_storage_honesty_hotfix_04.php
 */

$base = dirname(__DIR__, 2);

$failed = false;

$partial = $base . '/modules/marketing/views/gift-card-templates/partials/storage-not-ready-notice.php';
echo 'partial_storage_notice_exists=' . (is_file($partial) ? 'yes' : 'no') . PHP_EOL;
if (!is_file($partial)) {
    $failed = true;
}

$noticeText = 'Apply migration 102 to enable template and image management';
$noticeBody = (string) file_get_contents($partial);
echo 'partial_contains_migration_102_message=' . (str_contains($noticeBody, $noticeText) ? 'yes' : 'no') . PHP_EOL;
if (!str_contains($noticeBody, $noticeText)) {
    $failed = true;
}

$controllerPath = $base . '/modules/marketing/controllers/MarketingGiftCardTemplatesController.php';
$controllerText = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
$guardCount = substr_count($controllerText, 'if (!$this->service->isStorageReady())');
echo 'controller_mutation_storage_guards=' . $guardCount . PHP_EOL;
if ($guardCount < 5) {
    $failed = true;
}
echo 'controller_index_passes_storage_ready=' . (str_contains($controllerText, '$storageReady = $this->service->isStorageReady()') ? 'yes' : 'no') . PHP_EOL;
if (!str_contains($controllerText, '$storageReady = $this->service->isStorageReady()')) {
    $failed = true;
}

$viewNames = ['index', 'create', 'edit', 'images'];
foreach ($viewNames as $name) {
    $path = $base . '/modules/marketing/views/gift-card-templates/' . $name . '.php';
    $text = is_file($path) ? (string) file_get_contents($path) : '';
    $ok = str_contains($text, '$storageReady') && str_contains($text, 'storage-not-ready-notice');
    echo 'view_' . $name . '_storage_honesty_wired=' . ($ok ? 'yes' : 'no') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

$indexText = is_file($base . '/modules/marketing/views/gift-card-templates/index.php')
    ? (string) file_get_contents($base . '/modules/marketing/views/gift-card-templates/index.php')
    : '';
echo 'index_table_only_when_storage_ready=' . (
    str_contains($indexText, '<?php if ($storageReady): ?>') && str_contains($indexText, 'No active gift card templates yet.')
        ? 'yes'
        : 'no'
) . PHP_EOL;
if (!str_contains($indexText, '<?php if ($storageReady): ?>') || !str_contains($indexText, 'No active gift card templates yet.')) {
    $failed = true;
}

try {
    require $base . '/bootstrap.php';
    require $base . '/modules/bootstrap.php';
    $service = app(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class);
    $ready = $service->isStorageReady();
    echo 'runtime_storage_ready=' . ($ready ? 'yes' : 'no') . PHP_EOL;
    if (!$ready) {
        echo 'runtime_storage_not_ready_honest_path_expected=yes' . PHP_EOL;
    } else {
        echo 'runtime_storage_ready_normal_path_expected=yes' . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'runtime_probe_skipped=' . preg_replace('/\s+/', ' ', $e->getMessage()) . PHP_EOL;
}

if ($failed) {
    echo 'storage_honesty_hotfix_04_status=FAIL' . PHP_EOL;
    exit(1);
}

echo 'storage_honesty_hotfix_04_status=PASS' . PHP_EOL;
exit(0);
