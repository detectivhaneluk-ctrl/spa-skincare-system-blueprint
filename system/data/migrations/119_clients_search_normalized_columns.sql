-- CLIENT-NORMALIZED-SEARCH-COLUMNS-AND-REPOSITORY-SWITCH-01
-- Plain stored columns + indexes for client list / duplicate / lock paths (no generated columns).
-- Requires MySQL 8.0+ for REGEXP_REPLACE in backfill.
-- phone_digits mirrors legacy clients.phone; lock-by-phone paths remain scoped to that column only.

ALTER TABLE clients
    ADD COLUMN email_lc VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN phone_digits VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN phone_home_digits VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN phone_mobile_digits VARCHAR(32) NULL DEFAULT NULL,
    ADD COLUMN phone_work_digits VARCHAR(32) NULL DEFAULT NULL;

UPDATE clients SET
    email_lc = IF(email IS NULL OR TRIM(email) = '', NULL, LOWER(TRIM(email))),
    phone_digits = IF(
        phone IS NULL OR TRIM(phone) = '',
        NULL,
        IF(
            LENGTH(REGEXP_REPLACE(TRIM(phone), '[^0-9]', '')) BETWEEN 7 AND 20,
            REGEXP_REPLACE(TRIM(phone), '[^0-9]', ''),
            NULL
        )
    ),
    phone_home_digits = IF(
        phone_home IS NULL OR TRIM(phone_home) = '',
        NULL,
        IF(
            LENGTH(REGEXP_REPLACE(TRIM(phone_home), '[^0-9]', '')) BETWEEN 7 AND 20,
            REGEXP_REPLACE(TRIM(phone_home), '[^0-9]', ''),
            NULL
        )
    ),
    phone_mobile_digits = IF(
        phone_mobile IS NULL OR TRIM(phone_mobile) = '',
        NULL,
        IF(
            LENGTH(REGEXP_REPLACE(TRIM(phone_mobile), '[^0-9]', '')) BETWEEN 7 AND 20,
            REGEXP_REPLACE(TRIM(phone_mobile), '[^0-9]', ''),
            NULL
        )
    ),
    phone_work_digits = IF(
        phone_work IS NULL OR TRIM(phone_work) = '',
        NULL,
        IF(
            LENGTH(REGEXP_REPLACE(TRIM(phone_work), '[^0-9]', '')) BETWEEN 7 AND 20,
            REGEXP_REPLACE(TRIM(phone_work), '[^0-9]', ''),
            NULL
        )
    );

ALTER TABLE clients
    ADD INDEX idx_clients_email_lc (email_lc),
    ADD INDEX idx_clients_phone_digits (phone_digits),
    ADD INDEX idx_clients_phone_home_digits (phone_home_digits),
    ADD INDEX idx_clients_phone_mobile_digits (phone_mobile_digits),
    ADD INDEX idx_clients_phone_work_digits (phone_work_digits);
