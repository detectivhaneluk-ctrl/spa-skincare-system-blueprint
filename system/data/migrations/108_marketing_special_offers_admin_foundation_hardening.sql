-- Promotions admin foundation hardening:
-- guarantee offer_option column and add date-window support.

SET @has_col_offer_option := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'marketing_special_offers'
      AND COLUMN_NAME = 'offer_option'
);
SET @sql_col_offer_option := IF(
    @has_col_offer_option = 0,
    'ALTER TABLE marketing_special_offers ADD COLUMN offer_option VARCHAR(60) NOT NULL DEFAULT ''all'' AFTER adjustment_value',
    'DO 0'
);
PREPARE stmt_col_offer_option FROM @sql_col_offer_option;
EXECUTE stmt_col_offer_option;
DEALLOCATE PREPARE stmt_col_offer_option;

SET @has_col_start_date := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'marketing_special_offers'
      AND COLUMN_NAME = 'start_date'
);
SET @sql_col_start_date := IF(
    @has_col_start_date = 0,
    'ALTER TABLE marketing_special_offers ADD COLUMN start_date DATE NULL AFTER is_active',
    'DO 0'
);
PREPARE stmt_col_start_date FROM @sql_col_start_date;
EXECUTE stmt_col_start_date;
DEALLOCATE PREPARE stmt_col_start_date;

SET @has_col_end_date := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'marketing_special_offers'
      AND COLUMN_NAME = 'end_date'
);
SET @sql_col_end_date := IF(
    @has_col_end_date = 0,
    'ALTER TABLE marketing_special_offers ADD COLUMN end_date DATE NULL AFTER start_date',
    'DO 0'
);
PREPARE stmt_col_end_date FROM @sql_col_end_date;
EXECUTE stmt_col_end_date;
DEALLOCATE PREPARE stmt_col_end_date;

SET @has_idx_option := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'marketing_special_offers'
      AND INDEX_NAME = 'idx_mkt_special_offers_option'
);
SET @sql_idx_option := IF(
    @has_idx_option = 0,
    'ALTER TABLE marketing_special_offers ADD INDEX idx_mkt_special_offers_option (offer_option)',
    'DO 0'
);
PREPARE stmt_idx_option FROM @sql_idx_option;
EXECUTE stmt_idx_option;
DEALLOCATE PREPARE stmt_idx_option;

SET @has_idx_active_window := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'marketing_special_offers'
      AND INDEX_NAME = 'idx_mkt_special_offers_active_window'
);
SET @sql_idx_active_window := IF(
    @has_idx_active_window = 0,
    'ALTER TABLE marketing_special_offers ADD INDEX idx_mkt_special_offers_active_window (branch_id, is_active, start_date, end_date)',
    'DO 0'
);
PREPARE stmt_idx_active_window FROM @sql_idx_active_window;
EXECUTE stmt_idx_active_window;
DEALLOCATE PREPARE stmt_idx_active_window;
