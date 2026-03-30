<?php

declare(strict_types=1);

namespace Modules\Memberships\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Memberships\Services\MembershipRefundReviewService;

/**
 * Admin/operator inbox for membership initial-sale and renewal billing refund-review cases.
 */
final class MembershipRefundReviewController
{
    public function __construct(
        private MembershipRefundReviewService $refundReview,
        private BranchContext $branchContext,
        private BranchDirectory $branchDirectory
    ) {
    }

    public function index(): void
    {
        try {
            $saleRows = $this->refundReview->listRefundReviewSalesInTenant();
            $cycleRows = $this->refundReview->listRefundReviewBillingCyclesInTenant();
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
            $saleRows = [];
            $cycleRows = [];
        }
        $flash = flash();
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/memberships/views/refund-review/index.php');
    }

    public function reconcileSale(int $id): void
    {
        try {
            $r = $this->refundReview->reconcileMembershipSale($id);
            flash('success', sprintf(
                'Sale #%d reconciled from invoice #%d (%s → %s).',
                $id,
                (int) $r['invoice_id'],
                $r['status_before'],
                $r['status_after']
            ));
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', 'Could not reconcile membership sale.');
        }
        header('Location: /memberships/refund-review');
        exit;
    }

    public function acknowledgeSale(int $id): void
    {
        $note = trim((string) ($_POST['note'] ?? ''));
        try {
            $this->refundReview->acknowledgeMembershipSale($id, $note !== '' ? $note : null);
            flash('success', 'Membership sale review acknowledged (audit only; invoice truth unchanged).');
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', 'Could not acknowledge membership sale review.');
        }
        header('Location: /memberships/refund-review');
        exit;
    }

    public function reconcileBillingCycle(int $id): void
    {
        try {
            $this->refundReview->reconcileBillingCycle($id);
            flash('success', 'Billing cycle reconciled from canonical invoice (see audit for before/after).');
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', 'Could not reconcile billing cycle.');
        }
        header('Location: /memberships/refund-review');
        exit;
    }

    public function acknowledgeBillingCycle(int $id): void
    {
        $note = trim((string) ($_POST['note'] ?? ''));
        try {
            $this->refundReview->acknowledgeBillingCycle($id, $note !== '' ? $note : null);
            flash('success', 'Billing cycle review acknowledged (audit only; invoice truth unchanged).');
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', 'Could not acknowledge billing cycle review.');
        }
        header('Location: /memberships/refund-review');
        exit;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }
}
