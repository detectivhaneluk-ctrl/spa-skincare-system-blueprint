-- FOUNDATION-PLATFORM-INVARIANTS-AND-FOUNDER-RISK-ENGINE-01: TOTP secret storage for control-plane (founder) MFA.
-- Apply via normal migration runner. Columns are nullable / default off until CLI enrollment.

ALTER TABLE users
    ADD COLUMN control_plane_totp_secret_ciphertext VARBINARY(512) NULL DEFAULT NULL
        COMMENT 'Encrypted base32 TOTP shared secret for platform control-plane MFA' AFTER password_changed_at,
    ADD COLUMN control_plane_totp_enabled TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 when MFA is required for enrolled users on HIGH/CRITICAL founder actions' AFTER control_plane_totp_secret_ciphertext;
