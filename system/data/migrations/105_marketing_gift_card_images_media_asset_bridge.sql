-- MARKETING-GIFT-CARD-IMAGE-PIPELINE-CANONICALIZATION-01
-- Nullable link from marketing gift-card library rows to canonical media_assets (migration 103).
-- Legacy direct-file rows keep media_asset_id NULL; new uploads populate it.

ALTER TABLE marketing_gift_card_images
    ADD COLUMN media_asset_id BIGINT UNSIGNED NULL AFTER branch_id,
    ADD INDEX idx_mkt_gc_images_media_asset (media_asset_id),
    ADD CONSTRAINT fk_mkt_gc_images_media_asset
        FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL;
