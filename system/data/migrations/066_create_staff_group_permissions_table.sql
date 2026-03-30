CREATE TABLE staff_group_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_group_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (staff_group_id) REFERENCES staff_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_staff_group_permissions_pair (staff_group_id, permission_id),
    INDEX idx_staff_group_permissions_group (staff_group_id),
    INDEX idx_staff_group_permissions_permission (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
