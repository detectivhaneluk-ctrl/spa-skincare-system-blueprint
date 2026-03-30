<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use Core\App\Config;
use Core\Audit\AuditService;
use Core\Contracts\OutboundMailTransportInterface;
use Modules\Notifications\Repositories\OutboundNotificationAttemptRepository;
use Modules\Notifications\Repositories\OutboundNotificationMessageRepository;
use Modules\Notifications\Services\OutboundChannelPolicy;
use Modules\Notifications\Transports\LogOutboundMailTransport;
use Modules\Notifications\Transports\PhpMailOutboundTransport;
use Modules\Notifications\Transports\SmtpOutboundMailTransport;

/**
 * Worker batch dispatch: claims pending rows (→ processing), records attempts, terminal or retry state.
 * Runtime plane: notifications.outbound_drain_batch jobs call runBatch() from the PHP runtime worker (governance shell + domain dispatch). See RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH.
 * Email: {@see OutboundMailTransportInterface::successMessageStatus} — captured_locally vs handoff_accepted (never claims inbox delivery).
 * Non-operational channels ({@see OutboundChannelPolicy}): SMS → single terminal skip (no retries); unknown → failed.
 * Concurrent workers: FOR UPDATE SKIP LOCKED + stale processing reclaim.
 */
final class OutboundNotificationDispatchService
{
    private bool $auditedUnrecognizedMailDriverThisBatch = false;

    public function __construct(
        private OutboundNotificationMessageRepository $messages,
        private OutboundNotificationAttemptRepository $attempts,
        private Config $config,
        private AuditService $audit,
        private LogOutboundMailTransport $logMail,
        private PhpMailOutboundTransport $phpMail,
        private SmtpOutboundMailTransport $smtpMail
    ) {
    }

