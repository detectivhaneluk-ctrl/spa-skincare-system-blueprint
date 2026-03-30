-- PUBLIC-COMMERCE-PAYMENT-TRUST-HARDENING-01: purchase lifecycle states + finalize idempotency columns.
-- Replaces client-trusted payment recording on anonymous finalize with awaiting_verification + staff/PSP-paid invoice truth.

ALTER TABLE public_commerce_purchases
    MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'initiated';

UPDATE public_commerce_purchases SET status = 'initiated' WHERE status = 'pending_payment';
UPDATE public_commerce_purchases SET status = 'paid' WHERE status = 'fulfilled';

ALTER TABLE public_commerce_purchases
    ADD COLUMN finalize_attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER updated_at,
    ADD COLUMN finalize_last_request_hash CHAR(64) NULL DEFAULT NULL AFTER finalize_attempt_count,
    ADD COLUMN finalize_last_received_at TIMESTAMP NULL DEFAULT NULL AFTER finalize_last_request_hash;
