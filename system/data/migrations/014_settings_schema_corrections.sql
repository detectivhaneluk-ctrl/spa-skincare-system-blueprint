-- 1. Rename group to setting_group
ALTER TABLE settings CHANGE COLUMN `group` setting_group VARCHAR(50) NULL;

-- 2. Branch-aware uniqueness
ALTER TABLE settings DROP FOREIGN KEY fk_settings_branch;
ALTER TABLE settings DROP PRIMARY KEY, ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;
ALTER TABLE settings MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0;
UPDATE settings SET branch_id = 0 WHERE branch_id IS NULL;
ALTER TABLE settings ADD UNIQUE KEY uk_settings_key_branch (`key`, branch_id);
