-- PUBLIC-COMMERCE-QUEUE-INDEX-HARDENING-01
-- Staff verification queue: {@see \Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository::listAwaitingVerificationWithInvoices}
-- Hot pattern (branch scope):
--   WHERE p.status = 'awaiting_verification' AND p.branch_id = ? AND i joins … AND i.status <> 'cancelled'
--   ORDER BY COALESCE(p.finalize_last_received_at, p.updated_at) DESC, p.id DESC LIMIT n
-- Org scope: same status + EXISTS(branches…org) instead of branch_id = ?; ORDER BY unchanged.
-- Prior gap: only idx_public_commerce_branch(branch_id) — no status or time sort in index → large branch scans + filesort.
-- Fix: STORED generated column equals the COALESCE sort key (portable vs functional indexes across MySQL/MariaDB),
--      plus composites (branch_id, status, sort, id) and (status, sort, id).

ALTER TABLE public_commerce_purchases
    ADD COLUMN verification_queue_sort_at TIMESTAMP NOT NULL
        GENERATED ALWAYS AS (COALESCE(finalize_last_received_at, updated_at)) STORED
        COMMENT 'Queue sort key for awaiting_verification staff list (PUBLIC-COMMERCE-QUEUE-INDEX-HARDENING-01)'
        AFTER finalize_last_received_at,
    ADD INDEX idx_pc_verification_queue_branch_status (branch_id, status, verification_queue_sort_at DESC, id DESC),
    ADD INDEX idx_pc_verification_queue_status_sort (status, verification_queue_sort_at DESC, id DESC);
