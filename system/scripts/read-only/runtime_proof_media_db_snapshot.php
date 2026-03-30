<?php

declare(strict_types=1);

/**
 * One-shot DB snapshot for IMAGE-PIPELINE-RUNTIME-PROOF-11 (read-only).
 */
require dirname(__DIR__, 2) . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();

foreach (['media_assets', 'media_asset_variants', 'media_jobs'] as $t) {
    $s = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $s->execute([$t]);
    echo 'table_' . $t . '=' . ($s->fetchColumn() ? 'yes' : 'no') . PHP_EOL;
}

$codes = $pdo->query("SELECT code FROM permissions WHERE code IN ('media.upload','media.view') ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
echo 'permissions=' . implode(',', $codes ?: []) . PHP_EOL;

$row = $pdo->query("SELECT 1 FROM migrations WHERE migration = '103_media_image_pipeline_foundation.sql' LIMIT 1")->fetchColumn();
echo 'migration_103_stamped=' . ($row ? 'yes' : 'no') . PHP_EOL;
