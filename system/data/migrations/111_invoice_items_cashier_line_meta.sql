-- CASHIER-LINE-CONTRACT-AND-DEFERRED-SALES-TYPES-FOUNDATION-01
-- Extends invoice line storage for typed cashier semantics (JSON sidecar + wider item_type).

ALTER TABLE invoice_items
    MODIFY COLUMN item_type VARCHAR(32) NOT NULL,
    ADD COLUMN line_meta JSON NULL AFTER tax_rate;
