-- H-001: durable actionable state when public-commerce reconcile fails after financial commits (sales hooks).
ALTER TABLE public_commerce_purchases
    ADD COLUMN fulfillment_reconcile_recovery_at TIMESTAMP NULL DEFAULT NULL AFTER fulfillment_reversed_at,
    ADD COLUMN fulfillment_reconcile_recovery_trigger VARCHAR(64) NULL DEFAULT NULL AFTER fulfillment_reconcile_recovery_at,
    ADD COLUMN fulfillment_reconcile_recovery_error TEXT NULL AFTER fulfillment_reconcile_recovery_trigger,
    ADD INDEX idx_pc_purchase_fulfillment_recovery (fulfillment_reconcile_recovery_at);