    /**
     * @return array{
     *   processed: int,
     *   captured_locally: int,
     *   handoff_accepted: int,
     *   failed: int,
     *   skipped: int,
     *   sent_legacy_compatible: int,
     *   reclaimed_stale: int,
     *   retry_scheduled: int
     * }
     */
    public function runBatch(int $limit = 50): array
    {
        $this->auditedUnrecognizedMailDriverThisBatch = false;
        $stats = [
            'processed' => 0,
            'captured_locally' => 0,
            'handoff_accepted' => 0,
            'failed' => 0,
            'skipped' => 0,
            'sent_legacy_compatible' => 0,
            'reclaimed_stale' => 0,
            'retry_scheduled' => 0,
        ];

        $staleMinutes = (int) $this->config->get('outbound.dispatch_stale_claim_minutes', 15);
        $staleMinutes = max(1, min(1440, $staleMinutes));
        $reclaimed = $this->messages->reclaimStaleProcessingClaims($staleMinutes);
        $stats['reclaimed_stale'] = $reclaimed;
        if ($reclaimed > 0) {
            $this->audit->log('outbound_dispatch_stale_claims_reclaimed', 'outbound_notification_message', null, null, null, [
                'count' => $reclaimed,
                'older_than_minutes' => $staleMinutes,
            ]);
        }

        $rows = $this->messages->claimPendingBatchForDispatch($limit);
        foreach ($rows as $row) {
            ++$stats['processed'];
            $id = (int) ($row['id'] ?? 0);
            $channel = strtolower(trim((string) ($row['channel'] ?? '')));
            $branchId = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null;
            if (!OutboundChannelPolicy::isOperational($channel)) {
                $n = $this->attempts->nextAttemptNo($id);
                if ($channel === 'sms') {
                    $this->attempts->insert($id, $n, 'none', 'failed', 'channel_not_operational', [
                        'channel' => 'sms',
                        'operational_channels' => OutboundChannelPolicy::operationalChannels(),
                    ]);
                    $this->messages->finishClaimedSkipped($id, OutboundChannelPolicy::SKIP_REASON_SMS_NOT_OPERATIONAL);
                    $this->audit->log('outbound_channel_blocked', 'outbound_notification_message', $id, null, $branchId, [
                        'channel' => 'sms',
                        'reason' => OutboundChannelPolicy::SKIP_REASON_SMS_NOT_OPERATIONAL,
                    ]);
                    ++$stats['skipped'];
                } else {
                    $this->attempts->insert($id, $n, 'none', 'failed', 'unknown_channel:' . $channel, null);
                    $this->messages->finishClaimedFailure($id, 'unknown_channel', date('Y-m-d H:i:s'));
                    $this->audit->log('outbound_channel_blocked', 'outbound_notification_message', $id, null, $branchId, [
                        'channel' => $channel,
                        'reason' => 'unknown_channel',
                    ]);
                    ++$stats['failed'];
                }
                continue;
            }
            $to = trim((string) ($row['recipient_address'] ?? ''));
            $subject = trim((string) ($row['subject'] ?? ''));
            $body = (string) ($row['body_text'] ?? '');
            if ($to === '' || $subject === '') {
                $n = $this->attempts->nextAttemptNo($id);
                $this->attempts->insert($id, $n, 'validation', 'failed', 'missing_recipient_or_subject', ['to' => $to]);
                $this->messages->finishClaimedFailure($id, 'missing_recipient_or_subject', date('Y-m-d H:i:s'));
                $this->audit->log('outbound_dispatch_outcome', 'outbound_notification_message', $id, null, $branchId, [
                    'channel' => 'email',
                    'outcome' => 'failed',
                    'error' => 'missing_recipient_or_subject',
                ]);
                ++$stats['failed'];
                continue;
            }
            $resolved = $this->resolveMailTransport();
            $transport = $resolved['transport'];
            $configuredDriver = (string) ($this->config->get('outbound.mail_transport', 'log'));
            if ($resolved['unrecognized_driver'] !== null && !$this->auditedUnrecognizedMailDriverThisBatch) {
                $this->audit->log('outbound_mail_transport_fallback', 'outbound_notification_message', null, null, $branchId, [
                    'configured_driver' => $resolved['unrecognized_driver'],
                    'fallback' => 'local_log',
                ]);
                $this->auditedUnrecognizedMailDriverThisBatch = true;
            }
            $attemptNo = $this->attempts->nextAttemptNo($id);
            $result = $transport->send($to, $subject, $body, $branchId);
            if (!empty($result['ok'])) {
                $terminal = $transport->successMessageStatus();
                if (!in_array($terminal, ['captured_locally', 'handoff_accepted'], true)) {
                    $terminal = 'captured_locally';
                }
                $this->attempts->insert($id, $attemptNo, $transport->getName(), 'success', null, array_merge(
                    $result['detail'] ?? [],
                    [
                        'configured_mail_transport' => strtolower(trim($configuredDriver)),
                        'terminal_status' => $terminal,
                    ]
                ));
                $this->messages->finishClaimedSuccess($id, $terminal, date('Y-m-d H:i:s'));
                $this->audit->log('outbound_dispatch_outcome', 'outbound_notification_message', $id, null, $branchId, [
                    'channel' => 'email',
                    'outcome' => $terminal,
                    'transport' => $transport->getName(),
                    'configured_mail_transport' => strtolower(trim($configuredDriver)),
                ]);
                if ($terminal === 'captured_locally') {
                    ++$stats['captured_locally'];
                } else {
                    ++$stats['handoff_accepted'];
                }
                ++$stats['sent_legacy_compatible'];
            } else {
                $err = trim((string) ($result['error'] ?? 'send_failed'));
                $this->attempts->insert($id, $attemptNo, $transport->getName(), 'failed', $err, array_merge(
                    $result['detail'] ?? [],
                    ['configured_mail_transport' => strtolower(trim($configuredDriver))]
                ));
                $maxAttempts = (int) $this->config->get('outbound.mail_max_attempts', 5);
                $maxAttempts = max(1, min(15, $maxAttempts));
                if ($attemptNo < $maxAttempts) {
                    $delay = $this->retryDelaySeconds($attemptNo);
                    $when = date('Y-m-d H:i:s', time() + $delay);
                    $summary = 'retry_scheduled:' . $attemptNo . ':' . $err;
                    $this->messages->scheduleClaimedRetry($id, $when, $summary);
                    $this->audit->log('outbound_dispatch_outcome', 'outbound_notification_message', $id, null, $branchId, [
                        'channel' => 'email',
                        'outcome' => 'retry_scheduled',
                        'transport' => $transport->getName(),
                        'error' => $err,
                        'next_attempt_no' => $attemptNo + 1,
                        'scheduled_at' => $when,
                        'delay_seconds' => $delay,
                        'configured_mail_transport' => strtolower(trim($configuredDriver)),
                    ]);
                    ++$stats['retry_scheduled'];
                } else {
                    $this->messages->finishClaimedFailure($id, $err, date('Y-m-d H:i:s'));
                    $this->audit->log('outbound_dispatch_outcome', 'outbound_notification_message', $id, null, $branchId, [
                        'channel' => 'email',
                        'outcome' => 'failed',
                        'transport' => $transport->getName(),
                        'error' => $err,
                        'attempt_no' => $attemptNo,
                        'configured_mail_transport' => strtolower(trim($configuredDriver)),
                    ]);
                    ++$stats['failed'];
                }
            }
        }

        return $stats;
    }

    private function retryDelaySeconds(int $failedAttemptNo): int
    {
        $base = (int) $this->config->get('outbound.mail_retry_base_seconds', 60);
        $base = max(15, min(900, $base));
        $pow = $failedAttemptNo - 1;
        $pow = max(0, min(8, $pow));

        return min(3600, $base * (2 ** $pow));
    }

    /**
     * @return array{transport: OutboundMailTransportInterface, unrecognized_driver: ?string}
     */
    private function resolveMailTransport(): array
    {
        $raw = strtolower(trim((string) $this->config->get('outbound.mail_transport', 'log')));
        if ($raw === 'php_mail' || $raw === 'mail') {
            return ['transport' => $this->phpMail, 'unrecognized_driver' => null];
        }
        if ($raw === 'smtp') {
            $host = trim((string) $this->config->get('outbound.smtp_host', ''));
            if ($host === '') {
                return ['transport' => $this->logMail, 'unrecognized_driver' => 'smtp_missing_smtp_host'];
            }

            return ['transport' => $this->smtpMail, 'unrecognized_driver' => null];
        }
        if ($raw === 'log' || $raw === '') {
            return ['transport' => $this->logMail, 'unrecognized_driver' => null];
        }

        return ['transport' => $this->logMail, 'unrecognized_driver' => $raw];
    }
}
