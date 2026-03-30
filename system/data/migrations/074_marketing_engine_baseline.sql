-- Marketing engine baseline: campaigns, runs, recipient snapshots; email channel only (SMS deferred in outbound layer).
-- Outbound delivery uses existing outbound_notification_messages + worker script.

INSERT IGNORE INTO permissions (code, name) VALUES
('marketing.view', 'View marketing campaigns and run history'),
('marketing.manage', 'Create and run marketing campaigns');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r INNER JOIN permissions p ON p.code IN ('marketing.view', 'marketing.manage')
WHERE r.code = 'owner';

CREATE TABLE marketing_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(200) NOT NULL,
    channel ENUM('email') NOT NULL DEFAULT 'email',
    segment_key VARCHAR(64) NOT NULL,
    segment_config_json JSON NULL,
    subject VARCHAR(500) NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    status ENUM('draft','archived') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_marketing_campaigns_branch (branch_id),
    INDEX idx_marketing_campaigns_status (status),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketing_campaign_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    status ENUM('frozen','dispatching','completed','cancelled') NOT NULL DEFAULT 'frozen',
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    snapshot_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NULL,
    INDEX idx_marketing_runs_campaign (campaign_id),
    INDEX idx_marketing_runs_status (status),
    FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketing_campaign_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_run_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('email') NOT NULL DEFAULT 'email',
    email_snapshot VARCHAR(255) NOT NULL,
    first_name_snapshot VARCHAR(100) NOT NULL DEFAULT '',
    last_name_snapshot VARCHAR(100) NOT NULL DEFAULT '',
    delivery_status ENUM('pending','enqueued','skipped','cancelled') NOT NULL DEFAULT 'pending',
    skip_reason VARCHAR(500) NULL,
    outbound_message_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mkt_recipient_run_client_channel (campaign_run_id, client_id, channel),
    INDEX idx_mkt_recipients_run (campaign_run_id),
    INDEX idx_mkt_recipients_outbound (outbound_message_id),
    FOREIGN KEY (campaign_run_id) REFERENCES marketing_campaign_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (outbound_message_id) REFERENCES outbound_notification_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
