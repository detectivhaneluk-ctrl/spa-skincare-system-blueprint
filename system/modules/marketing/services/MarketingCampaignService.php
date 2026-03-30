<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\App\Database;
use Core\Audit\AuditService;
use Core\Auth\AuthService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Marketing\Repositories\MarketingCampaignRecipientRepository;
use Modules\Marketing\Repositories\MarketingCampaignRepository;
use Modules\Marketing\Repositories\MarketingCampaignRunRepository;
use Modules\Notifications\Services\OutboundMarketingEnqueueService;
use PDO;

final class MarketingCampaignService
{
    public const CAMPAIGN_STATUSES = ['draft', 'archived'];

    public function __construct(
        private Database $db,
        private MarketingCampaignRepository $campaigns,
        private MarketingCampaignRunRepository $runs,
        private MarketingCampaignRecipientRepository $recipients,
        private MarketingSegmentEvaluator $segmentEvaluator,
        private OutboundMarketingEnqueueService $outboundMarketing,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private AuditService $audit,
        private AuthService $auth
    ) {
    }

    /**
     * @param array{name: string, branch_id?: int|null, segment_key: string, segment_config?: array<string, mixed>, subject: string, body_text: string, status?: string} $data
     */
    public function createCampaign(array $data): int
    {
        $segmentKey = trim((string) ($data['segment_key'] ?? ''));
        if (!MarketingSegmentEvaluator::isAllowedSegmentKey($segmentKey)) {
            throw new \InvalidArgumentException('Unknown or unsupported segment.');
        }
        $status = trim((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, self::CAMPAIGN_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid campaign status.');
        }
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body_text'] ?? ''));
        if ($subject === '' || $body === '') {
            throw new \InvalidArgumentException('Subject and body are required.');
        }
        $payload = [
            'branch_id' => $data['branch_id'] ?? null,
            'name' => trim((string) ($data['name'] ?? '')),
            'channel' => 'email',
            'segment_key' => $segmentKey,
            'segment_config_json' => $this->encodeSegmentConfig($data['segment_config'] ?? []),
            'subject' => $subject,
            'body_text' => $body,
            'status' => $status,
            'created_by' => $this->currentUserId(),
            'updated_by' => $this->currentUserId(),
        ];
        if ($payload['name'] === '') {
            throw new \InvalidArgumentException('Campaign name is required.');
        }
        if ($this->organizationContext->getCurrentOrganizationId() !== null) {
            if ($payload['branch_id'] === null || $payload['branch_id'] === '') {
                throw new \DomainException('Campaign branch is required when organization context is resolved.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization((int) $payload['branch_id']);
        }
        $id = $this->campaigns->insert($payload);
        $this->audit->log('marketing_campaign_created', 'marketing_campaign', $id, $this->currentUserId(), $payload['branch_id'], [
            'segment_key' => $segmentKey,
        ]);

        return $id;
    }

    /**
     * @param array{name?: string, segment_key?: string, segment_config?: array<string, mixed>, subject?: string, body_text?: string, status?: string} $patch
     */
    public function updateCampaign(int $id, array $patch): void
    {
        $current = $this->campaigns->findInTenantScopeForStaff($id);
        if (!$current) {
            throw new \InvalidArgumentException('Campaign not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity(
            isset($current['branch_id']) && $current['branch_id'] !== '' && $current['branch_id'] !== null
                ? (int) $current['branch_id']
                : null
        );
        if ((string) ($current['status'] ?? '') === 'archived') {
            throw new \DomainException('Archived campaigns cannot be edited.');
        }
        $row = [];
        if (isset($patch['name'])) {
            $n = trim((string) $patch['name']);
            if ($n === '') {
                throw new \InvalidArgumentException('Campaign name is required.');
            }
            $row['name'] = $n;
        }
        if (isset($patch['segment_key'])) {
            $sk = trim((string) $patch['segment_key']);
            if (!MarketingSegmentEvaluator::isAllowedSegmentKey($sk)) {
                throw new \InvalidArgumentException('Unknown or unsupported segment.');
            }
            $row['segment_key'] = $sk;
        }
        if (array_key_exists('segment_config', $patch)) {
            $row['segment_config_json'] = $this->encodeSegmentConfig(is_array($patch['segment_config']) ? $patch['segment_config'] : []);
        }
        if (isset($patch['subject'])) {
            $s = trim((string) $patch['subject']);
            if ($s === '') {
                throw new \InvalidArgumentException('Subject is required.');
            }
            $row['subject'] = $s;
        }
        if (isset($patch['body_text'])) {
            $b = trim((string) $patch['body_text']);
            if ($b === '') {
                throw new \InvalidArgumentException('Body is required.');
            }
            $row['body_text'] = $b;
        }
        if (isset($patch['status'])) {
            $st = trim((string) $patch['status']);
            if (!in_array($st, self::CAMPAIGN_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid campaign status.');
            }
            $row['status'] = $st;
        }
        if ($row === []) {
            return;
        }
        $row['updated_by'] = $this->currentUserId();
        $this->campaigns->updateInTenantScopeForStaff($id, $row);
        $this->audit->log('marketing_campaign_updated', 'marketing_campaign', $id, $this->currentUserId(), $current['branch_id'] ?? null, array_keys($row));
    }

    /**
     * @return list<array{id: int, first_name: string, last_name: string, email: string}>
     */
    public function previewAudience(int $campaignId): array
    {
        $campaign = $this->campaigns->findInTenantScopeForStaff($campaignId);
        if (!$campaign) {
            throw new \InvalidArgumentException('Campaign not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($campaign['branch_id'] ?? null));

        return $this->segmentEvaluator->resolveEligibleClients(
            (string) ($campaign['segment_key'] ?? ''),
            $this->nullableInt($campaign['branch_id'] ?? null),
            $this->decodeSegmentConfig($campaign['segment_config_json'] ?? null)
        );
    }

    public function freezeRecipientSnapshot(int $campaignId): int
    {
        return (int) $this->transactional(function () use ($campaignId): int {
            $campaign = $this->campaigns->findInTenantScopeForStaff($campaignId);
            if (!$campaign) {
                throw new \InvalidArgumentException('Campaign not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($campaign['branch_id'] ?? null));
            if ((string) ($campaign['status'] ?? '') === 'archived') {
                throw new \DomainException('Cannot run an archived campaign.');
            }
            $branchId = $this->nullableInt($campaign['branch_id'] ?? null);
            $eligible = $this->segmentEvaluator->resolveEligibleClients(
                (string) ($campaign['segment_key'] ?? ''),
                $branchId,
                $this->decodeSegmentConfig($campaign['segment_config_json'] ?? null)
            );
            $runId = $this->runs->insert([
                'campaign_id' => $campaignId,
                'branch_id' => $branchId,
                'status' => 'frozen',
                'recipient_count' => count($eligible),
                'snapshot_at' => date('Y-m-d H:i:s'),
                'completed_at' => null,
                'cancelled_at' => null,
                'created_by' => $this->currentUserId(),
            ]);
            foreach ($eligible as $cl) {
                $this->recipients->insertBatch([[
                    'campaign_run_id' => $runId,
                    'campaign_id' => $campaignId,
                    'client_id' => $cl['id'],
                    'channel' => 'email',
                    'email_snapshot' => $cl['email'],
                    'first_name_snapshot' => $cl['first_name'],
                    'last_name_snapshot' => $cl['last_name'],
                    'delivery_status' => 'pending',
                    'skip_reason' => null,
                    'outbound_message_id' => null,
                ]]);
            }
            if ($eligible === []) {
                $this->runs->updateInTenantScopeForStaff($runId, [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->audit->log('marketing_run_frozen', 'marketing_campaign_run', $runId, $this->currentUserId(), $branchId, [
                'campaign_id' => $campaignId,
                'recipient_count' => count($eligible),
            ]);

            return $runId;
        });
    }

    public function dispatchFrozenRun(int $runId): void
    {
        $runPeek = $this->runs->findInTenantScopeForStaff($runId);
        if (!$runPeek) {
            throw new \InvalidArgumentException('Run not found.');
        }
        if ((string) ($runPeek['status'] ?? '') === 'completed') {
            return;
        }
        $campaign = $this->campaigns->findInTenantScopeForStaff((int) ($runPeek['campaign_id'] ?? 0));
        if (!$campaign) {
            throw new \InvalidArgumentException('Campaign not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($campaign['branch_id'] ?? null));

        $this->transactional(function () use ($runId): void {
            $run = $this->runs->findForUpdateInTenantScopeForStaff($runId);
            if (!$run) {
                throw new \InvalidArgumentException('Run not found.');
            }
            $st = (string) ($run['status'] ?? '');
            if ($st === 'completed') {
                return;
            }
            if ($st === 'cancelled') {
                throw new \DomainException('Run was cancelled.');
            }
            if ($st === 'frozen') {
                $this->runs->updateInTenantScopeForStaff($runId, ['status' => 'dispatching']);
            } elseif ($st !== 'dispatching') {
                throw new \DomainException('Run is not ready for dispatch.');
            }
        });
        $run = $this->runs->findInTenantScopeForStaff($runId);
        if (!$run || (string) ($run['status'] ?? '') !== 'dispatching') {
            return;
        }
        $subjectTpl = (string) ($campaign['subject'] ?? '');
        $bodyTpl = (string) ($campaign['body_text'] ?? '');
        if (trim($subjectTpl) === '' || trim($bodyTpl) === '') {
            $this->runs->updateInTenantScopeForStaff($runId, ['status' => 'frozen']);
            throw new \DomainException('Campaign subject/body missing; cannot dispatch.');
        }
        $branchId = $this->nullableInt($campaign['branch_id'] ?? null);
        $pending = $this->recipients->listPendingForRunInTenantScopeForStaff($runId);
        foreach ($pending as $rec) {
            $this->dispatchOneRecipient($rec, $campaign, $runId, $branchId, $subjectTpl, $bodyTpl);
        }
        $this->runs->updateInTenantScopeForStaff($runId, [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit->log('marketing_run_dispatched', 'marketing_campaign_run', $runId, $this->currentUserId(), $branchId, [
            'campaign_id' => (int) ($campaign['id'] ?? 0),
        ]);
    }

    public function cancelFrozenRun(int $runId): void
    {
        $this->transactional(function () use ($runId): void {
            $run = $this->runs->findForUpdateInTenantScopeForStaff($runId);
            if (!$run) {
                throw new \InvalidArgumentException('Run not found.');
            }
            $campaign = $this->campaigns->findInTenantScopeForStaff((int) ($run['campaign_id'] ?? 0));
            if ($campaign) {
                $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($campaign['branch_id'] ?? null));
            }
            if ((string) ($run['status'] ?? '') !== 'frozen') {
                throw new \DomainException('Only a frozen run (before dispatch) can be cancelled.');
            }
            $this->runs->updateInTenantScopeForStaff($runId, [
                'status' => 'cancelled',
                'cancelled_at' => date('Y-m-d H:i:s'),
            ]);
            $this->recipients->cancelAllPendingForRun($runId);
            $this->audit->log('marketing_run_cancelled', 'marketing_campaign_run', $runId, $this->currentUserId(), $run['branch_id'] ?? null, []);
        });
    }

    /**
     * @param array<string, mixed> $rec
     * @param array<string, mixed> $campaign
     */
    private function dispatchOneRecipient(array $rec, array $campaign, int $runId, ?int $branchId, string $subjectTpl, string $bodyTpl): void
    {
        $this->transactional(function () use ($rec, $campaign, $runId, $branchId, $subjectTpl, $bodyTpl): void {
            $rid = (int) ($rec['id'] ?? 0);
            $locked = $this->recipients->findForUpdateInTenantScopeForStaff($rid);
            if (!$locked || (string) ($locked['delivery_status'] ?? '') !== 'pending') {
                return;
            }
            $first = (string) ($locked['first_name_snapshot'] ?? '');
            $last = (string) ($locked['last_name_snapshot'] ?? '');
            $email = trim((string) ($locked['email_snapshot'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->recipients->updateInTenantScopeForStaff($rid, [
                    'delivery_status' => 'skipped',
                    'skip_reason' => 'invalid_email_snapshot',
                ]);

                return;
            }
            $subject = $this->interpolateTemplate($subjectTpl, $first, $last);
            $body = $this->interpolateTemplate($bodyTpl, $first, $last);
            if (trim($subject) === '') {
                $this->recipients->updateInTenantScopeForStaff($rid, [
                    'delivery_status' => 'skipped',
                    'skip_reason' => 'empty_rendered_subject',
                ]);

                return;
            }
            $campaignId = (int) ($campaign['id'] ?? 0);
            $clientId = (int) ($locked['client_id'] ?? 0);
            try {
                $mid = $this->outboundMarketing->enqueueCampaignRecipientEmail(
                    $rid,
                    $runId,
                    $campaignId,
                    $branchId,
                    $clientId,
                    $email,
                    $subject,
                    $body,
                    [
                        'marketing_campaign_id' => $campaignId,
                        'marketing_campaign_run_id' => $runId,
                        'marketing_campaign_recipient_id' => $rid,
                        'client_id' => $clientId,
                    ]
                );
            } catch (\Throwable $e) {
                $this->recipients->updateInTenantScopeForStaff($rid, [
                    'delivery_status' => 'skipped',
                    'skip_reason' => 'enqueue_failed:' . substr($e->getMessage(), 0, 400),
                ]);

                return;
            }
            $this->recipients->updateInTenantScopeForStaff($rid, [
                'delivery_status' => 'enqueued',
                'outbound_message_id' => $mid,
            ]);
        });
    }

    private function interpolateTemplate(string $tpl, string $first, string $last): string
    {
        return str_replace(
            ['{{first_name}}', '{{last_name}}'],
            [$first, $last],
            $tpl
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function encodeSegmentConfig(array $config): ?string
    {
        if ($config === []) {
            return null;
        }

        return json_encode($config, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSegmentConfig(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }
        $s = is_string($json) ? $json : (string) $json;
        $d = json_decode($s, true);

        return is_array($d) ? $d : [];
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    private function currentUserId(): ?int
    {
        $u = $this->auth->user();

        return isset($u['id']) ? (int) $u['id'] : null;
    }

    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Read model for campaigns index: segment label, outbound-backed sent count, no opens/clicks (not persisted).
     *
     * @param array{branch_id?: int|null, status?: string|null, channel?: string|null, q?: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function listCampaignsForIndex(array $filters, int $limit, int $offset): array
    {
        $rows = $this->campaigns->listForIndexRead($filters, $limit, $offset);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->normalizeCampaignIndexRow($r);
        }

        return $out;
    }

    /**
     * @param array{branch_id?: int|null, status?: string|null, channel?: string|null, q?: string|null} $filters
     */
    public function countCampaignsForIndex(array $filters): int
    {
        return $this->campaigns->countForIndexRead($filters);
    }

    /**
     * @param array<string, mixed> $campaign
     * @param list<array<string, mixed>> $runRows
     * @return array<string, mixed>
     */
    public function campaignShowReadModel(array $campaign, array $runRows): array
    {
        $segmentKey = (string) ($campaign['segment_key'] ?? '');
        $segmentCfg = $this->decodeSegmentConfig($campaign['segment_config_json'] ?? null);
        $delivery = $this->recipients->summarizeByCampaign((int) ($campaign['id'] ?? 0));

        $runTotal = count($runRows);
        $runCompleted = 0;
        $runFrozen = 0;
        $runDispatching = 0;
        $runCancelled = 0;
        foreach ($runRows as $r) {
            $st = (string) ($r['status'] ?? '');
            if ($st === 'completed') {
                $runCompleted++;
            } elseif ($st === 'frozen') {
                $runFrozen++;
            } elseif ($st === 'dispatching') {
                $runDispatching++;
            } elseif ($st === 'cancelled') {
                $runCancelled++;
            }
        }

        return [
            'status_label' => $this->campaignStatusLabel((string) ($campaign['status'] ?? 'draft')),
            'segment_label' => MarketingSegmentEvaluator::segmentLabelForUi($segmentKey),
            'segment_description' => MarketingSegmentEvaluator::segmentDescriptionForUi($segmentKey),
            'segment_config_items' => $this->segmentConfigItemsForUi($segmentKey, $segmentCfg),
            'delivery' => $delivery,
            'runs' => [
                'total' => $runTotal,
                'completed' => $runCompleted,
                'frozen' => $runFrozen,
                'dispatching' => $runDispatching,
                'cancelled' => $runCancelled,
            ],
            'latest_run' => $runRows[0] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCampaignIndexRow(array $row): array
    {
        $key = (string) ($row['segment_key'] ?? '');
        $sent = (int) ($row['index_sent_count'] ?? 0);
        $lastSent = $row['index_last_sent_at'] ?? null;
        $lastSentStr = $lastSent !== null && $lastSent !== '' ? (string) $lastSent : null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'channel' => (string) ($row['channel'] ?? 'email'),
            'status' => (string) ($row['status'] ?? 'draft'),
            'status_label' => $this->campaignStatusLabel((string) ($row['status'] ?? 'draft')),
            'segment_key' => $key,
            'lists_label' => self::segmentAudienceLabel($key),
            'sent_count' => $sent,
            'opens_count' => null,
            'clicks_count' => null,
            'send_date_raw' => $lastSentStr,
            'branch_id' => isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null,
        ];
    }

    private function campaignStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'archived' => 'Archived',
            default => $status,
        };
    }

    private static function segmentAudienceLabel(string $segmentKey): string
    {
        return MarketingSegmentEvaluator::segmentLabelForUi($segmentKey);
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{label: string, value: string}>
     */
    private function segmentConfigItemsForUi(string $segmentKey, array $config): array
    {
        return match ($segmentKey) {
            MarketingSegmentEvaluator::SEGMENT_DORMANT_NO_RECENT_COMPLETED => [[
                'label' => 'Dormant window',
                'value' => (string) max(1, min(3650, (int) ($config['dormant_days'] ?? 90))) . ' days',
            ]],
            MarketingSegmentEvaluator::SEGMENT_BIRTHDAY_UPCOMING => [[
                'label' => 'Birthday lookahead',
                'value' => (string) max(1, min(366, (int) ($config['lookahead_days'] ?? 14))) . ' days',
            ]],
            MarketingSegmentEvaluator::SEGMENT_WAITLIST_ENGAGED_RECENT => [[
                'label' => 'Recent activity window',
                'value' => (string) max(1, min(3650, (int) ($config['recent_days'] ?? 30))) . ' days',
            ]],
            default => [],
        };
    }
}
