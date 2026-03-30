-- Appointment check-in foundation (internal operator): arrival timestamp + recording user.
-- Does not change status workflow; orthogonal to scheduled/confirmed/in_progress.

ALTER TABLE appointments
    ADD COLUMN checked_in_at DATETIME NULL AFTER no_show_reason_id,
    ADD COLUMN checked_in_by BIGINT UNSIGNED NULL AFTER checked_in_at,
    ADD INDEX idx_appointments_checked_in_at (checked_in_at),
    ADD CONSTRAINT fk_appointments_checked_in_by FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL;
