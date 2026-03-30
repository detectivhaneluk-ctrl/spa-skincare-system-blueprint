<?php

declare(strict_types=1);

namespace Modules\Dashboard\Services;

use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Dashboard\Repositories\DashboardReadRepository;

/**
 * Read-only tenant/salon operator dashboard: branch-scoped snapshot + operational list.
 * Not the platform founder control plane ({@see \Modules\Organizations\Services\PlatformControlPlaneOverviewService}).
 */
final class TenantOperatorDashboardService
{
    private const STARTING_SOON_MINUTES = 60;

    public function __construct(
        private DashboardSnapshotService $snapshot,
        private DashboardShellSummaryService $shellSummary,
        private DashboardReadRepository $reads,
        private BranchContext $branchContext,
        private BranchDirectory $branchDirectory
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $userId): array
    {
        $snap = $this->snapshot->buildSnapshot($userId);
        $shell = $this->shellSummary->build();

        $branchId = $this->branchContext->getCurrentBranchId();
        $tz = new \DateTimeZone(date_default_timezone_get());
        $now = new \DateTimeImmutable('now', $tz);
        $soonEnd = $now->modify('+' . self::STARTING_SOON_MINUTES . ' minutes');
        $nowStr = $now->format('Y-m-d H:i:s');
        $soonEndStr = $soonEnd->format('Y-m-d H:i:s');

        $startingSoon = $this->reads->countAppointmentsStartingSoon($branchId, $nowStr, $soonEndStr);
        $rawUpcoming = $this->reads->listUpcomingAppointmentsForDashboard($branchId, $nowStr, 10);

        $apptToday = $snap['appointments_today'] ?? [];
        $apptSchedule = $snap['appointments_schedule'] ?? [];
        $wait = $snap['waitlist'] ?? [];

        $cancelledToday = (int) ($apptToday['cancelled'] ?? 0);
        $noShowToday = (int) ($apptToday['no_show'] ?? 0);
        $pastStartOpen = (int) ($apptSchedule['past_start_open'] ?? 0);
        $waiting = (int) ($wait['waiting'] ?? 0);
        $offered = (int) ($wait['offered'] ?? 0);
        $matched = (int) ($wait['matched'] ?? 0);

        $attention = $this->buildAttentionLines(
            $pastStartOpen,
            $startingSoon,
            $cancelledToday,
            $noShowToday,
            $waiting,
            $matched,
            $offered
        );

        $showBranchColumn = $branchId === null;

        return [
            'header' => [
                'title' => 'Dashboard',
                'subtitle' => 'Salon workspace snapshot: today’s schedule, waitlist, and roster context. Read-only.',
                'scope_label' => $this->resolveScopeLabel($branchId),
                'timezone' => date_default_timezone_get(),
            ],
            'cards' => [
                [
                    'label' => 'Today’s appointments',
                    'value' => (int) ($apptToday['total'] ?? 0),
                    'hint' => 'Starts today (local calendar day)',
                ],
                [
                    'label' => 'Upcoming today',
                    'value' => (int) ($apptToday['active_upcoming'] ?? 0),
                    'hint' => 'Scheduled, confirmed, or in progress (still active today)',
                ],
                [
                    'label' => 'Completed today',
                    'value' => (int) ($apptToday['completed'] ?? 0),
                    'hint' => null,
                ],
                [
                    'label' => 'Cancelled / no-show today',
                    'value' => $cancelledToday + $noShowToday,
                    'hint' => 'Cancelled: ' . $cancelledToday . ' · No-show: ' . $noShowToday,
                ],
                [
                    'label' => 'Waitlist (open)',
                    'value' => (int) ($wait['open_pipeline_waiting_or_matched'] ?? 0),
                    'hint' => $offered > 0 ? ('Offered pending: ' . $offered) : 'Waiting + matched pipeline',
                ],
                [
                    'label' => 'Clients',
                    'value' => (int) ($shell['counts']['clients'] ?? 0),
                    'hint' => 'Same scope as Clients list',
                ],
            ],
            'attention' => $attention,
            'upcoming' => $this->normalizeUpcomingRows($rawUpcoming, $tz, $now, $showBranchColumn),
            'show_branch_column' => $showBranchColumn,
            'quick_links' => [
                ['href' => '/appointments/calendar/day', 'label' => 'Appointments', 'hint' => 'Calendar'],
                ['href' => '/clients', 'label' => 'Clients', 'hint' => 'CRM'],
                ['href' => '/staff', 'label' => 'Staff', 'hint' => 'Team'],
                ['href' => '/services-resources', 'label' => 'Services', 'hint' => 'Catalog'],
                ['href' => '/sales', 'label' => 'Sales', 'hint' => 'Staff checkout & orders'],
                ['href' => '/inventory', 'label' => 'Inventory', 'hint' => 'Stock'],
                ['href' => '/settings', 'label' => 'Settings', 'hint' => 'Workspace'],
            ],
        ];
    }

