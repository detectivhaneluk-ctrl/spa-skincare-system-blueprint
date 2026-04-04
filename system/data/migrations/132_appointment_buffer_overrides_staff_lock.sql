-- Per-appointment buffer overrides (NULL = inherit from service). Used for staff availability / calendar display.
-- staff_assignment_locked: when 1, staff_id must not change until unlocked (reschedule/update guards).

ALTER TABLE appointments
    ADD COLUMN buffer_before_override_minutes INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Unset uses service buffer_before, 0+ forces prep minutes'
        AFTER checked_in_by,
    ADD COLUMN buffer_after_override_minutes INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Unset uses service buffer_after, 0+ forces turnover minutes'
        AFTER buffer_before_override_minutes,
    ADD COLUMN staff_assignment_locked TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'When 1, staff_id cannot change until unlocked'
        AFTER buffer_after_override_minutes;
