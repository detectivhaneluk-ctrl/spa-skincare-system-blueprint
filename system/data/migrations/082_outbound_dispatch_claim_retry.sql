-- Worker-safe dispatch: claim rows from pending → processing, stale reclaim, optional bounded retries.
-- Requires MySQL 8+ or MariaDB 10.6+ for FOR UPDATE SKIP LOCKED used by the dispatcher.

ALTER TABLE outbound_notification_messages
MODIFY COLUMN status ENUM(
    'pending',
    'processing',
    'sent',
    'failed',
    'skipped',
    'captured_locally',
    'handoff_accepted'
) NOT NULL DEFAULT 'pending';

ALTER TABLE outbound_notification_messages
ADD COLUMN claimed_at TIMESTAMP NULL DEFAULT NULL AFTER scheduled_at,
ADD KEY idx_outbound_processing_claimed (status, claimed_at);
