CREATE TABLE gift_card_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gift_card_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    type ENUM('issue','redeem','adjustment','expire','cancel') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gct_gift_card_created (gift_card_id, created_at),
    INDEX idx_gct_branch_type_created (branch_id, type, created_at),
    INDEX idx_gct_reference (reference_type, reference_id),
    FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE RESTRICT,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
