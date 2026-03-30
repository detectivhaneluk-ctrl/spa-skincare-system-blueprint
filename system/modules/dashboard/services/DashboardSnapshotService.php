<?php

declare(strict_types=1);

namespace Modules\Dashboard\Services;

use Core\Branch\BranchContext;
use Modules\Dashboard\Repositories\DashboardReadRepository;
use Modules\Notifications\Repositories\NotificationRepository;

/**
 * Operator dashboard read-model. All reads; no writes.
 *
 * Time windows (PHP default timezone after {@see \Core\App\ApplicationTimezone::applyForHttpRequest()}):
 * - today: [today 00:00:00, tomorrow 00:00:00)
 * - next_7_days_schedule: [tomorrow 00:00:00, today+8d 00:00:00) — seven calendar days after today
 * - membership_renewal_soon: client_memberships.ends_at DATE in [today, today+6d] inclusive (7 calendar days)
 * - membership_cycle_past_due: membership_billing_cycles.due_at < today (Y-m-d), statuses per query
 *
 * Appointment statuses (canonical {@see \Modules\Appointments\Services\AppointmentService::VALID_STATUSES}):
 * scheduled, confirmed, in_progress, completed, cancelled, no_show
 *
 * Waitlist statuses: waiting, offered, matched, booked, cancelled
 *
 * Reporting parity: JSON routes under {@code /reports/*} that mirror appointments and invoice-linked payments/refunds/VAT
 * use the same branch rule {@code (branch_id = context OR branch_id IS NULL)} and payment activity timestamp
 * {@code COALESCE(paid_at, created_at)} as {@see \Modules\Reports\Repositories\ReportRepository}.
 */
final class DashboardSnapshotService
{
    public function __construct(
        private DashboardReadRepository $reads,
        private NotificationRepository $notifications,
        private BranchContext $branchContext
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(int $userId): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $today = new \DateTimeImmutable('today', $tz);
        $tomorrow = $today->modify('+1 day');
        $nextWindowEnd = $today->modify('+8 days');
        $now = new \DateTimeImmutable('now', $tz);

        $todayStart = $today->format('Y-m-d H:i:s');
        $tomorrowStart = $tomorrow->format('Y-m-d H:i:s');
        $nowStr = $now->format('Y-m-d H:i:s');
        $nextRangeStart = $tomorrowStart;
        $nextRangeEnd = $nextWindowEnd->format('Y-m-d H:i:s');
        $todayYmd = $today->format('Y-m-d');
        $renewalSoonEndYmd = $today->modify('+6 days')->format('Y-m-d');

        $branchId = $this->branchContext->getCurrentBranchId();

        $openInvoices = $this->reads->openInvoiceTotalsByCurrency($branchId);

        return [
            'meta' => [
                'timezone' => date_default_timezone_get(),
                'branch_scope_id' => $branchId,
                'branch_scope_label' => $branchId === null ? 'all branches (no branch context)' : 'branch ' . $branchId,
                'windows' => [
                    'today_local' => $todayYmd,
                    'today_range' => ['start_inclusive' => $todayStart, 'end_exclusive' => $tomorrowStart],
                    'next_7_days_schedule_range' => ['start_inclusive' => $nextRangeStart, 'end_exclusive' => $nextRangeEnd],
                    'membership_renewal_soon_ends_at' => ['start_inclusive' => $todayYmd, 'end_inclusive' => $renewalSoonEndYmd],
                    'membership_past_due_compare_due_at_to' => $todayYmd,
                ],
                'appointment_statuses_documented' => ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'],
                'waitlist_statuses_documented' => ['waiting', 'offered', 'matched', 'booked', 'cancelled'],
            ],
            'appointments_today' => [
                'total' => $this->reads->countAppointmentsTodayTotal($branchId, $todayStart, $tomorrowStart),
                'active_upcoming' => $this->reads->countAppointmentsTodayActiveUpcoming($branchId, $todayStart, $tomorrowStart, $nowStr),
                'completed' => $this->reads->countAppointmentsTodayByStatus($branchId, $todayStart, $tomorrowStart, 'completed'),
                'cancelled' => $this->reads->countAppointmentsTodayByStatus($branchId, $todayStart, $tomorrowStart, 'cancelled'),
                'no_show' => $this->reads->countAppointmentsTodayByStatus($branchId, $todayStart, $tomorrowStart, 'no_show'),
            ],
            'appointments_schedule' => [
                'next_7_days_total' => $this->reads->countAppointmentsInRange($branchId, $nextRangeStart, $nextRangeEnd),
                'past_start_open' => $this->reads->countAppointmentsPastStartOpen($branchId, $nowStr),
                'by_day_next_7' => $this->reads->countAppointmentsByDayInRange($branchId, $nextRangeStart, $nextRangeEnd),
            ],
            'sales' => [
                'payments_completed_net_by_currency_today' => $this->reads->sumCompletedPaymentsNetByCurrencyToday($branchId, $todayStart, $tomorrowStart),
                'invoices_created_today_by_currency' => $this->reads->countInvoicesCreatedTodayByCurrency($branchId, $todayStart, $tomorrowStart),
                'open_invoices_count' => $openInvoices['count'],
                'open_invoice_balance_by_currency' => $openInvoices['balances_by_currency'],
            ],
            'waitlist' => [
                'open_pipeline_waiting_or_matched' => $this->reads->countWaitlistStatuses($branchId, ['waiting', 'matched']),
                'waiting' => $this->reads->countWaitlistByStatus($branchId, 'waiting'),
                'offered' => $this->reads->countWaitlistByStatus($branchId, 'offered'),
                'matched' => $this->reads->countWaitlistByStatus($branchId, 'matched'),
                'offered_past_expiry_still_offered' => $this->reads->countWaitlistOfferedPastExpiry($branchId),
            ],
            'memberships' => [
                'active_client_memberships' => $this->reads->countClientMembershipsByStatus($branchId, 'active'),
                'active_renewal_soon_ends_within_7d' => $this->reads->countActiveMembershipsRenewalWindow($branchId, $todayYmd, $renewalSoonEndYmd),
                'billing_cycles_status_overdue' => $this->reads->countMembershipBillingCyclesByStatus($branchId, 'overdue'),
                'billing_cycles_past_due_open_invoice' => $this->reads->countMembershipBillingCyclesPastDueOpenInvoice($branchId, $todayYmd),
            ],
            'inventory' => [
                'low_stock_products' => $this->reads->countProductsLowStock($branchId),
            ],
            'notifications' => [
                'unread_for_user' => $this->notifications->countForUser($userId, $branchId, ['is_read' => false]),
            ],
        ];
    }
}
