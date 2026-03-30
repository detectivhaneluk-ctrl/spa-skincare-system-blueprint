CREATE TABLE appointment_series (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    recurrence_type ENUM('weekly', 'biweekly') NOT NULL,
    interval_weeks TINYINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    occurrences_count INT UNSIGNED NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
    INDEX idx_appointment_series_branch_status (branch_id, status),
    INDEX idx_appointment_series_staff_start (staff_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
    ADD COLUMN series_id BIGINT UNSIGNED NULL AFTER branch_id,
    ADD CONSTRAINT fk_appointments_series
        FOREIGN KEY (series_id) REFERENCES appointment_series(id) ON DELETE SET NULL,
    ADD INDEX idx_appointments_series (series_id);
