-- FOUNDATION-81 — SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01 wave 1: nullable admin-managed long description.
-- Additive only; no backfill; existing rows remain valid with description NULL.

ALTER TABLE services
    ADD COLUMN description TEXT NULL AFTER name;
