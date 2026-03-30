<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use Modules\Notifications\Repositories\OutboundNotificationMessageRepository;
use PDOException;

/**
 * Queues marketing emails on the canonical outbound table (email channel only; {@see OutboundChannelPolicy}).
 * Uses event_key prefix {@code marketing.} so branch transactional notification toggles (appointment./waitlist./membership.) never apply.
 */
final class OutboundMarketingEnqueueService
{
    public const EVENT_KEY = 'marketing.campaign_send';
    public const AUTOMATION_EVENT_PREFIX = 'marketing.automation.';

    public function __construct(private OutboundNotificationMessageRepository $messages)
    {
    }

    /**
     * Idempotent per run + recipient row + channel (unique idempotency_key on outbound table).
     *
     * @param array<string, mixed> $payloadCtx
     * @return int outbound_notification_messages.id (existing or new)
     */
    public function enqueueCampaignRecipientEmail(
        int $marketingRecipientRowId,
        int $campaignRunId,
        int $campaignId,
        ?int $branchId,
        int $clientId,
        string $to,
        string $subject,
        string $bodyText,
        array $payloadCtx
    ): int {
        $idempotencyKey = 'email:v1:' . self::EVENT_KEY . ':run:' . $campaignRunId . ':mkt_recipient:' . $marketingRecipientRowId;
        $row = [
            'branch_id' => $branchId,
            'channel' => 'email',
            'event_key' => self::EVENT_KEY,
            'template_key' => self::EVENT_KEY,
            'idempotency_key' => $idempotencyKey,
            'recipient_type' => 'client',
            'recipient_id' => $clientId,
            'recipient_address' => $to,
            'subject' => $subject,
            'body_text' => $bodyText,
            'payload_json' => json_encode($payloadCtx, JSON_THROW_ON_ERROR),
            'entity_type' => 'marketing_campaign_recipient',
            'entity_id' => $marketingRecipientRowId,
            'status' => 'pending',
            'skip_reason' => null,
            'error_summary' => null,
            'scheduled_at' => null,
        ];
        try {
            return $this->messages->insert($row);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                $existing = $this->messages->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return (int) ($existing['id'] ?? 0);
                }
            }
            throw $e;
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'duplicate') || (string) $e->getCode() === '23000';
    }

    public function hasQueuedMessage(string $idempotencyKey): bool
    {
        return $this->messages->findByIdempotencyKey(trim($idempotencyKey)) !== null;
    }

    /**
     * @param array<string, mixed> $payloadCtx
     * @return array{message_id: int, created: bool}
     */
    public function enqueueAutomationClientEmail(
        string $automationKey,
        int $branchId,
        int $clientId,
        string $to,
        string $subject,
        string $bodyText,
        string $idempotencyKey,
        array $payloadCtx
    ): array {
        $existing = $this->messages->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return [
                'message_id' => (int) ($existing['id'] ?? 0),
                'created' => false,
            ];
        }

        $eventKey = self::AUTOMATION_EVENT_PREFIX . trim($automationKey);
        $row = [
            'branch_id' => $branchId,
            'channel' => 'email',
            'event_key' => $eventKey,
            'template_key' => $eventKey,
            'idempotency_key' => $idempotencyKey,
            'recipient_type' => 'client',
            'recipient_id' => $clientId,
            'recipient_address' => $to,
            'subject' => $subject,
            'body_text' => $bodyText,
            'payload_json' => json_encode($payloadCtx, JSON_THROW_ON_ERROR),
            'entity_type' => 'marketing_automation',
            'entity_id' => null,
            'status' => 'pending',
            'skip_reason' => null,
            'error_summary' => null,
            'scheduled_at' => null,
        ];
        try {
            return [
                'message_id' => $this->messages->insert($row),
                'created' => true,
            ];
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                $dupe = $this->messages->findByIdempotencyKey($idempotencyKey);
                if ($dupe !== null) {
                    return [
                        'message_id' => (int) ($dupe['id'] ?? 0),
                        'created' => false,
                    ];
                }
            }
            throw $e;
        }
    }
}
