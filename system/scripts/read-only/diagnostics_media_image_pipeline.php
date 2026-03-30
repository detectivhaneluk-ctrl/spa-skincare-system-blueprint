<?php

declare(strict_types=1);

/**
 * Read-only diagnostics: tables, permissions, pending counts, quarantine path writability.
 *
 * Run from the `system/` directory:
 *   php scripts/read-only/diagnostics_media_image_pipeline.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class)->connection();

$has = static function (string $table) use ($db): bool {
    $s = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $s->execute([$table]);

    return (bool) $s->fetchColumn();
};

foreach (['media_assets', 'media_jobs', 'media_asset_variants'] as $t) {
    echo 'table_' . $t . '=' . ($has($t) ? 'present' : 'MISSING') . PHP_EOL;
}

if (!$has('media_assets') || !$has('media_jobs') || !$has('media_asset_variants')) {
    echo 'abort=missing_tables' . PHP_EOL;
    exit(1);
}

$permStmt = $db->prepare('SELECT code FROM permissions WHERE code IN (\'media.upload\', \'media.view\') ORDER BY code');
$permStmt->execute();
$permCodes = $permStmt->fetchAll(PDO::FETCH_COLUMN);
echo 'permissions_media_upload_present=' . (in_array('media.upload', $permCodes, true) ? 'yes' : 'no') . PHP_EOL;
echo 'permissions_media_view_present=' . (in_array('media.view', $permCodes, true) ? 'yes' : 'no') . PHP_EOL;

$row = $db->query('SELECT COUNT(*) AS c FROM media_assets WHERE status = \'pending\'')->fetch(PDO::FETCH_ASSOC);
echo 'assets_pending=' . (int) ($row['c'] ?? 0) . PHP_EOL;

$row = $db->query('SELECT COUNT(*) AS c FROM media_jobs WHERE status = \'pending\'')->fetch(PDO::FETCH_ASSOC);
echo 'jobs_pending=' . (int) ($row['c'] ?? 0) . PHP_EOL;

$systemPath = dirname(__DIR__, 2);
$quarantineRoot = $systemPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine';

echo 'quarantine_path=' . $quarantineRoot . PHP_EOL;
echo 'quarantine_is_dir=' . (is_dir($quarantineRoot) ? 'yes' : 'no') . PHP_EOL;
echo 'quarantine_is_writable=' . (is_dir($quarantineRoot) && is_writable($quarantineRoot) ? 'yes' : 'no') . PHP_EOL;
$quarantineParent = dirname($quarantineRoot);
echo 'quarantine_parent_writable=' . (is_dir($quarantineParent) && is_writable($quarantineParent) ? 'yes' : 'no') . PHP_EOL;

echo 'done' . PHP_EOL;
