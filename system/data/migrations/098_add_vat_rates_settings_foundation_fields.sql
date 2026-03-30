ALTER TABLE vat_rates
    ADD COLUMN is_flexible TINYINT(1) NOT NULL DEFAULT 0 AFTER rate_percent,
    ADD COLUMN price_includes_tax TINYINT(1) NOT NULL DEFAULT 0 AFTER is_flexible,
    ADD COLUMN applies_to_json JSON NULL AFTER price_includes_tax;
