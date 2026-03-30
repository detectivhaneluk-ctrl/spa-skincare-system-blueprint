CREATE TABLE invoice_number_sequences (
    sequence_key VARCHAR(50) PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO invoice_number_sequences (sequence_key, next_number)
SELECT
    'invoice',
    COALESCE(MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)), 0) + 1
FROM invoices
WHERE invoice_number REGEXP '^INV-[0-9]+$'
ON DUPLICATE KEY UPDATE next_number = next_number;

ALTER TABLE payments
    ADD COLUMN entry_type ENUM('payment','refund') NOT NULL DEFAULT 'payment' AFTER register_session_id,
    ADD COLUMN parent_payment_id BIGINT UNSIGNED NULL AFTER entry_type,
    ADD INDEX idx_payments_entry_type (entry_type),
    ADD INDEX idx_payments_parent_payment (parent_payment_id),
    ADD CONSTRAINT fk_payments_parent_payment
        FOREIGN KEY (parent_payment_id) REFERENCES payments(id) ON DELETE SET NULL;
