CREATE TABLE public_booking_abuse_hits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(64) NOT NULL,
    throttle_key VARCHAR(191) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_public_booking_abuse_bucket_key_created (bucket, throttle_key, created_at),
    INDEX idx_public_booking_abuse_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
