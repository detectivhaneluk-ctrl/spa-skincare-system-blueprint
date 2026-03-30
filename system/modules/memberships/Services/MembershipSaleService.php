<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

use Core\App\Database;
use Core\App\Application;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Contracts\PublicCommerceFulfillmentReconciler;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Repositories\MembershipSaleRepository;
use Modules\Memberships\Support\MembershipEntitlementSnapshot;
use Modules\PublicCommerce\Services\PublicCommerceFulfillmentReconcileRecoveryService;
use Modules\Sales\Repositories\InvoiceRepository;
use Modules\Sales\Services\InvoiceService;

/**
 * Initial membership sale: canonical {@see InvoiceService::create} + {@see membership_sales} row;
 * activation on full pay via {@see syncMembershipSaleForInvoice} (invoice/payment authority hooks).
 *
 * Duplicate issuance: same-transaction {@see ClientRepository::findForUpdate} + {@see MembershipSaleRepository::findBlockingOpenInitialSale}
 * blocks a second sale for the same client + definition + branch while any non-terminal pipeline row exists
 * (`draft`/`invoiced`/`paid`/`refund_review`), including between invoice `paid` and membership activation.
 * Paid activation also runs {@see MembershipService::assignToClientAuthoritative}, which locks the client again and rejects
 * overlapping or in-flight `client_memberships` for the same client + definition + branch scope (manual assign uses the same path).
 *
 * **Invoice-keyed repository reads** ({@see MembershipSaleRepository::listByInvoiceId}, {@see MembershipSaleRepository::listDistinctInvoiceIdsForReconcile}):
 * invoice-plane org EXISTS on `invoices.branch_id` (strict tenant vs repair OrUnscoped). Canonical invoice rows via {@see InvoiceRepository::find}.
 */
final class MembershipSaleService
{
    /** Machine-readable tag in invoice notes; used to emit staff-checkout activation audit after pay. */
    public const INVOICE_NOTE_CHECKOUT_MARKER_STAFF_INVOICE = '[checkout:staff_invoice]';

    public const INVOICE_NOTE_CHECKOUT_MARKER_PUBLIC_COMMERCE = '[checkout:public_commerce]';

    /**
     * @param callable(): PublicCommerceFulfillmentReconciler $publicCommerceFulfillmentReconciler
     */
    public function __construct(
        private Database $db,
        private MembershipSaleRepository $sales,
        private ClientRepository $clients,
        private MembershipDefinitionRepository $definitions,
        private InvoiceService $invoiceService,
        private InvoiceRepository $invoiceRepo,
        private MembershipService $membershipService,
        private AuditService $audit,
        private BranchContext $branchContext,
        private SettingsService $settings,
        private $publicCommerceFulfillmentReconciler,
        private PublicCommerceFulfillmentReconcileRecoveryService $publicCommerceFulfillmentReconcileRecovery,
    ) {
    }

