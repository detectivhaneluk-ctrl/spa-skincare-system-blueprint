-- Per-field horizontal span on customer details form (1–3 columns on a 3-column grid).

ALTER TABLE client_page_layout_items
    ADD COLUMN layout_span TINYINT UNSIGNED NOT NULL DEFAULT 3
    AFTER is_required;
