<?php

declare(strict_types=1);

namespace Modules\Reports\Controllers;

use Modules\Reports\Services\ReportService;

/**
 * Read-only report endpoints. Returns JSON for consumption by Dashboard or other clients.
 * Query params: date_from (Y-m-d), date_to (Y-m-d), branch_id (optional; for global users only; branch-scoped users are forced to their branch).
 * Appointment and invoice-backed payment/refund/VAT reports align with dashboard branch scope (branch + NULL-branch rows);
 * payment date filters use {@code COALESCE(paid_at, created_at)} — see {@see \Modules\Reports\Repositories\ReportRepository}.
 */
final class ReportController
{
    public function __construct(private ReportService $reportService)
    {
    }

    /**
     * Build filters from GET; on invalid date input sends 400 JSON and exits.
     *
     * @return array{branch_id: int|null, date_from: string|null, date_to: string|null}
     */
    private function filters(): array
    {
        $dateFrom = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : null;
        $dateTo = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : null;
        $branchId = null;
        if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '' && $_GET['branch_id'] !== null) {
            $branchId = (int) $_GET['branch_id'];
        }
        try {
            return $this->reportService->buildFilters($dateFrom, $dateTo, $branchId);
        } catch (\InvalidArgumentException $e) {
            $this->badRequest($e->getMessage());
        }
    }

    private function badRequest(string $message): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => ['message' => $message]], JSON_THROW_ON_ERROR);
        exit;
    }

    public function revenueSummary(): void
    {
        $this->json($this->reportService->getRevenueSummary($this->filters()));
    }

    public function paymentsByMethod(): void
    {
        $this->json($this->reportService->getPaymentsByMethod($this->filters()));
    }

    public function refundsSummary(): void
    {
        $this->json($this->reportService->getRefundsSummary($this->filters()));
    }

    public function appointmentsVolume(): void
    {
        $this->json($this->reportService->getAppointmentsVolumeSummary($this->filters()));
    }

    public function newClients(): void
    {
        $this->json($this->reportService->getNewClientsSummary($this->filters()));
    }

    public function staffAppointmentCount(): void
    {
        $this->json($this->reportService->getStaffAppointmentCountSummary($this->filters()));
    }

    public function giftCardLiability(): void
    {
        $this->json($this->reportService->getGiftCardLiabilitySummary($this->filters()));
    }

    public function inventoryMovements(): void
    {
        $this->json($this->reportService->getInventoryMovementSummary($this->filters()));
    }

    public function vatDistribution(): void
    {
        $this->json($this->reportService->getVatDistribution($this->filters()));
    }

    private function json(mixed $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }
}
