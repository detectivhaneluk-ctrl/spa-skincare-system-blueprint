<?php

declare(strict_types=1);

/**
 * Dev helper: apply 142_services_trash_metadata.sql when migrate.php is blocked by unrelated pending files.
 * Idempotent when columns already exist.
 *
 *   php system/scripts/dev-only/apply_services_trash_migration_142_once.php
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$pdo = $db->connection();
$row = $db->fetchOne("SHOW COLUMNS FROM services LIKE 'purge_after_at'");
if ($row) {
    fwrite(STDOUT, "services.purge_after_at already exists — nothing to do.\n");
    exit(0);
}

$pdo->exec(
    'ALTER TABLE services '
    . 'ADD COLUMN deleted_by BIGINT UNSIGNED NULL COMMENT \'User who moved the service to trash.\' AFTER deleted_at, '
    . 'ADD COLUMN purge_after_at DATETIME NULL COMMENT \'When a trashed row becomes eligible for physical purge.\' AFTER deleted_by, '
    . 'ADD INDEX idx_services_trash_purge (purge_after_at, deleted_at), '
    . 'ADD CONSTRAINT fk_services_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL'
);
$pdo->exec(
    'UPDATE services SET purge_after_at = DATE_ADD(deleted_at, INTERVAL 30 DAY) '
    . 'WHERE deleted_at IS NOT NULL AND purge_after_at IS NULL'
);
fwrite(STDOUT, "Applied services trash columns (142).\n");
exit(0);
