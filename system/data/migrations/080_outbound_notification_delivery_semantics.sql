-- Honest outbound message statuses: distinguish local log capture vs MTA handoff attempt.
-- Legacy rows may remain status `sent` (meaning unknown pre-migration completion).

ALTER TABLE outbound_notification_messages
MODIFY COLUMN status ENUM(
    'pending',
    'sent',
    'failed',
    'skipped',
    'captured_locally',
    'handoff_accepted'
) NOT NULL DEFAULT 'pending';
