-- MEMBERSHIP-DEFINITION-BILLING-ENABLED-RUNTIME-FIX-01
-- Canonical billing columns on membership_definitions (same semantics as the first ALTER in
-- 067_membership_subscription_billing_foundation.sql). One statement per column so real DBs that
-- are only partially migrated get the missing pieces; duplicate column errors are tolerated by
-- scripts/migrate.php default (non-strict) mode. Already-aligned DBs: no-op aside from stamped row.

ALTER TABLE membership_definitions
    ADD COLUMN IF NOT EXISTS billing_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER price;

ALTER TABLE membership_definitions
    ADD COLUMN IF NOT EXISTS billing_interval_unit ENUM('day','week','month','year') NULL AFTER billing_enabled;

ALTER TABLE membership_definitions
    ADD COLUMN IF NOT EXISTS billing_interval_count INT UNSIGNED NULL AFTER billing_interval_unit;

ALTER TABLE membership_definitions
    ADD COLUMN IF NOT EXISTS renewal_price DECIMAL(12,2) NULL AFTER billing_interval_count;

ALTER TABLE membership_definitions
    ADD COLUMN IF NOT EXISTS renewal_invoice_due_days INT UNSIGNED NOT NULL DEFAULT 14 AFTER renewal_price;

ALTER TABLE membership_definitions
    ADD COLUMN IF NOT EXISTS billing_auto_renew_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER renewal_invoice_due_days;
