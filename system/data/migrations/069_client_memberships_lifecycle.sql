-- MEMBERSHIP-LIFECYCLE-STATE-MACHINE-ENFORCEMENT-01
-- Authoritative lifecycle: pause, scheduled cancel at period end, cancelled_at; billing skips paused + cancel_at_period_end renewals.

ALTER TABLE client_memberships
    MODIFY COLUMN status ENUM('active','expired','cancelled','paused') NOT NULL DEFAULT 'active',
    ADD COLUMN cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN cancelled_at TIMESTAMP NULL AFTER cancel_at_period_end,
    ADD COLUMN paused_at TIMESTAMP NULL AFTER cancelled_at,
    ADD COLUMN lifecycle_reason VARCHAR(500) NULL AFTER paused_at,
    ADD INDEX idx_client_memberships_cancel_scheduled (cancel_at_period_end, status, ends_at);
