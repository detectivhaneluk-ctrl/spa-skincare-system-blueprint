-- C-006: explicit timestamp when public-commerce fulfillment was reversed after refund (audit + idempotency).
ALTER TABLE public_commerce_purchases
    ADD COLUMN fulfillment_reversed_at TIMESTAMP NULL DEFAULT NULL AFTER fulfillment_applied_at;
