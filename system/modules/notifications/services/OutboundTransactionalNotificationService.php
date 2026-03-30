<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use Core\App\SettingsService;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Notifications\Repositories\OutboundNotificationMessageRepository;
use PDOException;

/**
 * Queues outbound transactional email on the canonical table. Business modules call this; they do not send directly.
 * Only {@code channel = email} is allowed at insert time ({@see OutboundChannelPolicy}); SMS cannot be enqueued until a provider exists.
 * Delivery is asynchronous via {@see OutboundNotificationDispatchService} (local log, php mail(), or SMTP — not inbox-guaranteed).
 * Idempotency: unique idempotency_key per business event + channel; duplicates are ignored (insert not repeated).
 */
final class OutboundTransactionalNotificationService
{
    public const EVENT_WAITLIST_OFFER = 'waitlist.offer';

    public function __construct(
        private OutboundNotificationMessageRepository $messages,
        private ClientRepository $clients,
        private AppointmentRepository $appointments,
        private SettingsService $settings,
        private OutboundTemplateRenderer $templates
    ) {
    }

    public function enqueueAppointmentConfirmation(int $appointmentId): void
    {
        $eventKey = 'appointment.confirmation';
        $apt = $this->appointments->find($appointmentId);
        if (!$apt) {
            return;
        }
        $branchId = $this->nullableInt($apt['branch_id'] ?? null);
        if (!$this->settings->shouldEmitOutboundNotificationForEvent($eventKey, $branchId)) {
            return;
        }
        $clientId = (int) ($apt['client_id'] ?? 0);
        if ($clientId <= 0) {
            return;
        }
        $client = $this->clients->find($clientId);
        if (!$client) {
            return;
        }
        $email = $this->normalizeEmail($client['email'] ?? null);
        $idempotencyKey = 'email:v1:' . $eventKey . ':appointment:' . $appointmentId;
        if ($email === null) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'no_client_email',
                'appointment',
                $appointmentId,
                []
            );

