ALTER TABLE audit_logs
    ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN target_type VARCHAR(100) NULL AFTER action,
    ADD COLUMN target_id BIGINT UNSIGNED NULL AFTER target_type,
    ADD COLUMN metadata_json JSON NULL AFTER user_agent;

UPDATE audit_logs SET
    actor_user_id = user_id,
    target_type = COALESCE(entity_type, 'unknown'),
    target_id = entity_id,
    metadata_json = CASE
        WHEN old_values IS NOT NULL OR new_values IS NOT NULL
        THEN JSON_OBJECT('old', old_values, 'new', new_values)
        ELSE NULL
    END;

ALTER TABLE audit_logs
    DROP COLUMN entity_type,
    DROP COLUMN entity_id,
    DROP COLUMN user_id,
    DROP COLUMN old_values,
    DROP COLUMN new_values,
    MODIFY COLUMN target_type VARCHAR(100) NOT NULL DEFAULT 'unknown',
    ADD INDEX idx_actor_created (actor_user_id, created_at),
    ADD INDEX idx_target (target_type, target_id),
    ADD INDEX idx_branch_created (branch_id, created_at);
