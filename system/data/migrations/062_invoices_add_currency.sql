-- Canonical invoice currency (branch-effective establishment currency at create time; see InvoiceService + SettingsService::getEffectiveCurrencyCode).
ALTER TABLE invoices
    ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'USD' AFTER branch_id;
