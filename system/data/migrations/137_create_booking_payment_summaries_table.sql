-- Booking payment summaries: durable payment decision record per wizard booking commit.
-- Records the chosen payment mode, explicit skip-payment decision, totals snapshot,
-- and hold_reservation flag. This is NOT a gateway charge record — it is the
-- domain-truthful persistence of the payment decision made at booking time.
--
-- tax_basis = 'zero_tax_v1': explicit contract that zero tax is intentional (no hidden
-- assumption), not an oversight. Replace with a real tax engine in a future phase.
--
-- booking_chain_id: FK to booking_chains (nullable for future non-chain wizard paths).
-- primary_appointment_id: the first appointment created in the chain (non-FK to avoid
--   cascade complexity; use for display/lookup only).

CREATE TABLE booking_payment_summaries (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_chain_id       BIGINT UNSIGNED NULL,
    primary_appointment_id BIGINT UNSIGNED NOT NULL,
    branch_id              INT UNSIGNED NOT NULL,
    payment_mode           VARCHAR(64) NOT NULL,
    skip_reason            TEXT NULL,
    hold_reservation       TINYINT(1) NOT NULL DEFAULT 0,
    subtotal               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_amount             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency               CHAR(3) NOT NULL DEFAULT 'GBP',
    line_count             TINYINT UNSIGNED NOT NULL DEFAULT 1,
    tax_basis              VARCHAR(64) NOT NULL DEFAULT 'zero_tax_v1',
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_bps_booking_chain (booking_chain_id),
    INDEX idx_bps_primary_appt  (primary_appointment_id),
    INDEX idx_bps_branch        (branch_id),
    CONSTRAINT fk_bps_booking_chain
        FOREIGN KEY (booking_chain_id) REFERENCES booking_chains(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
