-- CLIENT-MERGE-ASYNC-JOB-HARDENING-01: durable async merge queue (purpose-built).

CREATE TABLE IF NOT EXISTS client_merge_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    primary_client_id INT UNSIGNED NOT NULL,
    secondary_client_id INT UNSIGNED NOT NULL,
    requested_by_user_id INT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'queued',
    current_step VARCHAR(64) NULL,
    error_code VARCHAR(64) NULL,
    error_message_public VARCHAR(512) NULL,
    error_detail_internal TEXT NULL,
    merge_notes TEXT NULL,
    result_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_client_merge_jobs_org_status (organization_id, status, id),
    KEY idx_client_merge_jobs_pair_active (organization_id, primary_client_id, secondary_client_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
