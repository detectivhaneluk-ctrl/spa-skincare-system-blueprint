ALTER TABLE payment_methods
    ADD COLUMN type_label VARCHAR(50) NULL AFTER name;
