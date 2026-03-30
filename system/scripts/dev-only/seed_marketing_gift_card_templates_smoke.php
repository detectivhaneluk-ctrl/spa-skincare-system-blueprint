<?php

declare(strict_types=1);

/**
 * Dev-only: minimal marketing_gift_card_images + marketing_gift_card_templates rows for local UI smoke.
 * Idempotent; direct DB inserts (no HTTP org context). APP_ENV must be "local".
 *
 * Usage (from system/): php scripts/dev-only/seed_marketing_gift_card_templates_smoke.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

if (trim((string) env('APP_ENV', '')) !== 'local') {
    fwrite(STDERR, "Refusing to run: APP_ENV must be local.\n");
    exit(1);
}

$db = app(\Core\App\Database::class);

$imgTable = $db->fetchOne(
    'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
    ['marketing_gift_card_images']
);
if ($imgTable === null) {
    fwrite(STDERR, "marketing_gift_card_images missing; run php scripts/migrate.php first.\n");
    exit(1);
}

$branchRow = $db->fetchOne(
    "SELECT id FROM branches WHERE code = 'SMOKE_A' AND deleted_at IS NULL LIMIT 1"
) ?? $db->fetchOne('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
$branchId = $branchRow ? (int) ($branchRow['id'] ?? 0) : 0;
if ($branchId <= 0) {
    fwrite(STDERR, "No branch row; cannot seed.\n");
    exit(1);
}

$marker = 'DEV-SMOKE-GC-1';
$already = $db->fetchOne(
    'SELECT id FROM marketing_gift_card_templates WHERE branch_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1',
    [$branchId, $marker]
);
if ($already !== null) {
    echo "Skip: DEV-SMOKE-GC templates already present for branch_id={$branchId}.\n";
    exit(0);
}

$imageRow = [
    'branch_id' => $branchId,
    'title' => 'DEV smoke gift card art',
    'storage_path' => 'dev-only/smoke/gift-card-placeholder.bin',
    'filename' => 'dev-smoke-placeholder.bin',
    'mime_type' => 'application/octet-stream',
    'size_bytes' => 1,
    'is_active' => 1,
    'created_by' => null,
    'updated_by' => null,
];
$hasMediaBridge = $db->fetchOne(
    "SELECT 1 AS ok FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_gift_card_images' AND COLUMN_NAME = 'media_asset_id'
     LIMIT 1"
);
if ($hasMediaBridge) {
    $imageRow['media_asset_id'] = null;
}
$db->insert('marketing_gift_card_images', $imageRow);
$imageId = (int) $db->lastInsertId();

$templates = [
    ['name' => 'DEV-SMOKE-GC-1', 'image_id' => $imageId],
    ['name' => 'DEV-SMOKE-GC-2', 'image_id' => null],
    ['name' => 'DEV-SMOKE-GC-3', 'image_id' => null],
];
foreach ($templates as $t) {
    $db->insert('marketing_gift_card_templates', [
        'branch_id' => $branchId,
        'name' => $t['name'],
        'clone_source_template_id' => null,
        'sell_in_store_enabled' => 1,
        'sell_online_enabled' => 1,
        'image_id' => $t['image_id'],
        'is_active' => 1,
        'created_by' => null,
        'updated_by' => null,
    ]);
}

echo "Seeded branch_id={$branchId}: 1 image (id={$imageId}), 3 templates.\n";
