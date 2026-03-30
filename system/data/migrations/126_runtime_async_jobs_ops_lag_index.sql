-- FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03
-- Ops/lag visibility: filter by queue + status ordered by recency (worker dashboards, slow-consumer alerts).

ALTER TABLE runtime_async_jobs
    ADD INDEX idx_runtime_async_jobs_queue_status_updated (queue, status, updated_at, id);
