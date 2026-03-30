<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository;

/**
 * Founder exceptions inbox (/platform-admin/problems): one row per salon, top issue first,
 * same diagnostic truth as {@see PlatformSalonProblemsService} + {@see PlatformSalonAdminAccessService}.
 */
final class PlatformFounderIssuesInboxService
{
    public function __construct(
        private PlatformSalonRegistryReadRepository $salonReads,
        private PlatformSalonProblemsService $problems,
        private PlatformSalonAdminAccessService $adminAccess,
    ) {
    }

    /**
     * @return array{
     *   summary: array{
     *     salons_needing_attention:int,
     *     high_priority_salons:int,
     *     salons_with_access_issues:int,
     *     salons_with_operations_issues:int
     *   },
     *   items: list<array{
     *     salon_id:int,
     *     salon_name:string,
     *     top_issue_key:string,
     *     title:string,
     *     summary:string,
     *     severity:string,
     *     category_display:string,
     *     action_label:string,
     *     action_url:string,
     *     target_section:string,
     *     more_issue_count:int,
     *     sort_weight:int,
     *     triage_rank:int,
     *     bucket_order:int
     *   }>,
     *   filters: array{q:string, filter:string, severity:string},
     *   is_empty: bool,
     *   filter_no_match: bool
     * }
     */
    public function build(string $searchQuery, string $bucketFilter, ?string $severityFilter): array
    {
        $q = trim($searchQuery);
        $bucket = $this->normalizeBucket($bucketFilter);
        $sevF = $this->normalizeSeverityFilter($severityFilter);

        $orgs = $this->salonReads->listOrganizationsFiltered($q !== '' ? $q : null, 'all');
        $ids = [];
        foreach ($orgs as $org) {
            $oid = (int) ($org['id'] ?? 0);
            if ($oid > 0) {
                $ids[] = $oid;
            }
        }
        $branchCounts = $this->salonReads->countBranchesByOrganizationIds($ids);
        $admins = $this->salonReads->batchPrimaryAdminForOrganizations($ids);

        /** @var array<int, list<array<string, mixed>>> $grouped */
        $grouped = [];
        foreach ($orgs as $org) {
            $oid = (int) ($org['id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            $bc = (int) ($branchCounts[$oid] ?? 0);
            $primary = $admins[$oid] ?? null;
            $resolved = $this->adminAccess->resolve($primary);
            $shape = $resolved['shape'] ?? null;
            $rawList = $this->problems->buildProblems($org, $bc, $primary, is_array($shape) ? $shape : null, $oid);
            $salonName = (string) ($org['name'] ?? '');
            foreach ($rawList as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $row = $this->mapIssueRow($oid, $salonName, $raw);
                if ($row !== null) {
                    $grouped[$oid] ??= [];
                    $grouped[$oid][] = $row;
                }
            }
        }

        $inboxItems = [];
        foreach ($grouped as $salonId => $issues) {
            if ($issues === []) {
                continue;
            }
            usort($issues, static function (array $a, array $b): int {
                return [
                    $a['sort_weight'],
                    $a['bucket_order'],
                    $a['triage_rank'],
                    (string) ($a['issue_key'] ?? ''),
                ] <=> [
                    $b['sort_weight'],
                    $b['bucket_order'],
                    $b['triage_rank'],
                    (string) ($b['issue_key'] ?? ''),
                ];
            });
            $top = $issues[0];
            $more = count($issues) - 1;
            $inboxItems[] = [
                'salon_id' => $salonId,
                'salon_name' => (string) ($top['salon_name'] ?? ''),
                'top_issue_key' => (string) ($top['issue_key'] ?? ''),
                'title' => (string) ($top['title'] ?? ''),
                'summary' => (string) ($top['summary'] ?? ''),
                'severity' => (string) ($top['severity'] ?? 'medium'),
                'category_display' => (string) ($top['category_display'] ?? ''),
                'action_label' => (string) ($top['action_label'] ?? ''),
                'action_url' => (string) ($top['action_url'] ?? '#'),
                'target_section' => (string) ($top['target_section'] ?? ''),
                'more_issue_count' => max(0, $more),
                'sort_weight' => (int) ($top['sort_weight'] ?? 3),
                'triage_rank' => (int) ($top['triage_rank'] ?? 9),
                'bucket_order' => (int) ($top['bucket_order'] ?? 1),
                '_has_access' => $this->stackHasBucket($issues, 'access'),
                '_has_operations' => $this->stackHasBucket($issues, 'operations'),
            ];
        }

        usort($inboxItems, static function (array $a, array $b): int {
            return [
                $a['sort_weight'],
                $a['bucket_order'],
                $a['triage_rank'],
                $a['salon_id'],
            ] <=> [
                $b['sort_weight'],
                $b['bucket_order'],
                $b['triage_rank'],
                $b['salon_id'],
            ];
        });

        $summary = $this->buildSummary($inboxItems);

        $displayed = [];
        foreach ($inboxItems as $item) {
            if ($bucket === 'high') {
                $s = (string) ($item['severity'] ?? '');
                if ($s !== 'high' && $s !== FounderIncidentSeverity::CRITICAL) {
                    continue;
                }
            } elseif ($bucket === 'access' && empty($item['_has_access'])) {
                continue;
            } elseif ($bucket === 'operations' && empty($item['_has_operations'])) {
                continue;
            }
            if ($sevF !== '' && (string) ($item['severity'] ?? '') !== $sevF) {
                continue;
            }
            unset($item['_has_access'], $item['_has_operations']);
            $displayed[] = $item;
        }

        $isEmpty = $inboxItems === [];
        $filterNoMatch = !$isEmpty && $displayed === [];

        return [
            'summary' => $summary,
            'items' => $displayed,
            'filters' => [
                'q' => $q,
                'filter' => $bucket,
                'severity' => $sevF,
            ],
            'is_empty' => $isEmpty,
            'filter_no_match' => $filterNoMatch,
        ];
    }

    /**
     * @param list<array<string, mixed>> $issues
     */
    private function stackHasBucket(array $issues, string $bucket): bool
    {
        foreach ($issues as $row) {
            if (($row['issue_bucket'] ?? '') === $bucket) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array{salons_needing_attention:int, high_priority_salons:int, salons_with_access_issues:int, salons_with_operations_issues:int}
     */
    private function buildSummary(array $items): array
    {
        $high = 0;
        $access = 0;
        $ops = 0;
        foreach ($items as $item) {
            $s = (string) ($item['severity'] ?? '');
            if ($s === 'high' || $s === FounderIncidentSeverity::CRITICAL) {
                $high++;
            }
            if (!empty($item['_has_access'])) {
                $access++;
            }
            if (!empty($item['_has_operations'])) {
                $ops++;
            }
        }

        return [
            'salons_needing_attention' => count($items),
            'high_priority_salons' => $high,
            'salons_with_access_issues' => $access,
            'salons_with_operations_issues' => $ops,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function mapIssueRow(int $salonId, string $salonName, array $raw): ?array
    {
        $key = (string) ($raw['issue_key'] ?? '');
        if ($key === '') {
            return null;
        }
        $sev = (string) ($raw['severity'] ?? 'medium');
        $title = (string) ($raw['title'] ?? '');
        $summary = trim((string) ($raw['detail'] ?? ''));
        $bucket = $this->issueBucket($key);
        $categoryDisplay = $bucket === 'access' ? 'Access' : 'Operations';
        $bucketOrder = $bucket === 'operations' ? 0 : 1;

        if ($key === 'archived') {
            $actionUrl = '/platform-admin/access';
            $actionLabel = 'Open registry';
            $targetSection = 'access';
        } else {
            $fragment = match ($key) {
                'suspended' => '#salon-management',
                'no_branch' => '#branches',
                default => '#admin-access',
            };
            $actionUrl = '/platform-admin/salons/' . $salonId . $fragment;
            $actionLabel = match ($key) {
                'suspended' => 'Review reactivation',
                'no_branch' => 'Open Branches',
                'no_admin' => 'Open Admin Access',
                'admin_login_off' => 'Open Admin Access',
                'access_mismatch' => 'Review account',
                'tenant_path_blocked' => 'Complete setup',
                'tenant_suspended_binding' => 'Review account',
                default => 'Open Admin Access',
            };
            $targetSection = ltrim($fragment, '#');
        }

        $sortWeight = match ($sev) {
            FounderIncidentSeverity::CRITICAL => 0,
            'high' => 1,
            'medium' => 2,
            default => 3,
        };

        return [
            'salon_id' => $salonId,
            'salon_name' => $salonName,
            'issue_key' => $key,
            'title' => $title,
            'summary' => $summary,
            'severity' => $sev,
            'category_display' => $categoryDisplay,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'target_section' => $targetSection,
            'sort_weight' => $sortWeight,
            'triage_rank' => $this->issueTriageRank($key),
            'bucket_order' => $bucketOrder,
            'issue_bucket' => $bucket,
        ];
    }

    private function issueBucket(string $issueKey): string
    {
        return match ($issueKey) {
            'no_admin', 'admin_login_off', 'access_mismatch', 'tenant_path_blocked', 'tenant_suspended_binding' => 'access',
            default => 'operations',
        };
    }

    private function issueTriageRank(string $issueKey): int
    {
        return match ($issueKey) {
            'suspended' => 0,
            'archived' => 1,
            'no_branch' => 2,
            'admin_login_off' => 3,
            'no_admin' => 4,
            'access_mismatch' => 5,
            'tenant_path_blocked' => 6,
            'tenant_suspended_binding' => 7,
            default => 9,
        };
    }

    private function normalizeBucket(string $f): string
    {
        $f = strtolower(trim($f));

        return in_array($f, ['all', 'high', 'access', 'operations'], true) ? $f : 'all';
    }

    private function normalizeSeverityFilter(?string $s): string
    {
        if ($s === null || trim($s) === '') {
            return '';
        }
        $s = strtolower(trim($s));
        $ok = [FounderIncidentSeverity::CRITICAL, 'high', 'medium', 'low'];

        return in_array($s, $ok, true) ? $s : '';
    }
}
