-- WAVE-4B: Client Details contact/address foundation (split phones, home/delivery address, same-as-home).

ALTER TABLE clients
    ADD COLUMN phone_home VARCHAR(50) NULL DEFAULT NULL AFTER phone,
    ADD COLUMN phone_mobile VARCHAR(50) NULL DEFAULT NULL AFTER phone_home,
    ADD COLUMN mobile_operator VARCHAR(100) NULL DEFAULT NULL AFTER phone_mobile,
    ADD COLUMN phone_work VARCHAR(50) NULL DEFAULT NULL AFTER mobile_operator,
    ADD COLUMN phone_work_ext VARCHAR(30) NULL DEFAULT NULL AFTER phone_work,
    ADD COLUMN home_address_1 VARCHAR(255) NULL DEFAULT NULL AFTER marketing_opt_in,
    ADD COLUMN home_address_2 VARCHAR(255) NULL DEFAULT NULL AFTER home_address_1,
    ADD COLUMN home_city VARCHAR(120) NULL DEFAULT NULL AFTER home_address_2,
    ADD COLUMN home_postal_code VARCHAR(32) NULL DEFAULT NULL AFTER home_city,
    ADD COLUMN home_country VARCHAR(100) NULL DEFAULT NULL AFTER home_postal_code,
    ADD COLUMN delivery_same_as_home TINYINT(1) NOT NULL DEFAULT 0 AFTER home_country,
    ADD COLUMN delivery_address_1 VARCHAR(255) NULL DEFAULT NULL AFTER delivery_same_as_home,
    ADD COLUMN delivery_address_2 VARCHAR(255) NULL DEFAULT NULL AFTER delivery_address_1,
    ADD COLUMN delivery_city VARCHAR(120) NULL DEFAULT NULL AFTER delivery_address_2,
    ADD COLUMN delivery_postal_code VARCHAR(32) NULL DEFAULT NULL AFTER delivery_city,
    ADD COLUMN delivery_country VARCHAR(100) NULL DEFAULT NULL AFTER delivery_postal_code;
