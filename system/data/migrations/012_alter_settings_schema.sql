ALTER TABLE settings
    ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'string' AFTER `value`,
    ADD COLUMN `group` VARCHAR(50) NULL AFTER type,
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER `group`,
    ADD INDEX idx_settings_group (`group`),
    ADD INDEX idx_settings_branch (branch_id),
    ADD CONSTRAINT fk_settings_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;
