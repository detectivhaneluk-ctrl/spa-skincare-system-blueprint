-- Staff trash metadata: actor + auto-purge schedule (STAFF-BULK-TRASH-WORDPRESS-STYLE-01).
ALTER TABLE staff
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL COMMENT 'User who moved the staff row to trash.' AFTER deleted_at,
    ADD COLUMN purge_after_at DATETIME NULL COMMENT 'When a trashed row becomes eligible for physical purge.' AFTER deleted_by,
    ADD INDEX idx_staff_trash_purge (purge_after_at, deleted_at),
    ADD CONSTRAINT fk_staff_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE staff
SET purge_after_at = DATE_ADD(deleted_at, INTERVAL 30 DAY)
WHERE deleted_at IS NOT NULL AND purge_after_at IS NULL;
