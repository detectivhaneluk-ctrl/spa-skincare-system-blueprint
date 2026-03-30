-- BKM-006: Date-specific staff availability (PTO, partial off, one-off working hours).
-- Precedence (in AvailabilityService): closed > open override segments (+ subtract unavailable) > weekly staff_schedules; then staff_breaks, appointment_blocked_slots, appointments.

CREATE TABLE staff_availability_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    exception_date DATE NOT NULL,
    kind VARCHAR(20) NOT NULL COMMENT 'closed=full day off, open=working segment for this date, unavailable=time off within working hours',
    start_time TIME NULL,
    end_time TIME NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    INDEX idx_staff_avail_ex_staff_date (staff_id, exception_date),
    INDEX idx_staff_avail_ex_date (exception_date),
    INDEX idx_staff_avail_ex_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
