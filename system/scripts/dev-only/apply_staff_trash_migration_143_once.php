<?php

declare(strict_types=1);

/**
 * Dev helper: apply 143_staff_trash_metadata.sql when migrate.php is blocked.
 * Idempotent when columns already exist.
 *
 *   php system/scripts/dev-only/apply_staff_trash_migration_143_once.php
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$pdo = $db->connection();
$row = $db->fetchOne("SHOW COLUMNS FROM staff LIKE 'purge_after_at'");
if ($row) {
    fwrite(STDOUT, "staff.purge_after_at already exists — nothing to do.\n");
    exit(0);
}

$pdo->exec(
    'ALTER TABLE staff '
    . 'ADD COLUMN deleted_by BIGINT UNSIGNED NULL COMMENT \'User who moved the staff row to trash.\' AFTER deleted_at, '
    . 'ADD COLUMN purge_after_at DATETIME NULL COMMENT \'When a trashed row becomes eligible for physical purge.\' AFTER deleted_by, '
    . 'ADD INDEX idx_staff_trash_purge (purge_after_at, deleted_at), '
    . 'ADD CONSTRAINT fk_staff_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL'
);
$pdo->exec(
    'UPDATE staff SET purge_after_at = DATE_ADD(deleted_at, INTERVAL 30 DAY) '
    . 'WHERE deleted_at IS NOT NULL AND purge_after_at IS NULL'
);
fwrite(STDOUT, "Applied staff trash columns (143).\n");
exit(0);
