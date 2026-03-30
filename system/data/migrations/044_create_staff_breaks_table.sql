-- Recurring weekly breaks per staff (e.g. lunch). day_of_week: 0=Sunday, 1=Monday, ... 6=Saturday.
CREATE TABLE staff_breaks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    title VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    INDEX idx_staff_breaks_staff_dow (staff_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
