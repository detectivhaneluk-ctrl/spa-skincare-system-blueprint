-- WAVE-04: Audit log archival foundation.
-- Adds archived_at column and archival_batch_id to support row-level archival
-- without deleting rows immediately (soft-archival pattern).
--
-- Retention policy (enforced by run_audit_log_archival_cron.php):
--   Rows older than AUDIT_LOG_RETENTION_DAYS (default: 365) are marked
--   archived_at = NOW() and moved to audit_logs_archive in batches.
--
-- ONLINE-DDL-REQUIRED: Run via pt-online-schema-change or gh-ost.
-- See ONLINE-DDL-MIGRATION-POLICY-WAVE-04.md for exact command.
-- DO NOT run this file directly via system/scripts/migrate.php against a production large table.

ALTER TABLE audit_logs
    ADD COLUMN archived_at DATETIME NULL DEFAULT NULL COMMENT 'Set when row is archived - NULL means live row' AFTER created_at,
    ADD COLUMN archival_batch_id VARCHAR(36) NULL DEFAULT NULL COMMENT 'UUID of the archival batch that processed this row' AFTER archived_at,
    ADD INDEX idx_audit_logs_archival (archived_at, id);

-- Archival destination table — identical schema, separate partition for retention queries.
CREATE TABLE IF NOT EXISTS audit_logs_archive LIKE audit_logs;

-- Remove the archival index from the archive table (not needed for lookups there)
-- and add a primary archive lookup by original id + batch.
ALTER TABLE audit_logs_archive
    ADD INDEX idx_archive_batch (archival_batch_id),
    ADD INDEX idx_archive_org_created (organization_id, created_at);
