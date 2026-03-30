-- Add options field for marketing special offers UI filters.

ALTER TABLE marketing_special_offers
    ADD COLUMN offer_option VARCHAR(60) NOT NULL DEFAULT 'all' AFTER adjustment_value,
    ADD INDEX idx_mkt_special_offers_option (offer_option);

