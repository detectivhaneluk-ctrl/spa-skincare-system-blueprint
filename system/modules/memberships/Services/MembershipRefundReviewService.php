<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

use Core\App\Application;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Memberships\Repositories\MembershipBillingCycleRepository;
use Modules\Memberships\Repositories\MembershipSaleRepository;

/**
 * Operator-facing inbox + safe actions for membership sale / renewal billing refund-review states.
 * Canonical money truth remains invoices/payments; this layer never silently reverses memberships.
 */
final class MembershipRefundReviewService
{
    private const NOTE_MAX_LEN = 2000;

    public function __construct(
        private MembershipSaleRepository $sales,
        private MembershipBillingCycleRepository $cycles,
        private MembershipSaleService $saleService,
        private MembershipBillingService $billingService,
        private BranchContext $branchContext,
        private AuditService $audit
    ) {
    }

    /**
     * Delegates to {@see MembershipSaleRepository::listRefundReview} — **resolved organization required** (empty when unset).
     *
     * @param 'global'|null $branchScope
     * @return list<array<string, mixed>>
     */
    public function listRefundReviewSales(?int $branchId = null, ?string $branchScope = null): array
    {
        return $this->sales->listRefundReview($branchId, $branchScope);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRefundReviewSalesInTenant(): array
    {
        $b = $this->requireTenantBranchId();

        return $this->sales->listRefundReviewInTenantScope($b);
    }

    /**
     * Delegates to {@see MembershipBillingCycleRepository::listRefundReviewQueue} — **resolved organization required** (empty when unset).
     *
     * @param 'global'|null $branchScope
     * @return list<array<string, mixed>>
     */
    public function listRefundReviewBillingCycles(?int $branchId = null, ?string $branchScope = null): array
    {
        return $this->cycles->listRefundReviewQueue($branchId, $branchScope);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRefundReviewBillingCyclesInTenant(): array
    {
        $b = $this->requireTenantBranchId();

        return $this->cycles->listRefundReviewQueueInTenantScope($b);
    }

    /**
     * @return array{status_before:string, status_after:string, invoice_id:int}
     */
    public function reconcileMembershipSale(int $saleId): array
    {
        $b = $this->requireTenantBranchId();
        $sale = $this->sales->findInTenantScope($saleId, $b);
        if (!$sale) {
            throw new \DomainException('Membership sale not found.');
        }

        return $this->saleService->operatorReevaluateRefundReviewSale($saleId, $b);
    }

    public function acknowledgeMembershipSale(int $saleId, ?string $note): void
    {
        $b = $this->requireTenantBranchId();
        $sale = $this->sales->findInTenantScope($saleId, $b);
        if (!$sale) {
            throw new \DomainException('Membership sale not found.');
        }
        if (($sale['status'] ?? '') !== 'refund_review') {
            throw new \DomainException('Membership sale is not in refund_review status.');
        }
        $noteTrim = $this->truncateNote($note);
        $this->audit->log('membership_sale_refund_review_acknowledged', 'membership_sale', $saleId, $this->currentUserId(), $this->branchIdFromSale($sale), [
            'invoice_id' => (int) ($sale['invoice_id'] ?? 0),
            'note' => $noteTrim,
        ]);
    }

    /**
     * @return array{cycle_snapshot_before: array{status: string, renewal_applied_at: ?string}, cycle_snapshot_after: array{status: string, renewal_applied_at: ?string}, invoice_id: int}
     */
    public function reconcileBillingCycle(int $cycleId): array
    {
        $b = $this->requireTenantBranchId();
        $cycle = $this->cycles->findInTenantScope($cycleId, $b);
        if (!$cycle) {
            throw new \DomainException('Billing cycle not found.');
        }

        return $this->billingService->operatorReconcileBillingCycleRefundReview($cycleId);
    }

    public function acknowledgeBillingCycle(int $cycleId, ?string $note): void
    {
        $b = $this->requireTenantBranchId();
        $cycle = $this->cycles->findInTenantScope($cycleId, $b);
        if (!$cycle) {
            throw new \DomainException('Billing cycle not found.');
        }
        $this->billingService->operatorAcknowledgeBillingCycleRefundReview($cycleId, $note);
    }

    private function requireTenantBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for membership refund review operations.');
        }

        return $branchId;
    }

    /** @param array<string, mixed> $sale */
    private function branchIdFromSale(array $sale): ?int
    {
        if (!isset($sale['branch_id']) || $sale['branch_id'] === '' || $sale['branch_id'] === null) {
            return null;
        }

        return (int) $sale['branch_id'];
    }

    private function truncateNote(?string $note): ?string
    {
        $n = $note !== null ? trim($note) : '';
        if ($n === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($n, 0, self::NOTE_MAX_LEN, 'UTF-8');
        }

        return strlen($n) <= self::NOTE_MAX_LEN ? $n : substr($n, 0, self::NOTE_MAX_LEN);
    }

    private function currentUserId(): ?int
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }
}
