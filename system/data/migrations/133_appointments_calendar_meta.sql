-- Calendar display metadata (whitelist-merged in PHP). Not billing truth.
-- See Modules\Appointments\Services\CalendarBadgeRegistry.

ALTER TABLE appointments
    ADD COLUMN appointment_calendar_meta JSON NULL AFTER notes;