    private function resolveScopeLabel(?int $branchId): string
    {
        if ($branchId === null) {
            return 'All branches';
        }
        $row = $this->branchDirectory->getBranchByIdForAdmin($branchId);
        if ($row !== null && !empty($row['name'])) {
            return (string) $row['name'];
        }

        return 'Branch #' . $branchId;
    }

    /**
     * @return list<array{text: string}>
     */
    private function buildAttentionLines(
        int $pastStartOpen,
        int $startingSoon,
        int $cancelledToday,
        int $noShowToday,
        int $waiting,
        int $matched,
        int $offered
    ): array {
        $lines = [];
        if ($pastStartOpen > 0) {
            $lines[] = [
                'text' => $pastStartOpen . ' appointment(s) still marked scheduled or confirmed after start time — review status in Appointments.',
            ];
        }
        if ($startingSoon > 0) {
            $lines[] = [
                'text' => $startingSoon . ' appointment(s) starting in the next ' . self::STARTING_SOON_MINUTES . ' minutes.',
            ];
        }
        if ($waiting > 0 || $matched > 0 || $offered > 0) {
            $lines[] = [
                'text' => 'Waitlist: ' . $waiting . ' waiting, ' . $matched . ' matched'
                    . ($offered > 0 ? ', ' . $offered . ' offer(s) pending' : '') . '.',
            ];
        }
        if ($cancelledToday > 0 || $noShowToday > 0) {
            $lines[] = [
                'text' => 'Today’s exceptions: ' . $cancelledToday . ' cancelled, ' . $noShowToday . ' no-show.',
            ];
        }
        if ($lines === []) {
            $lines[] = ['text' => 'Nothing needs attention right now.'];
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int,time_display:string,client:string,service:string,staff:string,status:string,branch:string,show_url:string}>
     */
    private function normalizeUpcomingRows(array $rows, \DateTimeZone $tz, \DateTimeImmutable $now, bool $showBranchColumn): array
    {
        $todayYmd = $now->format('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $rawStart = (string) ($r['start_at'] ?? '');
            $dt = null;
            if ($rawStart !== '') {
                try {
                    $dt = new \DateTimeImmutable($rawStart, $tz);
                } catch (\Throwable) {
                    $dt = null;
                }
            }
            if ($dt === null) {
                $timeDisplay = $rawStart !== '' ? $rawStart : '—';
            } else {
                $timeDisplay = $dt->format('Y-m-d') === $todayYmd
                    ? $dt->format('g:i A')
                    : $dt->format('D M j, g:i A');
            }

            $c1 = trim((string) ($r['client_first_name'] ?? ''));
            $c2 = trim((string) ($r['client_last_name'] ?? ''));
            $client = trim($c1 . ' ' . $c2);
            if ($client === '') {
                $client = '—';
            }

            $s1 = trim((string) ($r['staff_first_name'] ?? ''));
            $s2 = trim((string) ($r['staff_last_name'] ?? ''));
            $staff = trim($s1 . ' ' . $s2);
            if ($staff === '') {
                $staff = '—';
            }

            $statusRaw = (string) ($r['status'] ?? '');
            $branchLabel = '—';
            if ($showBranchColumn) {
                $bn = trim((string) ($r['branch_name'] ?? ''));
                $bid = $r['branch_id'] ?? null;
                if ($bn !== '') {
                    $branchLabel = $bn;
                } elseif ($bid !== null && $bid !== '') {
                    $branchLabel = '#' . (int) $bid;
                }
            }

            $out[] = [
                'id' => $id,
                'time_display' => $timeDisplay,
                'client' => $client,
                'service' => trim((string) ($r['service_name'] ?? '')) !== '' ? (string) $r['service_name'] : '—',
                'staff' => $staff,
                'status' => $this->formatStatusLabel($statusRaw),
                'branch' => $branchLabel,
                'show_url' => $id > 0 ? '/appointments/' . $id : '#',
            ];
        }

        return $out;
    }

    private function formatStatusLabel(string $status): string
    {
        $raw = trim($status);
        if ($raw === '') {
            return '—';
        }

        return match ($raw) {
            'scheduled' => 'Scheduled',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No show',
            default => $raw,
        };
    }
}