    /**
     * Create sale row + open invoice (manual line, tagged notes). Blocks while a non-terminal sale already exists for the same client + definition + branch (see {@see MembershipSaleRepository::findBlockingOpenInitialSale}); after `activated` / `void` / `cancelled`, a new sale may be created.
     *
     * @param array{membership_definition_id:int, client_id:int, branch_id?:?int, starts_at?:string, notes?:string} $data
     * @param array{staff_invoice_checkout?:bool,public_commerce_checkout?:bool} $auditContext when `staff_invoice_checkout` true: marker in invoice notes + extra staff-checkout audits/metadata; `public_commerce_checkout` for anonymous public purchase path
     * @return array{membership_sale_id:int, invoice_id:int}
     */
    public function createSaleAndInvoice(array $data, array $auditContext = []): array
    {
        return $this->transactional(function () use ($data, $auditContext): array {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $definitionId = (int) ($data['membership_definition_id'] ?? 0);
            $clientId = (int) ($data['client_id'] ?? 0);
            if ($definitionId <= 0 || $clientId <= 0) {
                throw new \DomainException('membership_definition_id and client_id are required.');
            }
            $saleBranch = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : $this->branchContext->getCurrentBranchId();
            if ($saleBranch === null || $saleBranch <= 0) {
                throw new \DomainException('Branch context is required for membership sale.');
            }
            $data['branch_id'] = $saleBranch;

            $startsAt = isset($data['starts_at']) ? trim((string) $data['starts_at']) : '';
            if ($startsAt === '') {
                $startsAt = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt) !== 1) {
                throw new \DomainException('starts_at must be a Y-m-d date or empty.');
            }

            $clientRow = $this->clients->findLiveForUpdateOnBranch($clientId, $saleBranch);
            if (!$clientRow) {
                throw new \DomainException('Client not found or not assignable on this branch.');
            }

            $def = $this->definitions->findInTenantScope($definitionId, $saleBranch);
            if (!$def || ($def['status'] ?? '') !== 'active') {
                throw new \DomainException('Membership definition not found or not active.');
            }
            $price = $def['price'] ?? null;
            if ($price === null || $price === '') {
                throw new \DomainException('Membership definition has no price; cannot create sale invoice.');
            }
            $amount = round((float) $price, 2);
            if (!is_finite($amount) || $amount <= 0) {
                throw new \DomainException('Membership definition price must be greater than zero.');
            }
            $defBranch = isset($def['branch_id']) && $def['branch_id'] !== '' && $def['branch_id'] !== null
                ? (int) $def['branch_id']
                : null;
            if ($defBranch !== null && $defBranch !== $saleBranch) {
                throw new \DomainException('Membership definition branch does not match sale branch.');
            }
            $blocking = $this->sales->findBlockingOpenInitialSale($clientId, $definitionId, $saleBranch);
            if ($blocking !== null) {
                throw new \DomainException(
                    'An open membership sale invoice already exists for this client and plan. Use the existing invoice or resolve it before creating another.'
                );
            }

            $actor = $this->currentUserId();
            $snapshotPayload = MembershipEntitlementSnapshot::fromDefinitionRow($def, $saleBranch);
            $definitionSnapshotJson = MembershipEntitlementSnapshot::encode($snapshotPayload);
            $saleId = $this->sales->insert([
                'membership_definition_id' => $definitionId,
                'client_id' => $clientId,
                'branch_id' => $saleBranch,
                'status' => 'draft',
                'starts_at' => $startsAt,
                'sold_by_user_id' => $actor,
                'definition_snapshot_json' => $definitionSnapshotJson,
            ]);
            $branchId = $saleBranch;
            $defName = trim((string) ($def['name'] ?? 'Membership'));
            $desc = sprintf('Membership: %s [membership_sale_id=%d]', $defName, $saleId);
            $notes = 'membership_initial_sale:membership_sale_id=' . $saleId;
            $extraNotes = isset($data['notes']) ? trim((string) $data['notes']) : '';
            if ($extraNotes !== '') {
                $notes .= "\n\n" . $extraNotes;
            }
            $termsBlock = $this->settings->membershipTermsDocumentBlock($branchId);
            if ($termsBlock !== null) {
                $notes .= "\n\n" . $termsBlock;
            }
            $staffCheckout = !empty($auditContext['staff_invoice_checkout']);
            $publicCommerceCheckout = !empty($auditContext['public_commerce_checkout']);
            if ($staffCheckout) {
                $notes .= "\n" . self::INVOICE_NOTE_CHECKOUT_MARKER_STAFF_INVOICE;
            }
            if ($publicCommerceCheckout) {
                $notes .= "\n" . self::INVOICE_NOTE_CHECKOUT_MARKER_PUBLIC_COMMERCE;
            }
            $invoiceId = $this->invoiceService->create([
                'branch_id' => $branchId,
                'client_id' => $clientId,
                'appointment_id' => null,
                'status' => 'open',
                'notes' => $notes,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'items' => [
                    [
                        'item_type' => 'manual',
                        'source_id' => null,
                        'description' => $desc,
                        'quantity' => 1,
                        'unit_price' => $amount,
                        'discount_amount' => 0,
                        'tax_rate' => 0,
                    ],
                ],
            ]);
            $this->sales->update($saleId, [
                'invoice_id' => $invoiceId,
                'status' => 'invoiced',
            ]);
            $createdMeta = [
                'membership_definition_id' => $definitionId,
                'client_id' => $clientId,
                'invoice_id' => $invoiceId,
            ];
            if ($staffCheckout) {
                $createdMeta['staff_invoice_checkout'] = true;
            }
            if ($publicCommerceCheckout) {
                $createdMeta['public_commerce_checkout'] = true;
            }
            $this->audit->log('membership_sale_created', 'membership_sale', $saleId, $actor, $branchId, $createdMeta);
            $invoiceCreatedMeta = ['invoice_id' => $invoiceId];
            if ($staffCheckout) {
                $invoiceCreatedMeta['staff_invoice_checkout'] = true;
            }
            if ($publicCommerceCheckout) {
                $invoiceCreatedMeta['public_commerce_checkout'] = true;
            }
            $this->audit->log('membership_sale_invoice_created', 'membership_sale', $saleId, $actor, $branchId, $invoiceCreatedMeta);
            if ($staffCheckout) {
                $this->audit->log('membership_sale_staff_checkout_invoice_linked', 'membership_sale', $saleId, $actor, $branchId, [
                    'invoice_id' => $invoiceId,
                    'membership_definition_id' => $definitionId,
                    'client_id' => $clientId,
                ]);
            }
            if ($publicCommerceCheckout) {
                $this->audit->log('membership_sale_public_commerce_checkout_invoice_linked', 'membership_sale', $saleId, $actor, $branchId, [
                    'invoice_id' => $invoiceId,
                    'membership_definition_id' => $definitionId,
                    'client_id' => $clientId,
                ]);
            }

            return ['membership_sale_id' => $saleId, 'invoice_id' => $invoiceId];
        });
    }

    /**
     * @return array{invoices_synced:int}
     */
    public function reconcileMembershipSalesFromCanonicalInvoices(?int $invoiceId = null, ?int $clientId = null, ?int $branchId = null): array
    {
        $ids = $this->sales->listDistinctInvoiceIdsForReconcile($invoiceId, $clientId, $branchId);
        foreach ($ids as $iid) {
            $this->syncMembershipSaleForInvoice($iid);
        }

        return ['invoices_synced' => count($ids)];
    }

    public function syncMembershipSaleForInvoice(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $saleRows = $this->sales->listByInvoiceId($invoiceId);
        if ($saleRows === []) {
            return;
        }

        $invAny = $this->invoiceRepo->find($invoiceId, true);
        if (!$invAny) {
            return;
        }

        if (!empty($invAny['deleted_at'])) {
            foreach ($saleRows as $row) {
                $this->transactional(function () use ($row, $invAny): void {
                    $this->settleSaleForDeletedInvoice((int) $row['id'], $invAny);
                });
            }

            return;
        }

        $this->invoiceService->recomputeInvoiceFinancials($invoiceId);
        $inv = $this->invoiceRepo->find($invoiceId);
        if (!$inv) {
            return;
        }

        foreach ($saleRows as $row) {
            $this->transactional(function () use ($row, $inv): void {
                $this->settleSingleSaleLocked((int) $row['id'], $inv, false);
            });
        }
    }

    /**
     * Operator-only: re-run settlement for a sale stuck in {@code refund_review} after canonical invoice/payment was corrected.
     * Does not mutate {@see client_memberships} except via existing idempotent activation when invoice truth allows it.
     *
     * @return array{status_before:string, status_after:string, invoice_id:int}
     */
    public function operatorReevaluateRefundReviewSale(int $saleId, int $branchId): array
    {
        return $this->transactional(function () use ($saleId, $branchId): array {
            $sale = $this->sales->findForUpdateInTenantScope($saleId, $branchId);
            if (!$sale) {
                throw new \DomainException('Membership sale not found.');
            }
            if (($sale['status'] ?? '') !== 'refund_review') {
                throw new \DomainException('Membership sale is not in refund_review status.');
            }
            $invoiceId = (int) ($sale['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                throw new \DomainException('Membership sale has no invoice.');
            }
            $this->invoiceService->recomputeInvoiceFinancials($invoiceId);
            $inv = $this->invoiceRepo->find($invoiceId);
            if (!$inv) {
                throw new \DomainException('Invoice not found.');
            }
            $before = (string) ($sale['status'] ?? '');
            $this->settleSingleSaleLocked($saleId, $inv, true);
            $afterRow = $this->sales->findInTenantScope($saleId, $branchId);
            $statusAfter = (string) ($afterRow['status'] ?? $before);
            $this->audit->log('membership_sale_refund_review_operator_reconcile', 'membership_sale', $saleId, $this->currentUserId(), $this->branchFromSale($afterRow ?? $sale), [
                'status_before' => $before,
                'status_after' => $statusAfter,
                'invoice_id' => $invoiceId,
            ]);

            return [
                'status_before' => $before,
                'status_after' => $statusAfter,
                'invoice_id' => $invoiceId,
            ];
        });
    }

    /**
     * @param array<string, mixed> $invTrashed from InvoiceRepository::find with trashed
     */
    private function settleSaleForDeletedInvoice(int $saleId, array $invTrashed): void
    {
        $invBranch = (int) ($invTrashed['branch_id'] ?? 0);
        $sale = $invBranch > 0
            ? $this->sales->findForUpdateInTenantScope($saleId, $invBranch)
            : $this->sales->findForUpdate($saleId);
        if (!$sale || (int) ($sale['invoice_id'] ?? 0) !== (int) ($invTrashed['id'] ?? 0)) {
            return;
        }
        $beforeStatus = (string) ($sale['status'] ?? '');
        $hasCm = isset($sale['client_membership_id']) && (int) $sale['client_membership_id'] > 0;
        $activated = $beforeStatus === 'activated'
            || ($sale['activation_applied_at'] !== null && $sale['activation_applied_at'] !== '')
            || $hasCm;
        if ($activated) {
            if ($beforeStatus !== 'refund_review') {
                $this->sales->update($saleId, ['status' => 'refund_review']);
                $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $this->branchFromSale($sale), [
                    'reason' => 'invoice_deleted_after_activation',
                    'invoice_id' => (int) ($invTrashed['id'] ?? 0),
                ]);
            }
        } elseif (!in_array($beforeStatus, ['void', 'cancelled', 'refund_review'], true)) {
            $this->sales->update($saleId, ['status' => 'void']);
            $this->audit->log('membership_sale_voided', 'membership_sale', $saleId, $this->currentUserId(), $this->branchFromSale($sale), [
                'reason' => 'invoice_deleted',
                'invoice_id' => (int) ($invTrashed['id'] ?? 0),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $inv active invoice
     */
    private function settleSingleSaleLocked(int $saleId, array $inv, bool $operatorReevaluateRefundReview): void
    {
        $invBranch = (int) ($inv['branch_id'] ?? 0);
        $sale = $invBranch > 0
            ? $this->sales->findForUpdateInTenantScope($saleId, $invBranch)
            : $this->sales->findForUpdate($saleId);
        if (!$sale || (int) ($sale['invoice_id'] ?? 0) !== (int) ($inv['id'] ?? 0)) {
            return;
        }
        $invoiceId = (int) ($inv['id'] ?? 0);
        $branchId = $this->branchFromSale($sale);

        if ((int) ($inv['client_id'] ?? 0) !== (int) ($sale['client_id'] ?? 0)) {
            $this->sales->update($saleId, ['status' => 'refund_review']);
            $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'reason' => 'invoice_client_mismatch',
                'invoice_id' => $invoiceId,
            ]);

            return;
        }

        $status = (string) ($inv['status'] ?? '');
        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);
        $beforeStatus = (string) ($sale['status'] ?? '');
        $hasCm = isset($sale['client_membership_id']) && (int) $sale['client_membership_id'] > 0;
        $wasActivated = $beforeStatus === 'activated'
            || ($sale['activation_applied_at'] !== null && $sale['activation_applied_at'] !== '')
            || $hasCm;

        if ($status === 'cancelled') {
            if ($wasActivated) {
                if ($beforeStatus !== 'refund_review') {
                    $this->sales->update($saleId, ['status' => 'refund_review']);
                    $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                        'reason' => 'invoice_cancelled_after_activation',
                        'invoice_id' => $invoiceId,
                    ]);
                }
            } elseif (!in_array($beforeStatus, ['void', 'cancelled', 'refund_review'], true)) {
                $this->sales->update($saleId, ['status' => 'cancelled']);
                $this->audit->log('membership_sale_cancelled', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                    'invoice_id' => $invoiceId,
                ]);
            }

            return;
        }

        if ($status === 'refunded') {
            if ($wasActivated) {
                if ($beforeStatus !== 'refund_review') {
                    $this->sales->update($saleId, ['status' => 'refund_review']);
                    $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                        'reason' => 'invoice_refunded_after_activation',
                        'invoice_id' => $invoiceId,
                    ]);
                }
            } elseif (!in_array($beforeStatus, ['void', 'cancelled', 'refund_review'], true)) {
                $this->sales->update($saleId, ['status' => 'void']);
                $this->audit->log('membership_sale_voided', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                    'reason' => 'invoice_refunded',
                    'invoice_id' => $invoiceId,
                ]);
            }

            return;
        }

        if ($total <= 0) {
            if ($wasActivated) {
                if ($beforeStatus !== 'refund_review') {
                    $this->sales->update($saleId, ['status' => 'refund_review']);
                    $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                        'reason' => 'invoice_non_positive_total_after_activation',
                        'invoice_id' => $invoiceId,
                    ]);
                }
            } elseif (!in_array($beforeStatus, ['void', 'cancelled', 'refund_review'], true)) {
                $this->sales->update($saleId, ['status' => 'void']);
                $this->audit->log('membership_sale_voided', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                    'reason' => 'invoice_non_positive_total',
                    'invoice_id' => $invoiceId,
                ]);
            }

            return;
        }

        $fullyPaid = $paid >= $total;

        if ($wasActivated) {
            if (!$fullyPaid) {
                if ($beforeStatus !== 'refund_review') {
                    $this->sales->update($saleId, ['status' => 'refund_review']);
                    $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                        'reason' => 'invoice_no_longer_fully_paid',
                        'invoice_id' => $invoiceId,
                        'paid' => $paid,
                        'total' => $total,
                    ]);
                }
            } elseif (
                $beforeStatus === 'refund_review'
                && $fullyPaid
                && !in_array($status, ['cancelled', 'refunded'], true)
                && $total > 0
                && (int) ($inv['client_id'] ?? 0) === (int) ($sale['client_id'] ?? 0)
            ) {
                $this->sales->update($saleId, ['status' => 'activated']);
                $this->audit->log('membership_sale_refund_review_resolved_activated', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                    'invoice_id' => $invoiceId,
                    'mechanism' => 'canonical_invoice_recovered',
                ]);
                $this->triggerPublicCommerceFulfillmentAfterMembershipActivated($invoiceId);
            }

            return;
        }

        if ($sale['activation_applied_at'] !== null && $sale['activation_applied_at'] !== '') {
            return;
        }

        if ($hasCm) {
            return;
        }

        if (!$fullyPaid) {
            if (in_array($beforeStatus, ['draft', 'invoiced', 'paid'], true)) {
                $this->sales->update($saleId, ['status' => 'invoiced']);
            }

            return;
        }

        if ($beforeStatus === 'void' || $beforeStatus === 'cancelled') {
            return;
        }
        if ($beforeStatus === 'refund_review' && !$operatorReevaluateRefundReview) {
            return;
        }

        $this->activateSaleMembership($sale, $saleId, $invoiceId, $branchId, $inv);
    }

    /**
     * @param array<string, mixed> $sale locked row
     * @param array<string, mixed> $invoiceRow canonical invoice row (for notes marker)
     */
    private function activateSaleMembership(array $sale, int $saleId, int $invoiceId, ?int $branchId, array $invoiceRow): void
    {
        $defId = (int) ($sale['membership_definition_id'] ?? 0);
        $saleBranch = isset($sale['branch_id']) && $sale['branch_id'] !== '' && $sale['branch_id'] !== null
            ? (int) $sale['branch_id']
            : null;
        $invoiceBranch = isset($invoiceRow['branch_id']) && $invoiceRow['branch_id'] !== '' && $invoiceRow['branch_id'] !== null
            ? (int) $invoiceRow['branch_id']
            : null;
        $resBranch = $saleBranch !== null && $saleBranch > 0
            ? $saleBranch
            : ($invoiceBranch !== null && $invoiceBranch > 0 ? $invoiceBranch : null);

        $snapArr = MembershipEntitlementSnapshot::decode($this->jsonColumnToString($sale['definition_snapshot_json'] ?? null));
        if ($snapArr === null) {
            $this->sales->update($saleId, ['status' => 'refund_review']);
            $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'reason' => 'missing_membership_definition_snapshot',
                'invoice_id' => $invoiceId,
            ]);

            return;
        }
        if ((int) ($snapArr['membership_definition_id'] ?? 0) !== $defId) {
            $this->sales->update($saleId, ['status' => 'refund_review']);
            $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'reason' => 'membership_snapshot_definition_mismatch',
                'invoice_id' => $invoiceId,
            ]);

            return;
        }
        $snapBranch = (int) ($snapArr['definition_branch_id'] ?? 0);
        if ($resBranch === null || $resBranch <= 0 || $snapBranch !== $resBranch) {
            $this->sales->update($saleId, ['status' => 'refund_review']);
            $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'reason' => 'membership_snapshot_branch_mismatch',
                'invoice_id' => $invoiceId,
            ]);

            return;
        }

        $defCheck = $this->definitions->findInTenantScope($defId, $resBranch);
        if (!$defCheck || !empty($defCheck['deleted_at'])) {
            $this->sales->update($saleId, ['status' => 'refund_review']);
            $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'reason' => 'definition_missing_or_deleted_at_activation',
                'invoice_id' => $invoiceId,
            ]);

            return;
        }

        $startsAt = $sale['starts_at'] !== null && $sale['starts_at'] !== ''
            ? (string) $sale['starts_at']
            : date('Y-m-d');

        $payload = [
            'client_id' => (int) ($sale['client_id'] ?? 0),
            'membership_definition_id' => $defId,
            'branch_id' => $sale['branch_id'] ?? null,
            'starts_at' => $startsAt,
            'notes' => 'membership_sale_id=' . $saleId,
            'membership_entitlement_snapshot' => $snapArr,
        ];

        $actor = isset($sale['sold_by_user_id']) && $sale['sold_by_user_id'] !== '' && $sale['sold_by_user_id'] !== null
            ? (int) $sale['sold_by_user_id']
            : null;

        try {
            $cmId = $this->membershipService->assignToClientAuthoritative($payload, $actor);
        } catch (\DomainException $e) {
            $this->sales->update($saleId, ['status' => 'refund_review']);
            $this->audit->log('membership_sale_refund_review_required', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'reason' => 'activation_failed',
                'invoice_id' => $invoiceId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $cm = $this->membershipService->findClientMembership($cmId, $resBranch !== null && $resBranch > 0 ? $resBranch : null);
        $endsAt = $cm ? (string) ($cm['ends_at'] ?? '') : '';

        $this->sales->update($saleId, [
            'client_membership_id' => $cmId,
            'status' => 'activated',
            'activation_applied_at' => date('Y-m-d H:i:s'),
            'ends_at' => $endsAt !== '' ? $endsAt : null,
        ]);

        $this->audit->log('membership_sale_paid', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
            'invoice_id' => $invoiceId,
            'client_membership_id' => $cmId,
        ]);
        $this->audit->log('membership_sale_activated', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
            'invoice_id' => $invoiceId,
            'client_membership_id' => $cmId,
        ]);
        $invNotes = (string) ($invoiceRow['notes'] ?? '');
        if (str_contains($invNotes, self::INVOICE_NOTE_CHECKOUT_MARKER_STAFF_INVOICE)) {
            $this->audit->log('membership_sale_staff_checkout_activation_complete', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'invoice_id' => $invoiceId,
                'client_membership_id' => $cmId,
            ]);
        }
        if (str_contains($invNotes, self::INVOICE_NOTE_CHECKOUT_MARKER_PUBLIC_COMMERCE)) {
            $this->audit->log('membership_sale_public_commerce_activation_complete', 'membership_sale', $saleId, $this->currentUserId(), $branchId, [
                'invoice_id' => $invoiceId,
                'client_membership_id' => $cmId,
            ]);
        }

        $this->triggerPublicCommerceFulfillmentAfterMembershipActivated($invoiceId);
    }

    /** After trusted membership activation, run {@see PublicCommerceFulfillmentReconciler::reconcile} (single fulfillment truth path). */
    private function triggerPublicCommerceFulfillmentAfterMembershipActivated(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        $trigger = PublicCommerceFulfillmentReconciler::TRIGGER_MEMBERSHIP_PREREQUISITE_COMPLETE;
        try {
            $result = ($this->publicCommerceFulfillmentReconciler)()->reconcile($invoiceId, $trigger, null);
            $this->publicCommerceFulfillmentReconcileRecovery->recordPostReconcileOutcome($invoiceId, $trigger, null, $result);
        } catch (\Throwable $e) {
            slog('error', 'public-commerce.membership_activation_reconcile', 'Reconcile after membership activation failed.', [
                'invoice_id' => $invoiceId,
                'exception' => $e->getMessage(),
            ]);
            $this->publicCommerceFulfillmentReconcileRecovery->persistRecoveryAfterReconcileThrowable($invoiceId, $trigger, null, $e);
        }
    }

    private function jsonColumnToString(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return json_encode($raw, JSON_THROW_ON_ERROR);
        }

        return is_string($raw) ? $raw : null;
    }

    /** @param array<string, mixed> $sale */
    private function branchFromSale(array $sale): ?int
    {
        if (!isset($sale['branch_id']) || $sale['branch_id'] === '' || $sale['branch_id'] === null) {
            return null;
        }

        return (int) $sale['branch_id'];
    }

    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $fn();
            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function currentUserId(): ?int
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }
}
