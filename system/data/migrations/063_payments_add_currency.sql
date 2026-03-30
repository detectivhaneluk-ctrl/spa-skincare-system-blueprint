-- Canonical payment currency: matches invoice currency at insert time (see PaymentService / InvoiceService).
ALTER TABLE payments
    ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'USD' AFTER amount;
