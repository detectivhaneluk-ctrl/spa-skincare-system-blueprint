-- FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01: unified execution ledger for PHP schedulers + image worker heartbeat.
-- Honest visibility: last start/finish/success/failure, heartbeats, exclusive-run active slot (with stale recovery).

CREATE TABLE IF NOT EXISTS runtime_execution_registry (
    execution_key VARCHAR(190) NOT NULL,
    last_started_at DATETIME(3) NULL,
    last_finished_at DATETIME(3) NULL,
    last_success_at DATETIME(3) NULL,
    last_failure_at DATETIME(3) NULL,
    last_error_summary VARCHAR(2000) NULL,
    last_heartbeat_at DATETIME(3) NULL,
    active_started_at DATETIME(3) NULL,
    active_heartbeat_at DATETIME(3) NULL,
    active_meta VARCHAR(512) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (execution_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Scheduler/worker execution truth: FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01';
