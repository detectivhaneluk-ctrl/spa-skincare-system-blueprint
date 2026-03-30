-- Outbound transactional notifications: queue + delivery attempts (email first; SMS channel reserved).
-- Idempotency: uk_outbound_idempotency on idempotency_key (v1 format: email:v1:event:...).

CREATE TABLE outbound_notification_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    channel ENUM('email','sms') NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    template_key VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    recipient_type ENUM('client','staff','user','raw') NOT NULL,
    recipient_id BIGINT UNSIGNED NULL,
    recipient_address VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NULL,
    body_text MEDIUMTEXT NOT NULL,
    payload_json JSON NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    skip_reason VARCHAR(500) NULL,
    error_summary VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    UNIQUE KEY uk_outbound_idempotency (idempotency_key),
    KEY idx_outbound_status_created (status, created_at),
    KEY idx_outbound_branch (branch_id),
    KEY idx_outbound_entity (entity_type, entity_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE outbound_notification_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
    transport VARCHAR(64) NOT NULL,
    status ENUM('success','failed') NOT NULL,
    error_text TEXT NULL,
    detail_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES outbound_notification_messages(id) ON DELETE CASCADE,
    KEY idx_outbound_attempts_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
