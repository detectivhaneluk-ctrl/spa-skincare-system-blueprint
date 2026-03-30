<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Marketing\Repositories\MarketingAutomationExecutionRepository;
use Modules\Notifications\Services\OutboundMarketingEnqueueService;

/**
 * Runs automation logic and enqueues outbound email when not dry-run.
 * There is no in-app scheduler: production sends depend on an external job invoking
 * `system/scripts/marketing_automations_execute.php` (per automation key / branch).
 */
final class MarketingAutomationExecutionService
{
    public function __construct(
        private MarketingAutomationService $automationSettings,
        private MarketingAutomationExecutionRepository $executionRepo,
        private MarketingSegmentEvaluator $segmentEvaluator,
        private OutboundMarketingEnqueueService $outboundMarketing,
        private BranchContext $branchContext,
        private BranchDirectory $branchDirectory,
    ) {
    }

    /**
     * @return array{
     *   automation_key: string,
     *   branch_id: int,
     *   dry_run: bool,
     *   enabled: bool,
     *   eligible: int,
     *   skipped_disabled: int,
     *   skipped_duplicate: int,
     *   enqueued: int,
     *   invalid_recipient_data: int
     * }
     */
    public function executeAutomationForBranch(int $branchId, string $automationKey, bool $dryRun = false): array
    {
        $key = $this->assertAllowedKey($automationKey);
        $this->assertExecutableBranch($branchId);
        if (!$this->automationSettings->isStorageReady()) {
            throw new \DomainException(MarketingAutomationService::EXCEPTION_STORAGE_NOT_READY);
        }

        $effective = $this->effectiveSettingByKey($branchId, $key);
        $enabled = !empty($effective['enabled']);
        $summary = [
            'automation_key' => $key,
            'branch_id' => $branchId,
            'dry_run' => $dryRun,
            'enabled' => $enabled,
            'eligible' => 0,
            'skipped_disabled' => 0,
            'skipped_duplicate' => 0,
            'enqueued' => 0,
            'invalid_recipient_data' => 0,
        ];
        if (!$enabled) {
            $summary['skipped_disabled'] = 1;

            return $summary;
        }

        $candidates = $this->eligibleCandidates($branchId, $key, (array) ($effective['config'] ?? []));
        foreach ($candidates as $candidate) {
            $eligible = $this->segmentEvaluator->filterMarketingEligible($candidate, $branchId);
            if ($eligible === null) {
                $summary['invalid_recipient_data']++;
                continue;
            }
            $summary['eligible']++;
            $idempotencyKey = $this->idempotencyKeyForCandidate($key, $branchId, $candidate);
            if ($this->outboundMarketing->hasQueuedMessage($idempotencyKey)) {
                $summary['skipped_duplicate']++;
                continue;
            }
            if ($dryRun) {
                continue;
            }
            $message = $this->messageTemplateForKey($key, $eligible);
            $result = $this->outboundMarketing->enqueueAutomationClientEmail(
                $key,
                $branchId,
                (int) $eligible['id'],
                (string) $eligible['email'],
                $message['subject'],
                $message['body_text'],
                $idempotencyKey,
                [
                    'marketing_automation_key' => $key,
                    'client_id' => (int) $eligible['id'],
                    'idempotency_key' => $idempotencyKey,
                ]
            );
            if (!empty($result['created'])) {
                $summary['enqueued']++;
            } else {
                $summary['skipped_duplicate']++;
            }
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligibleCandidates(int $branchId, string $key, array $config): array
    {
        return match ($key) {
            'reengagement_45_day' => $this->executionRepo->eligibleReengagement(
                $branchId,
                (int) ($config['dormant_days'] ?? 45)
            ),
            'birthday_special' => array_values(array_filter(
                $this->executionRepo->eligibleBirthday($branchId),
                fn (array $r): bool => $this->birthdayInLookahead((string) ($r['birth_date'] ?? ''), (int) ($config['lookahead_days'] ?? 7))
            )),
            'first_time_visitor_welcome' => $this->executionRepo->eligibleFirstTimeVisitorWelcome(
                $branchId,
                (int) ($config['delay_hours'] ?? 24)
            ),
            default => [],
        };
    }

    private function assertAllowedKey(string $automationKey): string
    {
        $key = trim($automationKey);
        if (!isset(MarketingAutomationService::catalog()[$key])) {
            throw new \InvalidArgumentException('Unknown automation key.');
        }

        return $key;
    }

    private function assertExecutableBranch(int $branchId): void
    {
        if ($branchId <= 0) {
            throw new \DomainException('Branch is required.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
        if (!$this->branchDirectory->isActiveBranchId($branchId)) {
            throw new \DomainException('Branch must be active.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveSettingByKey(int $branchId, string $key): array
    {
        $effective = $this->automationSettings->effectiveByBranch($branchId);
        foreach ($effective as $row) {
            if ((string) ($row['automation_key'] ?? '') === $key) {
                return $row;
            }
        }
        throw new \DomainException('Automation setting not found.');
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function idempotencyKeyForCandidate(string $key, int $branchId, array $candidate): string
    {
        $clientId = (int) ($candidate['id'] ?? 0);
        $window = match ($key) {
            'reengagement_45_day' => 'last:' . $this->safeDateChunk((string) ($candidate['last_completed_at'] ?? 'none')),
            'birthday_special' => 'bday:' . $this->birthdayYearWindow((string) ($candidate['birth_date'] ?? '')),
            'first_time_visitor_welcome' => 'first:' . $this->safeDateChunk((string) ($candidate['first_completed_at'] ?? 'none')),
            default => 'unknown',
        };

        return 'email:v1:marketing.auto:' . $key . ':b:' . $branchId . ':c:' . $clientId . ':w:' . $window;
    }

    /**
     * @param array{id:int,first_name:string,last_name:string,email:string} $eligible
     * @return array{subject: string, body_text: string}
     */
    private function messageTemplateForKey(string $key, array $eligible): array
    {
        $first = trim((string) ($eligible['first_name'] ?? ''));
        $greetingName = $first !== '' ? $first : 'there';

        return match ($key) {
            'reengagement_45_day' => [
                'subject' => 'We miss you at the spa',
                'body_text' => "Hi {$greetingName},\n\nIt has been a while since your last visit. We would love to welcome you back.\n\nBook your next appointment when you are ready.\n",
            ],
            'birthday_special' => [
                'subject' => 'A birthday treat just for you',
                'body_text' => "Hi {$greetingName},\n\nYour birthday is coming up and we would love to celebrate with you.\n\nReach out to enjoy your birthday special.\n",
            ],
            'first_time_visitor_welcome' => [
                'subject' => 'Thanks for your first visit',
                'body_text' => "Hi {$greetingName},\n\nThank you for visiting us for the first time. We look forward to seeing you again soon.\n",
            ],
            default => [
                'subject' => 'A message from our spa',
                'body_text' => "Hi {$greetingName},\n\nThank you for being with us.\n",
            ],
        };
    }

    private function birthdayInLookahead(string $birthDateYmd, int $lookaheadDays): bool
    {
        if (trim($birthDateYmd) === '') {
            return false;
        }
        try {
            $bd = new \DateTimeImmutable($birthDateYmd . ' 00:00:00');
        } catch (\Exception) {
            return false;
        }
        $lookahead = max(0, min(60, $lookaheadDays));
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $candidate = $bd->setDate($year, (int) $bd->format('m'), (int) $bd->format('d'));
        if ($candidate < $today) {
            $candidate = $candidate->modify('+1 year');
        }
        $end = $today->modify('+' . $lookahead . ' days');

        return $candidate >= $today && $candidate <= $end;
    }

    private function birthdayYearWindow(string $birthDateYmd): string
    {
        if (trim($birthDateYmd) === '') {
            return 'none';
        }
        try {
            $bd = new \DateTimeImmutable($birthDateYmd . ' 00:00:00');
        } catch (\Exception) {
            return 'none';
        }
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $candidate = $bd->setDate($year, (int) $bd->format('m'), (int) $bd->format('d'));
        if ($candidate < $today) {
            $candidate = $candidate->modify('+1 year');
        }

        return $candidate->format('Y');
    }

    private function safeDateChunk(string $raw): string
    {
        $ts = strtotime($raw);
        if ($ts === false) {
            return 'none';
        }

        return gmdate('YmdHis', $ts);
    }
}

