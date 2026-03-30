-- Prevent duplicate series occurrence materialization for the same scheduled start (idempotent generation).
ALTER TABLE appointments
    ADD UNIQUE KEY uq_appointments_series_start (series_id, start_at);
