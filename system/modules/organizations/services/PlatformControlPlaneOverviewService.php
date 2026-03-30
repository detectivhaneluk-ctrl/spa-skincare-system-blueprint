<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\UserAccessShapeService;
use Modules\Organizations\Repositories\PlatformControlPlaneReadRepository;
use Modules\Organizations\Repositories\PlatformFounderAuditReadRepository;

/**
 * Read-only platform control plane home aggregates (FOUNDATION-97).
 */
final class PlatformControlPlaneOverviewService
{
    private const ACCESS_METRICS_CHUNK = 400;

    public function __construct(
        private PlatformControlPlaneReadRepository $reads,
        private UserAccessShapeService $accessShape,
        private PlatformFounderSecurityService $security,
        private PlatformFounderAuditReadRepository $auditReads,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $orgs = $this->reads->countActiveOrganizations();
        $suspended = $this->reads->countSuspendedOrganizations();
        $activeOrganizations = max(0, $orgs - $suspended);
        $branches = $this->reads->countActiveBranches();
        $users = $this->reads->countActiveUsers();
        $staff = $this->reads->countActiveStaffProfiles();
        $appointments = $this->reads->countNonDeletedAppointments();
        $clients = $this->reads->countNonDeletedClients();
        $branchesUnderSuspendedOrgs = $this->reads->countBranchesUnderSuspendedOrganizations();

        $commandCenter = $this->buildCommandCenter($branchesUnderSuspendedOrgs);

        return [
            'header' => [
                'title' => 'Founder dashboard',
                'subtitle' => 'Platform-wide status, access risk signals, and quick paths to operations.',
                'scope_label' => 'Global (all tenants)',
                'timezone' => date_default_timezone_get(),
            ],
            'command_center' => $commandCenter,
            'cards' => [
                [
                    'label' => 'Organizations (active)',
                    'value' => $activeOrganizations,
                    'hint' => 'Active organizations in registry',
                ],
                [
                    'label' => 'Organizations (suspended)',
                    'value' => $suspended,
                    'hint' => 'Suspended organizations',
                ],
                [
                    'label' => 'Organizations (total)',
                    'value' => $orgs,
                    'hint' => 'Non-deleted registry rows',
                ],
                [
                    'label' => 'Platform users',
                    'value' => $users,
                    'hint' => 'All non-deleted user accounts',
                ],
                [
                    'label' => 'Staff profiles',
                    'value' => $staff,
                    'hint' => 'Directory rows (staff table)',
                ],
                [
                    'label' => 'Branches',
                    'value' => $branches,
                    'hint' => 'Non-deleted locations',
                ],
                [
                    'label' => 'Appointments',
                    'value' => $appointments,
                    'hint' => 'All non-deleted rows',
                ],
                [
                    'label' => 'Clients',
                    'value' => $clients,
                    'hint' => 'All non-deleted client records',
                ],
            ],
            'actions' => [
                [
                    'href' => '/platform-admin/guide',
                    'label' => 'Operator guide',
                    'hint' => 'Workflow, scenarios, and which module to open',
                ],
                [
                    'href' => '/platform-admin/incidents',
                    'label' => 'Incident Center',
                    'hint' => 'Grouped operational signals and where to act next',
                ],
                [
                    'href' => '/platform-admin/access',
                    'label' => 'Access',
                    'hint' => 'Scan accounts and open user actions',
                ],
                [
                    'href' => '/platform-admin/access/provision',
                    'label' => 'Provision users',
                    'hint' => 'Create tenant admin or reception logins',
                ],
                [
                    'href' => '/platform-admin/branches',
                    'label' => 'Branches',
                    'hint' => 'Global branch catalog and org linkage',
                ],
                [
                    'href' => '/platform-admin/security',
                    'label' => 'Security',
                    'hint' => 'Public kill switches and access audit',
                ],
                [
                    'href' => '/platform-admin/salons',
                    'label' => 'Organizations',
                    'hint' => 'Registry list and lifecycle',
                ],
            ],
            'recent_organizations' => $this->normalizeRecentOrgs($this->reads->listRecentOrganizations(5)),
        ];
    }

    /**
     * @return array{
     *   access_accounts_evaluated:int,
     *   access_shape_counts:array<string,int>,
     *   access_eval_errors:int,
     *   branches_under_suspended_orgs:int,
     *   kill_switches:array{kill_online_booking:bool,kill_anonymous_public_apis:bool,kill_public_commerce:bool},
     *   recent_actions:list<array<string,mixed>>
     * }
     */
    private function buildCommandCenter(int $branchesUnderSuspendedOrgs): array
    {
        $counts = array_fill_keys(UserAccessShapeService::ACCESS_SHAPE_CANONICAL_STATES, 0);
        $evalErrors = 0;
        $evaluated = 0;
        $afterId = 0;
        $chunk = self::ACCESS_METRICS_CHUNK;
        while (true) {
            $ids = $this->reads->listActiveUserIdsAfterId($afterId, $chunk);
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
                if (array_key_exists($c, $counts)) {
                    $counts[$c]++;
                }
            }
            $evaluated += count($ids);
            $afterId = max($ids);
        }

        return [
            'access_accounts_evaluated' => $evaluated,
            'access_shape_counts' => $counts,
            'access_eval_errors' => $evalErrors,
            'branches_under_suspended_orgs' => $branchesUnderSuspendedOrgs,
            'kill_switches' => $this->security->getPublicSurfaceKillSwitchState(),
            'recent_actions' => $this->auditReads->listAccessPlaneEvents(8),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int|string,name:string,created_display:string}>
     */
    private function normalizeRecentOrgs(array $rows): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $out = [];
        foreach ($rows as $r) {
            $raw = trim((string) ($r['created_at'] ?? ''));
            $display = $raw;
            if ($raw !== '') {
                try {
                    $dt = new \DateTimeImmutable($raw, $tz);
                    $display = $dt->format('Y-m-d H:i');
                } catch (\Throwable) {
                }
            }
            $out[] = [
                'id' => $r['id'] ?? 0,
                'name' => (string) ($r['name'] ?? ''),
                'created_display' => $display,
            ];
        }

        return $out;
    }
}
