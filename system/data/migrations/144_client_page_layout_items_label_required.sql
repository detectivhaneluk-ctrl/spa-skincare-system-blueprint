-- Per-profile layout overrides for composer: display label and required flag on layout rows.

ALTER TABLE client_page_layout_items
    ADD COLUMN display_label VARCHAR(150) NULL DEFAULT NULL AFTER is_enabled,
    ADD COLUMN is_required TINYINT(1) NULL DEFAULT NULL AFTER display_label;
