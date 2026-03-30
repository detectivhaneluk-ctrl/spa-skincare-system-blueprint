-- Phase 2A integrity: unique codes per branch, soft-delete indexes

-- Rooms: unique(code, branch_id) for branch-scoped code uniqueness
-- When code is NULL, multiple rows allowed (MySQL treats NULLs as distinct in UNIQUE)
ALTER TABLE rooms
    ADD UNIQUE KEY uk_rooms_code_branch (code, branch_id),
    ADD INDEX idx_rooms_deleted (deleted_at);

-- Equipment: unique(code, branch_id)
ALTER TABLE equipment
    ADD UNIQUE KEY uk_equipment_code_branch (code, branch_id),
    ADD INDEX idx_equipment_deleted (deleted_at);

-- Staff: index on deleted_at for soft-delete queries
ALTER TABLE staff
    ADD INDEX idx_staff_deleted (deleted_at);

-- Service categories and services: deleted_at for soft-delete queries
ALTER TABLE service_categories
    ADD INDEX idx_service_categories_deleted (deleted_at);

ALTER TABLE services
    ADD INDEX idx_services_deleted (deleted_at);
