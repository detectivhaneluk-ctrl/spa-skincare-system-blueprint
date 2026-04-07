-- SERVICES-STEP1-FULL-TARGET-CLOSURE-01
-- Adds all Step-1 target fields to the services table.
-- Fields added:
--   service_type          ENUM — canonical service classification (replaces no-column state)
--   sku                   VARCHAR(100) NULL UNIQUE — service SKU/code
--   barcode               VARCHAR(100) NULL — barcode reference
--   processing_time_required TINYINT(1) — service requires processing time behavior
--   add_on                TINYINT(1) — service is bookable as an add-on
--   requires_two_staff_members TINYINT(1) — service requires two concurrent staff
--   applies_to_employee   TINYINT(1) DEFAULT 1 — booking occupies employee slot
--   applies_to_room       TINYINT(1) DEFAULT 1 — booking occupies room slot
--   requires_equipment    TINYINT(1) — service requires equipment (flag only; assignment is Step 2)
--   show_in_online_menu   TINYINT(1) — visible in online/public booking menu
--   staff_fee_mode        ENUM('none','percentage','amount') DEFAULT 'none'
--   staff_fee_value       DECIMAL(10,2) NULL — only used when staff_fee_mode != 'none'
--   allow_on_gift_voucher_sale TINYINT(1) — can be used in gift voucher context
--   billing_code          VARCHAR(50) NULL — external billing/reference code

ALTER TABLE services
    ADD COLUMN service_type ENUM('service','package_item','other') NOT NULL DEFAULT 'service'
        COMMENT 'Canonical service classification.'
        AFTER id,

    ADD COLUMN sku VARCHAR(100) NULL
        COMMENT 'Optional unique service SKU/code.'
        AFTER description,

    ADD COLUMN barcode VARCHAR(100) NULL
        COMMENT 'Optional barcode reference.'
        AFTER sku,

    ADD COLUMN processing_time_required TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Service has a processing phase (e.g. colour development) during which staff can be freed.'
        AFTER buffer_after_minutes,

    ADD COLUMN add_on TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Service can be booked as an add-on to another service.'
        AFTER processing_time_required,

    ADD COLUMN requires_two_staff_members TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Service requires two staff members concurrently.'
        AFTER add_on,

    ADD COLUMN applies_to_employee TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Booking occupies an employee time slot.'
        AFTER requires_two_staff_members,

    ADD COLUMN applies_to_room TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Booking occupies a room slot.'
        AFTER applies_to_employee,

    ADD COLUMN requires_equipment TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Service requires equipment (behaviour flag; actual equipment assigned in Step 2).'
        AFTER applies_to_room,

    ADD COLUMN show_in_online_menu TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Service appears in public/online booking menu.'
        AFTER is_active,

    ADD COLUMN staff_fee_mode ENUM('none','percentage','amount') NOT NULL DEFAULT 'none'
        COMMENT 'Staff fee commission mode for this service.'
        AFTER show_in_online_menu,

    ADD COLUMN staff_fee_value DECIMAL(10,2) NULL
        COMMENT 'Staff fee value — percent or fixed amount per staff_fee_mode. NULL when mode=none.'
        AFTER staff_fee_mode,

    ADD COLUMN allow_on_gift_voucher_sale TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Service can be included in gift voucher sales.'
        AFTER staff_fee_value,

    ADD COLUMN billing_code VARCHAR(50) NULL
        COMMENT 'External billing or reference code.'
        AFTER allow_on_gift_voucher_sale,

    ADD UNIQUE KEY uk_services_sku (sku);
