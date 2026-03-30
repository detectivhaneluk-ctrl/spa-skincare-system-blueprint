-- INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01
-- Non-DDL contract change: document the global sequence hotspot for operators and future scoped migration.
-- Safe: COMMENT only; no data or constraint changes.

ALTER TABLE invoice_number_sequences
    COMMENT = 'Global invoice counter: single row sequence_key=invoice (FOR UPDATE hotspot). Target: per-organization scoped sequences — see system/docs/INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01.md';
