-- Offer state + expiry for waitlist.auto_offer_enabled / waitlist.default_expiry_minutes runtime enforcement.
ALTER TABLE appointment_waitlist
    MODIFY COLUMN status ENUM('waiting','offered','matched','booked','cancelled') NOT NULL DEFAULT 'waiting';

ALTER TABLE appointment_waitlist
    ADD COLUMN offer_started_at TIMESTAMP NULL DEFAULT NULL AFTER notes,
    ADD COLUMN offer_expires_at TIMESTAMP NULL DEFAULT NULL AFTER offer_started_at;
