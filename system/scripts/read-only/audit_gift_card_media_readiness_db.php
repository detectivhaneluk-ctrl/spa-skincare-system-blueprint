<?php

declare(strict_types=1);

/**
 * Read-only: which DB, migrations 103/105, media tables, media_asset_id column + FK.
 * Usage (from system/): php scripts/read-only/audit_gift_card_media_readiness_db.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

echo 'loaded_env_files=' . json_encode(\Core\App\Env::loadedEnvFilePaths()) . PHP_EOL;
echo 'DB_DATABASE_env=' . (string) env('DB_DATABASE', '') . PHP_EOL;

$db = app(\Core\App\Database::class);
$r = $db->fetchOne('SELECT DATABASE() AS d');
echo 'connected_database=' . (string) ($r['d'] ?? '') . PHP_EOL;

$m = $db->fetchAll(
    "SELECT migration FROM migrations
     WHERE migration LIKE '%103%' OR migration LIKE '%105%' OR migration LIKE '%102%'
     ORDER BY migration"
);
echo 'migrations_102_103_105=' . json_encode(array_column($m, 'migration')) . PHP_EOL;

foreach (['media_assets', 'media_jobs', 'media_asset_variants', 'marketing_gift_card_images', 'marketing_gift_card_templates'] as $t) {
    $x = $db->fetchOne(
        'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$t]
    );
    echo 'table_' . $t . '=' . ($x ? 'yes' : 'no') . PHP_EOL;
}

$col = $db->fetchOne(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'marketing_gift_card_images'
       AND COLUMN_NAME = 'media_asset_id'"
);
echo 'column_media_asset_id=' . json_encode($col ?: null) . PHP_EOL;

$fk = $db->fetchOne(
    "SELECT CONSTRAINT_NAME
     FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'marketing_gift_card_images'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'
       AND CONSTRAINT_NAME = 'fk_mkt_gc_images_media_asset'"
);
echo 'fk_mkt_gc_images_media_asset=' . json_encode($fk ?: null) . PHP_EOL;

$repo = app(\Modules\Marketing\Repositories\MarketingGiftCardTemplateRepository::class);
echo 'repo_is_storage_ready=' . ($repo->isStorageReady() ? 'yes' : 'no') . PHP_EOL;
echo 'repo_is_media_pipeline_present=' . ($repo->isMediaPipelinePresent() ? 'yes' : 'no') . PHP_EOL;
echo 'repo_is_media_bridge_ready=' . ($repo->isMediaBridgeReady() ? 'yes' : 'no') . PHP_EOL;
