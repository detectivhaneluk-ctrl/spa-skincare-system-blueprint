CREATE TABLE service_staff_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    staff_group_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_group_id) REFERENCES staff_groups(id) ON DELETE CASCADE,
    UNIQUE KEY uq_service_staff_groups_pair (service_id, staff_group_id),
    INDEX idx_service_staff_groups_service (service_id),
    INDEX idx_service_staff_groups_staff_group (staff_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
