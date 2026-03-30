<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use Core\Branch\BranchContext;
use Modules\Reports\Repositories\ReportRepository;

/**
 * Read-only report aggregation. Builds branch/date filters from context and request.
 * Branch-scoped users cannot override branch via request; superadmin may pass branch_id or null.
 */
final class ReportService
{
    public function __construct(
        private ReportRepository $repo,
        private BranchContext $branchContext
    ) {
    }

    /**
     * Build filters from query params.
     * Branch: when user is branch-scoped (context branch set), request branch_id is ignored.
     * When user is global (context branch null), request branch_id is used (or null for all branches).
     * Dates: optional Y-m-d; validated when both provided (must be valid and date_from <= date_to).
     *
     * @return array{branch_id: int|null, date_from: string|null, date_to: string|null}
     * @throws \InvalidArgumentException when date_from/date_to are invalid or inverted
     */
    public function buildFilters(?string $dateFrom = null, ?string $dateTo = null, ?int $branchId = null): array
    {
        $contextBranchId = $this->branchContext->getCurrentBranchId();
        if ($contextBranchId !== null) {
            $branchId = $contextBranchId;
        } else {
            $branchId = $branchId ?? null;
        }
        if ($branchId !== null) {
            $branchId = (int) $branchId;
        }

        $dateFrom = $dateFrom !== null && $dateFrom !== '' ? trim($dateFrom) : null;
        $dateTo = $dateTo !== null && $dateTo !== '' ? trim($dateTo) : null;
        $this->validateDateRange($dateFrom, $dateTo);

        return [
            'branch_id' => $branchId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * @throws \InvalidArgumentException when both dates are set and invalid or date_from > date_to
     */
    private function validateDateRange(?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom === null && $dateTo === null) {
            return;
        }
        if ($dateFrom !== null) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dateFrom, 0, 10));
            if ($d === false) {
                throw new \InvalidArgumentException('Invalid date_from; use Y-m-d.');
            }
        }
        if ($dateTo !== null) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dateTo, 0, 10));
            if ($d === false) {
                throw new \InvalidArgumentException('Invalid date_to; use Y-m-d.');
            }
        }
        if ($dateFrom !== null && $dateTo !== null) {
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dateFrom, 0, 10));
            $to = \DateTimeImmutable::createFromFormat('Y-m-d', substr($dateTo, 0, 10));
            if ($from->format('Y-m-d') > $to->format('Y-m-d')) {
                throw new \InvalidArgumentException('date_from must not be after date_to.');
            }
        }
    }

    public function getRevenueSummary(array $filters): array
    {
        return $this->repo->getRevenueSummary($filters);
    }

    public function getPaymentsByMethod(array $filters): array
    {
        return $this->repo->getPaymentsByMethod($filters);
    }

    public function getRefundsSummary(array $filters): array
    {
        return $this->repo->getRefundsSummary($filters);
    }

    public function getAppointmentsVolumeSummary(array $filters): array
    {
        return $this->repo->getAppointmentsVolumeSummary($filters);
    }

    public function getNewClientsSummary(array $filters): array
    {
        return $this->repo->getNewClientsSummary($filters);
    }

    /** Appointments in date range grouped by staff (branch-scoped). */
    public function getStaffAppointmentCountSummary(array $filters): array
    {
        return $this->repo->getStaffAppointmentCountSummary($filters);
    }

    public function getGiftCardLiabilitySummary(array $filters): array
    {
        return $this->repo->getGiftCardLiabilitySummary($filters);
    }

    public function getInventoryMovementSummary(array $filters): array
    {
        return $this->repo->getInventoryMovementSummary($filters);
    }

    /**
     * VAT distribution by tax rate (invoice lines). Optional vat_rate_id/code/name when vat_rates match rate_percent.
     */
    public function getVatDistribution(array $filters): array
    {
        return $this->repo->getVatDistribution($filters);
    }
}
