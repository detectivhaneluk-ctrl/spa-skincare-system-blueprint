-- FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02: durable async job queue (DB-backed; worker-driven).
-- Status machine: pending -> processing -> succeeded | failed -> (retry) pending | dead

CREATE TABLE IF NOT EXISTS runtime_async_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(64) NOT NULL,
    job_type VARCHAR(128) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(24) NOT NULL COMMENT 'pending,processing,succeeded,failed,dead',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    reserved_at DATETIME(3) NULL DEFAULT NULL,
    last_error VARCHAR(4000) NULL DEFAULT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_runtime_async_jobs_queue_pick (queue, status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Durable job queue: FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02';
