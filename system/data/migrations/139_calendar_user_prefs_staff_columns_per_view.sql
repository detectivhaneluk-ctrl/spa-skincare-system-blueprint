-- WAVE-CAL-UI-03: responsive staff-per-view snap preset (1–6 columns).
-- NULL means legacy free-px mode (column_width_px drives rendering).

ALTER TABLE calendar_user_preferences
    ADD COLUMN staff_columns_per_view TINYINT UNSIGNED NULL
        COMMENT 'Responsive snap: how many staff columns fill the viewport (1-6). NULL = legacy px mode.'
        AFTER staff_order_freelancer_ids;
