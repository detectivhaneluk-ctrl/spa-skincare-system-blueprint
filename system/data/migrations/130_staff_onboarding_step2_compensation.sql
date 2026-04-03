-- Migration 130: Staff onboarding Step 2 — Compensation and Benefits
-- Adds per-employee compensation and benefits columns required for the Step 2 onboarding page.
-- Also adds primary_group_id: the single group selected during the wizard (distinct from
-- staff_group_members many-to-many which drives the permissions system).

ALTER TABLE staff
    ADD COLUMN primary_group_id           BIGINT UNSIGNED                        NULL,
    ADD COLUMN pay_type                   ENUM(
                                            'none',
                                            'flat_hourly',
                                            'salary',
                                            'commission',
                                            'combination',
                                            'per_service_fee',
                                            'per_service_fee_with_bonus',
                                            'per_service_fee_by_employee',
                                            'service_commission_by_sales_tier'
                                          )                                      NULL,
    ADD COLUMN pay_type_classes           ENUM('same_as_services','commission_by_attendee') NULL,
    ADD COLUMN pay_type_products          ENUM('none','commission','commission_by_sales_tier','per_product_fee') NULL,
    ADD COLUMN vacation_days              SMALLINT UNSIGNED                       NULL,
    ADD COLUMN sick_days                  SMALLINT UNSIGNED                       NULL,
    ADD COLUMN personal_days              SMALLINT UNSIGNED                       NULL,
    ADD COLUMN employee_number            VARCHAR(100)                            NULL,
    ADD COLUMN has_dependents             TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN is_exempt                  TINYINT(1)  NOT NULL DEFAULT 0;

-- FK and index for primary_group_id (loose FK: does not cascade delete group membership system)
ALTER TABLE staff
    ADD INDEX idx_staff_primary_group (primary_group_id),
    ADD CONSTRAINT fk_staff_primary_group
        FOREIGN KEY (primary_group_id) REFERENCES staff_groups(id) ON DELETE SET NULL;
