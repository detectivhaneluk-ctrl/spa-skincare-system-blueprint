-- SCALABILITY-HOT-TABLE-QUERY-AND-INDEX-AUDIT-01 (wave 01)
-- Additive indexes only: aligns hot list/join/sort predicates with covering composites.
-- See: system/docs/SCALABILITY-HOT-TABLE-QUERY-AND-INDEX-AUDIT-01.md

ALTER TABLE invoices
    ADD INDEX idx_invoices_branch_deleted_created (branch_id, deleted_at, created_at),
    ADD INDEX idx_invoices_client_deleted_created (client_id, deleted_at, created_at);

ALTER TABLE payments
    ADD INDEX idx_payments_invoice_created (invoice_id, created_at),
    ADD INDEX idx_payments_register_session_method_status (register_session_id, payment_method, status),
    ADD INDEX idx_payments_parent_entry_status (parent_payment_id, entry_type, status);

ALTER TABLE invoice_items
    ADD INDEX idx_invoice_items_invoice_sort (invoice_id, sort_order, id);

ALTER TABLE appointments
    ADD INDEX idx_appointments_branch_deleted_start (branch_id, deleted_at, start_at),
    ADD INDEX idx_appointments_staff_deleted_start (staff_id, deleted_at, start_at);

ALTER TABLE clients
    ADD INDEX idx_clients_branch_deleted_name (branch_id, deleted_at, last_name, first_name);
