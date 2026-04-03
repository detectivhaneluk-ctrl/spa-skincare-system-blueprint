-- Migration 131: add optional lunch break columns to staff_schedules
-- These are nullable — off-days have no row, working days may or may not have lunch set.
ALTER TABLE staff_schedules
    ADD COLUMN lunch_start_time TIME NULL AFTER end_time,
    ADD COLUMN lunch_end_time   TIME NULL AFTER lunch_start_time;
