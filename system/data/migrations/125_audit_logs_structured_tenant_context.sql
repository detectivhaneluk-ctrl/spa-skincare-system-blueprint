-- FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03
-- Structured audit context: request id, tenant (organization), outcome, and category for ops/SIEM queries.
-- Backfill organization_id from branches; FK is nullable — no row delete.

ALTER TABLE audit_logs
    ADD COLUMN outcome VARCHAR(24) NULL DEFAULT NULL COMMENT 'success|failure|denied|unknown' AFTER action,
    ADD COLUMN action_category VARCHAR(64) NULL DEFAULT NULL COMMENT 'auth|booking|payments|documents_intake|platform_control|...' AFTER outcome,
    ADD COLUMN organization_id BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Tenant scope' AFTER branch_id,
    ADD COLUMN request_id VARCHAR(64) NULL DEFAULT NULL COMMENT 'RequestCorrelation id' AFTER metadata_json;

UPDATE audit_logs al
INNER JOIN branches b ON al.branch_id = b.id
SET al.organization_id = b.organization_id
WHERE al.organization_id IS NULL AND b.organization_id IS NOT NULL;

ALTER TABLE audit_logs
    ADD CONSTRAINT fk_audit_logs_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    ADD INDEX idx_audit_logs_org_created (organization_id, created_at),
    ADD INDEX idx_audit_logs_request (request_id),
    ADD INDEX idx_audit_logs_outcome_action (outcome, action);
