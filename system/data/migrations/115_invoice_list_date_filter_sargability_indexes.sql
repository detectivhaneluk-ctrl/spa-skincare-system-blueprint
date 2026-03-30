-- INVOICE-LIST-DATE-FILTER-SARGABILITY-HARDENING-01
-- Supporting composites for the OR-split list/count predicates on issued_at vs created_at.
-- Pairs with existing idx_invoices_*_deleted_created from migration 114.

ALTER TABLE invoices
    ADD INDEX idx_invoices_branch_deleted_issued_at (branch_id, deleted_at, issued_at),
    ADD INDEX idx_invoices_client_deleted_issued_at (client_id, deleted_at, issued_at);