            return;
        }
        $ctx = $this->appointmentContext($apt, $client);
        try {
            $rendered = $this->templates->render('appointment.confirmation', $ctx);
        } catch (\Throwable $e) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'template_error:' . $e->getMessage(),
                'appointment',
                $appointmentId,
                $ctx
            );

            return;
        }
        $this->tryInsertPending(
            $idempotencyKey,
            $eventKey,
            'email',
            $branchId,
            'client',
            $clientId,
            $email,
            $rendered['subject'],
            $rendered['body'],
            ['appointment_id' => $appointmentId, 'client_id' => $clientId] + $ctx,
            'appointment',
            $appointmentId
        );
    }

    public function enqueueAppointmentCancelled(int $appointmentId): void
    {
        $eventKey = 'appointment.cancelled';
        $apt = $this->appointments->find($appointmentId);
        if (!$apt) {
            return;
        }
        $branchId = $this->nullableInt($apt['branch_id'] ?? null);
        if (!$this->settings->shouldEmitOutboundNotificationForEvent($eventKey, $branchId)) {
            return;
        }
        $clientId = (int) ($apt['client_id'] ?? 0);
        if ($clientId <= 0) {
            return;
        }
        $client = $this->clients->find($clientId);
        if (!$client) {
            return;
        }
        $email = $this->normalizeEmail($client['email'] ?? null);
        $idempotencyKey = 'email:v1:' . $eventKey . ':appointment:' . $appointmentId;
        if ($email === null) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'no_client_email',
                'appointment',
                $appointmentId,
                []
            );

            return;
        }
        $ctx = $this->appointmentContext($apt, $client);
        try {
            $rendered = $this->templates->render('appointment.cancelled', $ctx);
        } catch (\Throwable $e) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'template_error:' . $e->getMessage(),
                'appointment',
                $appointmentId,
                $ctx
            );

            return;
        }
        $this->tryInsertPending(
            $idempotencyKey,
            $eventKey,
            'email',
            $branchId,
            'client',
            $clientId,
            $email,
            $rendered['subject'],
            $rendered['body'],
            ['appointment_id' => $appointmentId] + $ctx,
            'appointment',
            $appointmentId
        );
    }

    public function enqueueAppointmentRescheduled(int $appointmentId): void
    {
        $eventKey = 'appointment.rescheduled';
        $apt = $this->appointments->find($appointmentId);
        if (!$apt) {
            return;
        }
        $branchId = $this->nullableInt($apt['branch_id'] ?? null);
        if (!$this->settings->shouldEmitOutboundNotificationForEvent($eventKey, $branchId)) {
            return;
        }
        $clientId = (int) ($apt['client_id'] ?? 0);
        if ($clientId <= 0) {
            return;
        }
        $client = $this->clients->find($clientId);
        if (!$client) {
            return;
        }
        $email = $this->normalizeEmail($client['email'] ?? null);
        $startAt = trim((string) ($apt['start_at'] ?? ''));
        $idempotencyKey = 'email:v1:' . $eventKey . ':appointment:' . $appointmentId . ':start:' . $startAt;
        if ($email === null) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'no_client_email',
                'appointment',
                $appointmentId,
                []
            );

            return;
        }
        $ctx = $this->appointmentContext($apt, $client);
        try {
            $rendered = $this->templates->render('appointment.rescheduled', $ctx);
        } catch (\Throwable $e) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'template_error:' . $e->getMessage(),
                'appointment',
                $appointmentId,
                $ctx
            );

            return;
        }
        $this->tryInsertPending(
            $idempotencyKey,
            $eventKey,
            'email',
            $branchId,
            'client',
            $clientId,
            $email,
            $rendered['subject'],
            $rendered['body'],
            ['appointment_id' => $appointmentId] + $ctx,
            'appointment',
            $appointmentId
        );
    }

    /**
     * @param array<string, mixed> $waitlistRow from {@see \Modules\Appointments\Repositories\WaitlistRepository::find}
     * @return array{outcome: string, detail?: string|null}
     */
    /**
     * When the waitlist row is no longer an active offer, pending customer emails for {@see EVENT_WAITLIST_OFFER} must not send.
     *
     * @return int number of queue rows marked skipped
     */
    public function suppressPendingWaitlistOfferEmails(int $waitlistId, string $skipReason): int
    {
        if ($waitlistId <= 0) {
            return 0;
        }

        return $this->messages->skipPendingByEntityTypeAndEventKey(
            'appointment_waitlist',
            $waitlistId,
            self::EVENT_WAITLIST_OFFER,
            $skipReason
        );
    }

    public function enqueueWaitlistOffer(int $waitlistId, array $waitlistRow, string $offerStartedAt, ?string $offerExpiresAt): array
    {
        $eventKey = self::EVENT_WAITLIST_OFFER;
        $branchId = $this->nullableInt($waitlistRow['branch_id'] ?? null);
        if (!$this->settings->shouldEmitOutboundNotificationForEvent($eventKey, $branchId)) {
            return ['outcome' => 'outbound_event_gated'];
        }
        $clientId = (int) ($waitlistRow['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['outcome' => 'skipped_no_client_id'];
        }
        $client = $this->clients->find($clientId);
        if (!$client) {
            return ['outcome' => 'skipped_client_not_found'];
        }
        $email = $this->normalizeEmail($client['email'] ?? null);
        $idempotencyKey = 'email:v1:' . $eventKey . ':waitlist:' . $waitlistId . ':offer_started:' . trim($offerStartedAt);
        if ($email === null) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'no_client_email',
                'appointment_waitlist',
                $waitlistId,
                []
            );

            return ['outcome' => 'skipped_no_client_email'];
        }
        $expNote = $offerExpiresAt !== null && trim($offerExpiresAt) !== ''
            ? 'Please respond before ' . trim($offerExpiresAt) . '.'
            : 'No automatic expiry time is set for this offer.';
        $ctx = [
            'client_first_name' => (string) ($client['first_name'] ?? ''),
            'client_last_name' => (string) ($client['last_name'] ?? ''),
            'waitlist_id' => (string) $waitlistId,
            'preferred_date' => (string) ($waitlistRow['preferred_date'] ?? ''),
            'offer_expiry_note' => $expNote,
        ];
        try {
            $rendered = $this->templates->render('waitlist.offer', $ctx);
        } catch (\Throwable $e) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'template_error:' . $e->getMessage(),
                'appointment_waitlist',
                $waitlistId,
                $ctx
            );

            return ['outcome' => 'skipped_template_error', 'detail' => $e->getMessage()];
        }
        $ins = $this->tryInsertPending(
            $idempotencyKey,
            $eventKey,
            'email',
            $branchId,
            'client',
            $clientId,
            $email,
            $rendered['subject'],
            $rendered['body'],
            ['waitlist_id' => $waitlistId, 'client_id' => $clientId] + $ctx,
            'appointment_waitlist',
            $waitlistId
        );

        return $ins === 'duplicate'
            ? ['outcome' => 'duplicate_ignored']
            : ['outcome' => 'pending_enqueued'];
    }

    /**
     * @param array<string, mixed> $membershipRow scan row with client_first_name, definition_name, ends_at
     * @return array{outcome: string, detail?: string|null}
     */
    public function enqueueMembershipRenewalReminder(int $clientMembershipId, array $membershipRow): array
    {
        $eventKey = 'membership.renewal_reminder';
        $branchId = $this->nullableInt($membershipRow['branch_id'] ?? null);
        if (!$this->settings->shouldEmitOutboundNotificationForEvent($eventKey, $branchId)) {
            return ['outcome' => 'outbound_event_gated'];
        }
        $clientId = (int) ($membershipRow['client_id'] ?? 0);
        if ($clientId <= 0) {
            return ['outcome' => 'skipped_no_client_id'];
        }
        $client = $this->clients->find($clientId);
        if (!$client) {
            return ['outcome' => 'skipped_client_not_found'];
        }
        $email = $this->normalizeEmail($client['email'] ?? null);
        $endsAt = trim((string) ($membershipRow['ends_at'] ?? ''));
        $idempotencyKey = 'email:v1:' . $eventKey . ':cm:' . $clientMembershipId . ':ends:' . $endsAt;
        if ($email === null) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'no_client_email',
                'client_membership',
                $clientMembershipId,
                []
            );

            return ['outcome' => 'skipped_no_client_email'];
        }
        $ctx = [
            'client_first_name' => (string) ($client['first_name'] ?? ''),
            'client_last_name' => (string) ($client['last_name'] ?? ''),
            'plan_name' => trim((string) ($membershipRow['definition_name'] ?? 'Membership')),
            'ends_at' => $endsAt,
            'client_membership_id' => (string) $clientMembershipId,
        ];
        try {
            $rendered = $this->templates->render('membership.renewal_reminder', $ctx);
        } catch (\Throwable $e) {
            $this->insertSkipped(
                $idempotencyKey,
                $eventKey,
                $branchId,
                $clientId,
                'template_error:' . $e->getMessage(),
                'client_membership',
                $clientMembershipId,
                $ctx
            );

            return ['outcome' => 'skipped_template_error', 'detail' => $e->getMessage()];
        }
        $ins = $this->tryInsertPending(
            $idempotencyKey,
            $eventKey,
            'email',
            $branchId,
            'client',
            $clientId,
            $email,
            $rendered['subject'],
            $rendered['body'],
            ['client_membership_id' => $clientMembershipId, 'client_id' => $clientId] + $ctx,
            'client_membership',
            $clientMembershipId
        );

        return $ins === 'duplicate'
            ? ['outcome' => 'duplicate_ignored']
            : ['outcome' => 'pending_enqueued'];
    }

    /**
     * @param array<string, mixed> $payloadCtx
     * @return 'inserted'|'duplicate'
     */
    private function tryInsertPending(
        string $idempotencyKey,
        string $eventKey,
        string $channel,
        ?int $branchId,
        string $recipientType,
        int $recipientId,
        string $recipientAddress,
        string $subject,
        string $bodyText,
        array $payloadCtx,
        ?string $entityType,
        ?int $entityId
    ): string {
        $row = [
            'branch_id' => $branchId,
            'channel' => $channel,
            'event_key' => $eventKey,
            'template_key' => $eventKey,
            'idempotency_key' => $idempotencyKey,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'recipient_address' => $recipientAddress,
            'subject' => $subject,
            'body_text' => $bodyText,
            'payload_json' => json_encode($payloadCtx, JSON_THROW_ON_ERROR),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => 'pending',
            'skip_reason' => null,
            'error_summary' => null,
            'scheduled_at' => null,
        ];
        try {
            $this->messages->insert($row);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return 'duplicate';
            }
            throw $e;
        }

        return 'inserted';
    }

    /**
     * @param array<string, mixed> $payloadCtx
     */
    private function insertSkipped(
        string $idempotencyKey,
        string $eventKey,
        ?int $branchId,
        int $recipientClientId,
        string $reason,
        ?string $entityType,
        ?int $entityId,
        array $payloadCtx
    ): void {
        $row = [
            'branch_id' => $branchId,
            'channel' => 'email',
            'event_key' => $eventKey,
            'template_key' => $eventKey,
            'idempotency_key' => $idempotencyKey,
            'recipient_type' => 'client',
            'recipient_id' => $recipientClientId,
            'recipient_address' => '(none)',
            'subject' => null,
            'body_text' => '[skipped]',
            'payload_json' => $payloadCtx === [] ? null : json_encode($payloadCtx, JSON_THROW_ON_ERROR),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => 'skipped',
            'skip_reason' => $reason,
            'error_summary' => null,
            'scheduled_at' => null,
        ];
        try {
            $this->messages->insert($row);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                return;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $apt
     * @param array<string, mixed> $client
     * @return array<string, scalar>
     */
    private function appointmentContext(array $apt, array $client): array
    {
        return [
            'appointment_id' => (string) ($apt['id'] ?? ''),
            'appointment_start_at' => (string) ($apt['start_at'] ?? ''),
            'appointment_end_at' => (string) ($apt['end_at'] ?? ''),
            'service_name' => (string) ($apt['service_name'] ?? ''),
            'staff_name' => trim(((string) ($apt['staff_first_name'] ?? '')) . ' ' . ((string) ($apt['staff_last_name'] ?? ''))),
            'client_first_name' => (string) ($client['first_name'] ?? ''),
            'client_last_name' => (string) ($client['last_name'] ?? ''),
        ];
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $e = trim((string) ($email ?? ''));
        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $e;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $m = strtolower($e->getMessage());

        return str_contains($m, 'duplicate') || (string) $e->getCode() === '23000';
    }
}
