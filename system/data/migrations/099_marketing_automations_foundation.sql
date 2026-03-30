-- Marketing automated emails foundation (definitions per branch + validated config payload).
-- No execution engine in this migration: storage only.

CREATE TABLE marketing_automations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    automation_key VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketing_automation_branch_key (branch_id, automation_key),
    KEY idx_marketing_automation_branch (branch_id),
    KEY idx_marketing_automation_key (automation_key),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
