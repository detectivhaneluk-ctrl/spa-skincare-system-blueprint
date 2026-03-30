<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Centralized, repo-provable segment resolution. All marketing sends must pass {@see filterMarketingEligible} after segment match.
 *
 * Client predicates use {@see OrganizationRepositoryScope::clientMarketingBranchScopedOrBranchlessTenantMemberClause()} when a campaign
 * branch is set, or {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()} for tenant-wide (null-branch) campaigns.
 * {@code appointment_waitlist} rows use the product-catalog union (branch row **or** org-global-null) for the campaign branch when set.
 */
final class MarketingSegmentEvaluator
{
    public const SEGMENT_MARKETING_OPT_IN_EMAIL = 'marketing_opt_in_email';

    public const SEGMENT_DORMANT_NO_RECENT_COMPLETED = 'dormant_no_recent_completed';

    public const SEGMENT_BIRTHDAY_UPCOMING = 'birthday_upcoming';

    public const SEGMENT_WAITLIST_ENGAGED_RECENT = 'waitlist_engaged_recent';

    /** @var list<string> */
    public const ALLOWED_SEGMENT_KEYS = [
        self::SEGMENT_MARKETING_OPT_IN_EMAIL,
        self::SEGMENT_DORMANT_NO_RECENT_COMPLETED,
        self::SEGMENT_BIRTHDAY_UPCOMING,
        self::SEGMENT_WAITLIST_ENGAGED_RECENT,
    ];

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public static function isAllowedSegmentKey(string $key): bool
    {
        return in_array($key, self::ALLOWED_SEGMENT_KEYS, true);
    }

    /** Short label for admin UI (forms, lists). */
    public static function segmentLabelForUi(string $key): string
    {
        return match ($key) {
            self::SEGMENT_MARKETING_OPT_IN_EMAIL => 'Marketing opt-in',
            self::SEGMENT_DORMANT_NO_RECENT_COMPLETED => 'Dormant (no recent visit)',
            self::SEGMENT_BIRTHDAY_UPCOMING => 'Upcoming birthdays',
            self::SEGMENT_WAITLIST_ENGAGED_RECENT => 'Waitlist (engaged)',
            default => $key !== '' ? $key : '—',
        };
    }

