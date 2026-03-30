-- Authoritative last password change time for security.password_expiration enforcement (90_days vs never).
ALTER TABLE users
    ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER password_hash;

UPDATE users SET password_changed_at = created_at WHERE password_changed_at IS NULL;
