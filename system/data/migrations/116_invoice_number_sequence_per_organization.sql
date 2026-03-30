-- INVOICE-SEQUENCE-PHASE-2-REAL-SWITCH-02: per-organization invoice sequence rows.
-- Legacy row from 043 becomes (organization_id=0, sequence_key='invoice') — retained for history only; allocator uses org > 0.

ALTER TABLE invoice_number_sequences
    ADD COLUMN organization_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST;

ALTER TABLE invoice_number_sequences
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (organization_id, sequence_key);

ALTER TABLE invoice_number_sequences
    COMMENT = 'Invoice counters: PK (organization_id, sequence_key). Row (0,invoice) is legacy global depot from 043 — unused by new allocation (see InvoiceRepository::allocateNextInvoiceNumber).';