    /** One-line description for campaign composer; empty if unknown. */
    public static function segmentDescriptionForUi(string $key): string
    {
        return match ($key) {
            self::SEGMENT_MARKETING_OPT_IN_EMAIL => 'Clients who opted in to marketing email for the selected branch.',
            self::SEGMENT_DORMANT_NO_RECENT_COMPLETED => 'Clients with no completed appointment within the dormant window (scoped to the campaign branch when a branch is selected).',
            self::SEGMENT_BIRTHDAY_UPCOMING => 'Clients whose birthday falls within the lookahead window.',
            self::SEGMENT_WAITLIST_ENGAGED_RECENT => 'Waitlist entries with activity in the recent window.',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $segmentConfig decoded JSON or empty
     * @return list<array{id: int, first_name: string, last_name: string, email: string}>
     */
    public function resolveEligibleClients(string $segmentKey, ?int $campaignBranchId, array $segmentConfig): array
    {
        if (!self::isAllowedSegmentKey($segmentKey)) {
            return [];
        }
        $ids = $this->rawClientIdsForSegment($segmentKey, $campaignBranchId, $segmentConfig);
        if ($ids === []) {
            return [];
        }
        $clients = $this->loadClientsByIds($ids);
        $out = [];
        foreach ($clients as $c) {
            $row = $this->filterMarketingEligible($c, $campaignBranchId);
            if ($row === null) {
                continue;
            }
            if ($segmentKey === self::SEGMENT_BIRTHDAY_UPCOMING) {
                $lookahead = max(1, min(366, (int) ($segmentConfig['lookahead_days'] ?? 14)));
                $bd = (string) ($c['birth_date'] ?? '');
                if ($bd === '' || !$this->isBirthdayWithinLookahead($bd, $lookahead)) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array{id: int, first_name: string, last_name: string, email: string}|null
     */
    public function filterMarketingEligible(array $clientRow, ?int $campaignBranchId): ?array
    {
        if (!empty($clientRow['deleted_at'])) {
            return null;
        }
        if (!empty($clientRow['merged_into_client_id'])) {
            return null;
        }
        if ((int) ($clientRow['marketing_opt_in'] ?? 0) !== 1) {
            return null;
        }
        if (!$this->clientMatchesCampaignBranch($clientRow, $campaignBranchId)) {
            return null;
        }
        $email = trim((string) ($clientRow['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'id' => (int) $clientRow['id'],
            'first_name' => (string) ($clientRow['first_name'] ?? ''),
            'last_name' => (string) ($clientRow['last_name'] ?? ''),
            'email' => $email,
        ];
    }

    /**
     * @param array<string, mixed> $segmentConfig
     * @return list<int>
     */
    private function rawClientIdsForSegment(string $segmentKey, ?int $campaignBranchId, array $segmentConfig): array
    {
        $tenantC = $this->clientSegmentTenantFragment($campaignBranchId);

        return match ($segmentKey) {
            self::SEGMENT_MARKETING_OPT_IN_EMAIL => $this->fetchIds(
                "SELECT c.id FROM clients c
                 WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
                   AND c.marketing_opt_in = 1
                   {$tenantC['sql']}",
                $tenantC['params']
            ),
            self::SEGMENT_DORMANT_NO_RECENT_COMPLETED => $this->fetchDormantNoRecentCompletedClientIds(
                $tenantC,
                $segmentConfig,
                $campaignBranchId
            ),
            self::SEGMENT_BIRTHDAY_UPCOMING => $this->fetchIds(
                "SELECT c.id FROM clients c
                 WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
                   AND c.birth_date IS NOT NULL
                   {$tenantC['sql']}",
                $tenantC['params']
            ),
            self::SEGMENT_WAITLIST_ENGAGED_RECENT => $this->fetchWaitlistEngagedRecentClientIds(
                $tenantC,
                $segmentConfig,
                $campaignBranchId
            ),
            default => [],
        };
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function clientSegmentTenantFragment(?int $campaignBranchId): array
    {
        if ($campaignBranchId !== null && $campaignBranchId > 0) {
            return $this->orgScope->clientMarketingBranchScopedOrBranchlessTenantMemberClause('c', (int) $campaignBranchId);
        }

        return $this->orgScope->clientProfileOrgMembershipExistsClause('c');
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function waitlistBranchVisibilityParenthetical(?int $campaignBranchId): array
    {
        if ($campaignBranchId !== null && $campaignBranchId > 0) {
            return $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('w', (int) $campaignBranchId);
        }

        return $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('w');
    }

    /**
     * @param array{sql: string, params: list<mixed>} $tenantC
     * @param array<string, mixed> $segmentConfig
     * @return list<int>
     */
    private function fetchWaitlistEngagedRecentClientIds(array $tenantC, array $segmentConfig, ?int $campaignBranchId): array
    {
        $recentDays = max(1, min(3650, (int) ($segmentConfig['recent_days'] ?? 30)));
        $wVis = $this->waitlistBranchVisibilityParenthetical($campaignBranchId);
        $sql = "SELECT DISTINCT c.id FROM clients c
                 INNER JOIN appointment_waitlist w ON w.client_id = c.id
                 WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
                   AND w.client_id IS NOT NULL
                   AND w.updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND w.status IN ('waiting','offered','matched')
                   AND ({$wVis['sql']})
                   {$tenantC['sql']}";

        return $this->fetchIds($sql, array_merge([$recentDays], $wVis['params'], $tenantC['params']));
    }

    /**
     * Dormant = has some appointment history (join {@code a}) but no completed visit in the window (NOT EXISTS {@code a2}).
     * When {@code $campaignBranchId} is set, both appointment legs use strict {@code branch_id = ?} (H-004 marketing alignment).
     * When branch is null, appointment legs are unscoped (legacy/global evaluation — no branch input).
     *
     * @param array{sql: string, params: list<mixed>} $tenantC
     * @param array<string, mixed> $segmentConfig
     * @return list<int>
     */
    private function fetchDormantNoRecentCompletedClientIds(array $tenantC, array $segmentConfig, ?int $campaignBranchId): array
    {
        $dormantDays = max(1, min(3650, (int) ($segmentConfig['dormant_days'] ?? 90)));
        $branchId = $campaignBranchId !== null && $campaignBranchId > 0 ? (int) $campaignBranchId : null;
        $aBranch = '';
        $a2Branch = '';
        $joinBranchParams = [];
        $existsBranchParams = [];
        if ($branchId !== null) {
            $aBranch = ' AND a.branch_id = ?';
            $a2Branch = ' AND a2.branch_id = ?';
            $joinBranchParams = [$branchId];
            $existsBranchParams = [$branchId];
        }

        $sql = "SELECT DISTINCT c.id FROM clients c
                 INNER JOIN appointments a ON a.client_id = c.id AND a.deleted_at IS NULL{$aBranch}
                 WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
                   {$tenantC['sql']}
                   AND NOT EXISTS (
                     SELECT 1 FROM appointments a2
                     WHERE a2.client_id = c.id AND a2.deleted_at IS NULL
                       AND a2.status = 'completed'
                       AND a2.end_at >= DATE_SUB(NOW(), INTERVAL ? DAY){$a2Branch}
                   )";

        $params = array_merge($joinBranchParams, $tenantC['params'], [$dormantDays], $existsBranchParams);

        return $this->fetchIds($sql, $params);
    }

    private function clientMatchesCampaignBranch(array $clientRow, ?int $campaignBranchId): bool
    {
        if ($campaignBranchId === null) {
            return true;
        }
        $cb = $clientRow['branch_id'] ?? null;
        if ($cb === null || $cb === '') {
            return true;
        }

        return (int) $cb === (int) $campaignBranchId;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function loadClientsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($x) => (int) $x > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = "SELECT c.* FROM clients c
             WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
               AND c.id IN ({$placeholders}){$frag['sql']}";

        return $this->db->fetchAll($sql, array_merge($ids, $frag['params']));
    }

    /**
     * @param list<int|float|string> $params
     * @return list<int>
     */
    private function fetchIds(string $sql, array $params): array
    {
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) ($r['id'] ?? 0);
        }

        return array_values(array_unique(array_filter($out, fn ($x) => $x > 0)));
    }

    private function isBirthdayWithinLookahead(string $birthDateYmd, int $lookaheadDays): bool
    {
        try {
            $bd = new \DateTimeImmutable($birthDateYmd . ' 00:00:00');
        } catch (\Exception) {
            return false;
        }
        $today = new \DateTimeImmutable('today');
        $y = (int) $today->format('Y');
        $thisYear = $bd->setDate($y, (int) $bd->format('m'), (int) $bd->format('d'));
        if ($thisYear < $today) {
            $thisYear = $thisYear->modify('+1 year');
        }
        $end = $today->modify('+' . $lookaheadDays . ' days');

        return $thisYear >= $today && $thisYear <= $end;
    }
}
