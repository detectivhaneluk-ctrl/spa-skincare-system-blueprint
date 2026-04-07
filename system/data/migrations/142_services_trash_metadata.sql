-- Services trash metadata: actor + auto-purge schedule (SERVICES-BULK-TRASH-WORDPRESS-STYLE-01).
ALTER TABLE services
    ADD COLUMN deleted_by BIGINT UNSIGNED NULL COMMENT 'User who moved the service to trash.' AFTER deleted_at,
    ADD COLUMN purge_after_at DATETIME NULL COMMENT 'When a trashed row becomes eligible for physical purge.' AFTER deleted_by,
    ADD INDEX idx_services_trash_purge (purge_after_at, deleted_at),
    ADD CONSTRAINT fk_services_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL;

-- Backfill purge schedule for existing trashed rows (align with default retention; runtime uses config).
UPDATE services
SET purge_after_at = DATE_ADD(deleted_at, INTERVAL 30 DAY)
WHERE deleted_at IS NOT NULL AND purge_after_at IS NULL;
