-- FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02: server-side session invalidation (logout-all / revoke)
-- without scanning Redis keys. Incrementing session_version invalidates all sessions whose stored epoch is lower.

ALTER TABLE users
    ADD COLUMN session_version BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Incremented to revoke all sessions for this user (distributed-safe with any session.save_handler)' AFTER control_plane_totp_enabled;
