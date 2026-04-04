-- Booking chains: canonical group record for a full-page wizard booking session.
-- Every appointment created from one wizard commit shares the same booking_chain_id.
-- Semantically distinct from appointment_series (recurring single-service) — this
-- table groups multi-service linked-chain bookings created in a single wizard flow.

CREATE TABLE booking_chains (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id         INT UNSIGNED NOT NULL,
    booking_mode      ENUM('standalone', 'linked_chain') NOT NULL DEFAULT 'standalone',
    chain_order_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    notes             TEXT NULL,
    created_by        INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_booking_chains_branch (branch_id),
    INDEX idx_booking_chains_mode   (booking_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
