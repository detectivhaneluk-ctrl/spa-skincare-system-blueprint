-- Users: self-referential FKs for audit fields
ALTER TABLE users
    ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL;

-- Audit: FKs for actor and branch (after 011 schema)
ALTER TABLE audit_logs
    ADD CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

-- Branches: unique code (allows NULL)
ALTER TABLE branches ADD UNIQUE KEY uk_branches_code (code);
