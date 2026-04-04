-- Link appointments to a booking_chain record.
-- booking_chain_id: FK to booking_chains(id), SET NULL on delete so chain removal
--   does not cascade-delete individual appointments.
-- booking_chain_order: 0-based position within the chain (first service = 0).
--   Nullable for appointments not created via the wizard chain flow.

ALTER TABLE appointments
    ADD COLUMN booking_chain_id    BIGINT UNSIGNED NULL AFTER series_id,
    ADD COLUMN booking_chain_order TINYINT UNSIGNED NULL AFTER booking_chain_id,
    ADD CONSTRAINT fk_appointments_booking_chain
        FOREIGN KEY (booking_chain_id) REFERENCES booking_chains(id) ON DELETE SET NULL,
    ADD INDEX idx_appointments_booking_chain (booking_chain_id);
