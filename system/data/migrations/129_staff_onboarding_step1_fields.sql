-- Migration 129: Staff onboarding wizard Step 1 schema expansion
-- Adds all fields required for the Step 1 employee onboarding form.
-- Existing columns (phone, email, job_title, is_active, user_id) are preserved for backward compatibility.

-- last_name becomes optional: the new wizard only requires first_name, gender, email, staff_type, status
ALTER TABLE staff MODIFY COLUMN last_name VARCHAR(100) NULL DEFAULT NULL;

-- Add all Step 1 new columns to the staff table
ALTER TABLE staff
    ADD COLUMN display_name             VARCHAR(200)            NULL            AFTER last_name,
    ADD COLUMN gender                   ENUM('male','female')   NULL            AFTER display_name,
    ADD COLUMN staff_type               ENUM('freelancer','scheduled') NULL     AFTER gender,
    ADD COLUMN onboarding_step          TINYINT UNSIGNED        NULL            AFTER staff_type,
    ADD COLUMN employment_end_date      DATE                    NULL            AFTER onboarding_step,
    ADD COLUMN create_login_requested   TINYINT(1)  NOT NULL    DEFAULT 0       AFTER employment_end_date,
    ADD COLUMN max_appointments_per_day INT UNSIGNED            NULL            AFTER create_login_requested,
    ADD COLUMN photo_media_asset_id     BIGINT UNSIGNED         NULL            AFTER max_appointments_per_day,
    ADD COLUMN signature_media_asset_id BIGINT UNSIGNED         NULL            AFTER photo_media_asset_id,
    ADD COLUMN profile_description      TEXT                    NULL            AFTER signature_media_asset_id,
    ADD COLUMN employee_notes           TEXT                    NULL            AFTER profile_description,
    ADD COLUMN license_number           VARCHAR(100)            NULL            AFTER employee_notes,
    ADD COLUMN license_expiration_date  DATE                    NULL            AFTER license_number,
    ADD COLUMN service_type_id          INT UNSIGNED            NULL            AFTER license_expiration_date,
    ADD COLUMN street_1                 VARCHAR(200)            NULL            AFTER service_type_id,
    ADD COLUMN street_2                 VARCHAR(200)            NULL            AFTER street_1,
    ADD COLUMN city                     VARCHAR(100)            NULL            AFTER street_2,
    ADD COLUMN postal_code              VARCHAR(20)             NULL            AFTER city,
    ADD COLUMN country                  VARCHAR(100)            NULL            AFTER postal_code,
    ADD COLUMN home_phone               VARCHAR(50)             NULL            AFTER country,
    ADD COLUMN mobile_phone             VARCHAR(50)             NULL            AFTER home_phone,
    ADD COLUMN preferred_phone          ENUM('home','mobile')   NULL            AFTER mobile_phone,
    ADD COLUMN sms_opt_in               TINYINT(1)  NOT NULL    DEFAULT 0       AFTER preferred_phone;

-- Loose FK indexes for media asset references (no hard FK to avoid dependency on media pipeline being deployed)
ALTER TABLE staff
    ADD INDEX idx_staff_photo_asset (photo_media_asset_id),
    ADD INDEX idx_staff_sig_asset (signature_media_asset_id),
    ADD INDEX idx_staff_onboarding_step (onboarding_step);

-- Lookup table for Service Type select on the onboarding wizard
-- Intentionally scoped to global (no branch_id): service types are an org-level catalogue.
-- Populated by admin in a future management UI; empty table renders an empty select (honest).
CREATE TABLE staff_service_types (
    id         INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)      NOT NULL,
    sort_order TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    is_active  TINYINT(1)        NOT NULL DEFAULT 1,
    created_at TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sst_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
