<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\UserAccessShapeService;
use Modules\Organizations\Repositories\PlatformControlPlaneReadRepository;

/**
 * Aggregates provable operational incidents for the founder Incident Center (identify + route; no repairs here).
 * FOUNDER-OPS-INCIDENT-CENTER-FOUNDATION-01.
 */
final class FounderIncidentCenterService
{
    private const CHUNK = 400;

    public function __construct(
        private PlatformControlPlaneReadRepository $reads,
        private UserAccessShapeService $accessShape,
        private PlatformFounderSecurityService $security,
        private FounderIncidentImpactExplainer $incidentImpactExplainer,
    ) {
    }

    /**
     * @return array{
     *   header:array{title:string,subtitle:string},
     *   category_cards:list<array{category:string,label:string,active_incidents:int,max_severity:string,summary:string}>,
     *   incidents:list<array<string,mixed>>,
     *   totals:array{active_incident_rows:int,accounts_evaluated:int,access_eval_errors:int},
     *   filters:array{category:string,severity:string},
     *   is_all_clear:bool,
     *   filter_empty:bool
     * }
     */
    public function build(?string $categoryFilter, ?string $severityFilter): array
    {
        $categoryFilter = $this->normalizeCategory($categoryFilter);
        $severityFilter = $this->normalizeSeverity($severityFilter);

        $presenter = new FounderIncidentPresenter();

        $shapeCounts = array_fill_keys(UserAccessShapeService::ACCESS_SHAPE_CANONICAL_STATES, 0);
        $evalErrors = 0;
        $evaluated = 0;
        $founderContradictions = 0;
        $afterId = 0;

        while (true) {
            $ids = $this->reads->listActiveUserIdsAfterId($afterId, self::CHUNK);
            if ($ids === []) {
                break;
            }
            $shapes = $this->accessShape->evaluateForUserIds($ids);
            foreach ($shapes as $sh) {
                if (!empty($sh['error'])) {
                    $evalErrors++;
                    continue;
                }
                $c = (string) ($sh['canonical_state'] ?? '');
                if (array_key_exists($c, $shapeCounts)) {
                    $shapeCounts[$c]++;
                }
                $isPlatform = !empty($sh['is_platform_principal']);
                $contr = $sh['contradictions'] ?? [];
                if ($isPlatform && is_array($contr) && $contr !== []) {
                    $founderContradictions++;
                }
            }
            $evaluated += count($ids);
            $afterId = max($ids);
        }

        $orphan = (int) ($shapeCounts['tenant_orphan_blocked'] ?? 0);
        $suspendedBinding = (int) ($shapeCounts['tenant_suspended_organization'] ?? 0);
        $deactivated = (int) ($shapeCounts['deactivated'] ?? 0);

        $suspendedOrgs = $this->reads->countSuspendedOrganizations();
        $branchesUnderSuspended = $this->reads->countBranchesUnderSuspendedOrganizations();
        $branchesDeletedOrg = $this->reads->countActiveBranchesLinkedToDeletedOrganizations();

        $kill = $this->security->getPublicSurfaceKillSwitchState();
        $killOn = 0;
        foreach (['kill_online_booking', 'kill_anonymous_public_apis', 'kill_public_commerce'] as $k) {
            if (!empty($kill[$k])) {
                $killOn++;
            }
        }

        $allRows = $this->buildIncidentRows(
            $evalErrors,
            $orphan,
            $suspendedBinding,
            $deactivated,
            $founderContradictions,
            $suspendedOrgs,
            $branchesUnderSuspended,
            $branchesDeletedOrg,
            $killOn
        );

        $activeRows = array_values(array_filter(
            $allRows,
            static fn (array $r) => ((int) ($r['affected_count'] ?? 0)) > 0
        ));
        $activeRows = array_map(fn (array $r) => $this->enrichIncidentRow($r), $activeRows);

        $categoryCards = $this->buildCategoryCards($activeRows, $presenter);

        $filtered = [];
        foreach ($activeRows as $row) {
            if ($categoryFilter !== '' && ($row['category'] ?? '') !== $categoryFilter) {
                continue;
            }
            if ($severityFilter !== '' && ($row['severity'] ?? '') !== $severityFilter) {
                continue;
            }
            $filtered[] = $row;
        }

        return [
            'header' => [
                'title' => 'Incident Center',
                'subtitle' => 'Identify and route — not repair here. Each row states cause, blast radius, and the first place to investigate. Root-cause rows (often Organizations) are labeled separately from downstream access effects.',
            ],
            'category_cards' => $categoryCards,
            'incidents' => $filtered,
            'totals' => [
                'active_incident_rows' => count($activeRows),
                'accounts_evaluated' => $evaluated,
                'access_eval_errors' => $evalErrors,
            ],
            'filters' => [
                'category' => $categoryFilter,
                'severity' => $severityFilter,
            ],
            'is_all_clear' => $activeRows === [],
            'filter_empty' => $filtered === [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{category:string,label:string,active_incidents:int,max_severity:string,summary:string}>
     */
    private function buildCategoryCards(array $rows, FounderIncidentPresenter $presenter): array
    {
        $cats = ['access', 'organization_branch', 'public_surface', 'data_health'];
        $out = [];
        foreach ($cats as $cat) {
            $active = 0;
            $severities = [];
            $parts = [];
            foreach ($rows as $r) {
                if (($r['category'] ?? '') !== $cat) {
                    continue;
                }
                $n = (int) ($r['affected_count'] ?? 0);
                if ($n > 0) {
                    $active++;
                    $severities[] = (string) ($r['severity'] ?? FounderIncidentSeverity::LOW);
                    $parts[] = (string) ($r['title'] ?? '');
                }
            }
            $summary = $active === 0
                ? 'No active signals in this category.'
                : implode('; ', array_slice($parts, 0, 3)) . ($active > 3 ? '…' : '');

            $out[] = [
                'category' => $cat,
                'label' => $presenter->categoryLabel($cat),
                'active_incidents' => $active,
                'max_severity' => $severities === [] ? FounderIncidentSeverity::LOW : FounderIncidentSeverity::maxOf($severities),
                'summary' => $summary,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildIncidentRows(
        int $evalErrors,
        int $orphan,
        int $suspendedBinding,
        int $deactivated,
        int $founderContradictions,
        int $suspendedOrgs,
        int $branchesUnderSuspended,
        int $branchesDeletedOrg,
        int $killOn,
    ): array {
        $rows = [];

        $rows[] = [
            'id' => 'access_eval_errors',
            'category' => 'access',
            'title' => 'Access-shape evaluation failures',
            'severity' => $evalErrors > 0 ? FounderIncidentSeverity::CRITICAL : FounderIncidentSeverity::LOW,
            'affected_count' => $evalErrors,
            'cause_summary' => 'Some login accounts could not be evaluated; canonical access state is unknown for those rows.',
            'recommended_next_step' => 'Investigate affected users in Access and Diagnostics until errors clear.',
            'open_url' => '/platform-admin/access',
            'open_label' => 'Open Access',
        ];

        $rows[] = [
            'id' => 'access_orphan_blocked',
            'category' => 'access',
            'title' => 'Tenant logins with no usable organization or branch access',
            'severity' => $orphan > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $orphan,
            'cause_summary' => 'Access-shape engine reports tenant_orphan_blocked: no valid membership path to a usable branch.',
            'recommended_next_step' => 'Open Access filtered to blocked accounts; assign membership and consistent branch context per user.',
            'open_url' => '/platform-admin/access?shape=tenant_orphan_blocked',
            'open_label' => 'Open Access (blocked)',
        ];

        $rows[] = [
            'id' => 'access_suspended_org_binding',
            'category' => 'access',
            'title' => 'Tenant logins blocked by suspended organization binding',
            'severity' => $suspendedBinding > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $suspendedBinding,
            'cause_summary' => 'Users are tied to a suspended organization via pin or active membership on a suspended org.',
            'recommended_next_step' => 'Review organization status and user bindings; reactivate org or move membership in Access.',
            'open_url' => '/platform-admin/access?shape=tenant_suspended_organization',
            'open_label' => 'Open Access (suspended org)',
        ];

        $rows[] = [
            'id' => 'access_deactivated_accounts',
            'category' => 'access',
            'title' => 'Deactivated login accounts',
            'severity' => $deactivated > 0 ? FounderIncidentSeverity::LOW : FounderIncidentSeverity::LOW,
            'affected_count' => $deactivated,
            'cause_summary' => 'Soft-deleted users remain in the directory; they cannot authenticate.',
            'recommended_next_step' => 'Use Access if an account was deactivated by mistake and should be restored.',
            'open_url' => '/platform-admin/access?shape=deactivated',
            'open_label' => 'Open Access (deactivated)',
        ];

        $rows[] = [
            'id' => 'access_founder_contradictions',
            'category' => 'access',
            'title' => 'Platform principal access-shape conflicts',
            'severity' => $founderContradictions > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $founderContradictions,
            'cause_summary' => 'Founder accounts with extra tenant roles and/or usable tenant branches — contradicts control-plane boundary rules.',
            'recommended_next_step' => 'Open each affected user in Access; canonicalize platform principal or remove conflicting tenant roles.',
            'open_url' => '/platform-admin/access',
            'open_label' => 'Open Access',
        ];

        $rows[] = [
            'id' => 'org_branch_suspended_orgs',
            'category' => 'organization_branch',
            'title' => 'Suspended organizations (registry)',
            'severity' => $suspendedOrgs > 0 ? FounderIncidentSeverity::MEDIUM : FounderIncidentSeverity::LOW,
            'affected_count' => $suspendedOrgs,
            'cause_summary' => 'Organizations with suspended_at set; tenant operations for these orgs are frozen by policy.',
            'recommended_next_step' => 'Review lifecycle and reactivation in Organizations when appropriate.',
            'open_url' => '/platform-admin/salons',
            'open_label' => 'Open Organizations',
        ];

        $rows[] = [
            'id' => 'org_branch_branches_under_suspended',
            'category' => 'organization_branch',
            'title' => 'Branches under suspended organizations',
            'severity' => $branchesUnderSuspended > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $branchesUnderSuspended,
            'cause_summary' => 'Active branch rows whose organization is suspended — locations are not operable under current org state.',
            'recommended_next_step' => 'Confirm org suspension intent; reactivate organization or adjust branch catalog as needed.',
            'open_url' => '/platform-admin/branches',
            'open_label' => 'Open Branches',
        ];

        $rows[] = [
            'id' => 'org_branch_deleted_org_links',
            'category' => 'organization_branch',
            'title' => 'Branches linked to soft-deleted organizations',
            'severity' => $branchesDeletedOrg > 0 ? FounderIncidentSeverity::CRITICAL : FounderIncidentSeverity::LOW,
            'affected_count' => $branchesDeletedOrg,
            'cause_summary' => 'Non-deleted branch rows pointing at organization rows with deleted_at set (integrity risk).',
            'recommended_next_step' => 'Inspect branch and organization records; align data with registry rules.',
            'open_url' => '/platform-admin/branches',
            'open_label' => 'Open Branches',
        ];

        $rows[] = [
            'id' => 'public_kill_switches',
            'category' => 'public_surface',
            'title' => 'Public kill switches enabled',
            'severity' => $killOn > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $killOn,
            'cause_summary' => 'Deployment-wide emergency stops for anonymous/public traffic are active (count of enabled switches).',
            'recommended_next_step' => 'Review Security to confirm intentional exposure posture.',
            'open_url' => '/platform-admin/security',
            'open_label' => 'Open Security',
        ];

        $rows[] = [
            'id' => 'data_health_orphan_accounts',
            'category' => 'data_health',
            'title' => 'Orphan tenant accounts (no usable access path)',
            'severity' => $orphan > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $orphan,
            'cause_summary' => 'Same signal as access-shape tenant_orphan_blocked: directory users without a consistent tenant entry path.',
            'recommended_next_step' => 'Repair memberships and branch pins from Access; verify organization registry alignment.',
            'open_url' => '/platform-admin/access?shape=tenant_orphan_blocked',
            'open_label' => 'Open Access (blocked)',
        ];

        $rows[] = [
            'id' => 'data_health_contradictions',
            'category' => 'data_health',
            'title' => 'Access-shape contradiction candidates',
            'severity' => $founderContradictions > 0 ? FounderIncidentSeverity::HIGH : FounderIncidentSeverity::LOW,
            'affected_count' => $founderContradictions,
            'cause_summary' => 'Platform principals with tenant-plane contradictions detected by the access-shape engine.',
            'recommended_next_step' => 'Resolve in Access using diagnostics output; Security audit may supplement review.',
            'open_url' => '/platform-admin/access',
            'open_label' => 'Open Access',
        ];

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function enrichIncidentRow(array $row): array
    {
        return $this->incidentImpactExplainer->enrich($this->withImpactExplain($row));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withImpactExplain(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        $links = [];
        $impact = '';

        switch ($id) {
            case 'access_eval_errors':
                $impact = 'Operators cannot trust access filters until evaluation succeeds; wrong fixes may be applied.';
                $links = [
                    ['label' => 'Access', 'url' => '/platform-admin/access'],
                    ['label' => 'Security (audit)', 'url' => '/platform-admin/security'],
                ];
                break;
            case 'access_orphan_blocked':
                $impact = 'Affected users cannot reach tenant dashboard until membership and branch paths align.';
                $links = [
                    ['label' => 'Access (blocked filter)', 'url' => '/platform-admin/access?shape=tenant_orphan_blocked'],
                    ['label' => 'Organizations', 'url' => '/platform-admin/salons'],
                ];
                break;
            case 'access_suspended_org_binding':
                $impact = 'Tenant entry stays blocked until the organization is reactivated or memberships move.';
                $links = [
                    ['label' => 'Access (suspended org)', 'url' => '/platform-admin/access?shape=tenant_suspended_organization'],
                    ['label' => 'Organizations', 'url' => '/platform-admin/salons'],
                ];
                break;
            case 'access_deactivated_accounts':
                $impact = 'Those accounts cannot sign in; restoring access requires activation, not branch edits alone.';
                $links = [
                    ['label' => 'Access (deactivated)', 'url' => '/platform-admin/access?shape=deactivated'],
                ];
                break;
            case 'access_founder_contradictions':
                $impact = 'Mixed founder/tenant planes create privilege ambiguity until roles are canonicalized.';
                $links = [
                    ['label' => 'Access', 'url' => '/platform-admin/access'],
                    ['label' => 'Security', 'url' => '/platform-admin/security'],
                ];
                break;
            case 'org_branch_suspended_orgs':
                $impact = 'Each suspended org blocks tenant operations for its branches and tied memberships.';
                $links = [
                    ['label' => 'Organizations (pick one)', 'url' => '/platform-admin/salons'],
                    ['label' => 'Branches', 'url' => '/platform-admin/branches'],
                ];
                break;
            case 'org_branch_branches_under_suspended':
                $impact = 'Locations remain in the catalog but cannot run normal tenant workflows under a suspended org.';
                $links = [
                    ['label' => 'Branches', 'url' => '/platform-admin/branches'],
                    ['label' => 'Organizations', 'url' => '/platform-admin/salons'],
                ];
                break;
            case 'org_branch_deleted_org_links':
                $impact = 'Data integrity risk: branches should not point at deleted organizations — routing may break.';
                $links = [
                    ['label' => 'Branches', 'url' => '/platform-admin/branches'],
                    ['label' => 'Organizations', 'url' => '/platform-admin/salons'],
                ];
                break;
            case 'public_kill_switches':
                $impact = 'Anonymous/public traffic paths are altered deployment-wide (not per tenant).';
                $links = [
                    ['label' => 'Security', 'url' => '/platform-admin/security'],
                ];
                break;
            case 'data_health_orphan_accounts':
                $impact = 'Directory users exist without a consistent tenant entry — operations and reporting may skew.';
                $links = [
                    ['label' => 'Access (blocked)', 'url' => '/platform-admin/access?shape=tenant_orphan_blocked'],
                    ['label' => 'Organizations', 'url' => '/platform-admin/salons'],
                ];
                break;
            case 'data_health_contradictions':
                $impact = 'Platform principals should not carry tenant usable branches — security and routing conflict.';
                $links = [
                    ['label' => 'Access', 'url' => '/platform-admin/access'],
                    ['label' => 'Security', 'url' => '/platform-admin/security'],
                ];
                break;
            default:
                $impact = '';
                $links = [];
        }

        $row['impact_line'] = $impact;
        $row['context_links'] = $links;

        return $row;
    }

    private function normalizeCategory(?string $v): string
    {
        $v = trim((string) $v);
        $ok = ['', 'access', 'organization_branch', 'public_surface', 'data_health'];

        return in_array($v, $ok, true) ? $v : '';
    }

    private function normalizeSeverity(?string $v): string
    {
        $v = trim((string) $v);
        $ok = array_merge([''], FounderIncidentSeverity::all());

        return in_array($v, $ok, true) ? $v : '';
    }
}
