<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Contracts\PublicCommerceFulfillmentReconciler as PublicCommerceFulfillmentReconcilerContract;
use Core\Contracts\PublicCommerceFulfillmentSync as PublicCommerceFulfillmentSyncContract;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationLifecycleGate;
use Modules\Clients\Services\PublicClientResolutionService;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Services\MembershipSaleService;
use Modules\Packages\Repositories\PackageRepository;
use Modules\Packages\Support\PackageEntitlementSnapshot;
use Modules\PublicCommerce\Repositories\PublicCommercePurchaseRepository;
use Modules\Sales\Repositories\InvoiceRepository;

/**
 * Anonymous public catalog + purchase initiation; fulfillment sync hooks canonical invoice paid state.
 * Staff-only queue ({@see listStaffAwaitingVerificationQueue}) is session- and tenant-scoped; public JSON controllers must not call it.
 * Anonymous finalize does not record trusted payments — only awaiting_verification + audit (PUBLIC-COMMERCE-PAYMENT-TRUST-HARDENING-01).
 * Anonymous JSON handlers load invoices via {@see InvoiceRepository::findForPublicCommerceCorrelatedBranch} (purchase/initiate branch pin), not {@see InvoiceRepository::find} (branch-derived org session).
 * Trusted completion: internal paths call {@see PublicCommerceFulfillmentReconciler::reconcile} (invoice settlement, membership
 * prerequisite, public finalize when invoice is trusted-paid, staff manual sync). For membership purchases, {@code paid} applies
 * only after {@code membership_sales} is {@code activated}; activation in {@see \Modules\Memberships\Services\MembershipSaleService}
 * triggers the same reconciler. Staff {@see staffTrustedFulfillmentSync} is a thin wrapper (auth/branch) around reconcile.
 */
final class PublicCommerceService implements PublicCommerceFulfillmentSyncContract
{
    public const ERROR_GENERIC = 'Purchase could not be completed. Please contact the spa if you need help.';
    public const ERROR_ORGANIZATION_SUSPENDED = 'Tenant branch is unavailable.';

    private const PURCHASE_INITIATED = 'initiated';
    private const PURCHASE_AWAITING_VERIFICATION = 'awaiting_verification';
    private const PURCHASE_PAID = 'paid';
    private const PURCHASE_FAILED = 'failed';
    private const PURCHASE_CANCELLED = 'cancelled';

    public function __construct(
        private Database $db,
        private SettingsService $settings,
        private AuditService $audit,
        private PublicClientResolutionService $publicClientResolution,
        private InvoiceRepository $invoiceRepo,
        private PublicCommercePurchaseRepository $purchases,
        private MembershipDefinitionRepository $membershipDefinitions,
        private PackageRepository $packages,
        private BranchContext $branchContext,
        private SessionAuth $session,
        private PublicCommerceFulfillmentReconcilerContract $fulfillmentReconciler,
        private PublicCommerceFulfillmentReconcileRecoveryService $fulfillmentReconcileRecovery,
        private OrganizationLifecycleGate $lifecycleGate,
        private OrganizationContext $organizationContext,
    ) {
    }

    /**
     * Staff-only operational queue (HTTP entry must remain auth + {@see TenantProtectedRouteMiddleware} + permission).
     * Boundaries: no session user → empty; listing is branch-scoped when session branch is set, else organization-scoped
     * (never cross-tenant global).
     *
     * @return list<array<string, mixed>>
     */
    public function listStaffAwaitingVerificationQueue(int $limit = 100): array
    {
        if ($this->session->id() === null) {
            return [];
        }
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        $rows = $this->purchases->listAwaitingVerificationWithInvoices(
            $this->branchContext->getCurrentBranchId(),
            ($orgId !== null && $orgId > 0) ? $orgId : null,
            $limit
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'purchase_id' => (int) ($r['id'] ?? 0),
                'invoice_id' => (int) ($r['invoice_id'] ?? 0),
                'branch_id' => (int) ($r['branch_id'] ?? 0),
                'client_id' => (int) ($r['client_id'] ?? 0),
                'product_kind' => (string) ($r['product_kind'] ?? ''),
                'purchase_status' => (string) ($r['status'] ?? ''),
                'invoice_status' => (string) ($r['invoice_status'] ?? ''),
                'invoice_total_amount' => round((float) ($r['invoice_total_amount'] ?? 0), 2),
                'invoice_paid_amount' => round((float) ($r['invoice_paid_amount'] ?? 0), 2),
                'finalize_attempt_count' => (int) ($r['finalize_attempt_count'] ?? 0),
                'finalize_last_received_at' => $r['finalize_last_received_at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Staff fallback: auth + branch guard, then {@see PublicCommerceFulfillmentReconciler::reconcile} with
     * {@see PublicCommerceFulfillmentReconcilerContract::TRIGGER_STAFF_MANUAL_SYNC}. Does not post payments.
     *
     * @return array{ok: bool, data?: array<string, mixed>, error_code?: string, message?: string}
     */
    public function staffTrustedFulfillmentSync(int $invoiceId): array
    {
        $actorId = $this->session->id();
        if ($actorId === null) {
            return ['ok' => false, 'error_code' => 'unauthenticated', 'message' => 'Authentication required.'];
        }
        if ($invoiceId <= 0) {
            return ['ok' => false, 'error_code' => 'invalid_invoice', 'message' => 'Invalid invoice.'];
        }

        $inv = $this->invoiceRepo->find($invoiceId);
        if (!$inv) {
            return ['ok' => false, 'error_code' => 'not_found', 'message' => 'Invoice not found.'];
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity(isset($inv['branch_id']) && $inv['branch_id'] !== '' && $inv['branch_id'] !== null ? (int) $inv['branch_id'] : null);

        $purchase = $this->purchases->findCorrelatedToInvoiceRow($inv, $invoiceId);
        if ($purchase === null) {
            return ['ok' => false, 'error_code' => 'not_public_commerce', 'message' => 'No public commerce purchase is linked to this invoice.'];
        }

        try {
            $result = $this->fulfillmentReconciler->reconcile(
                $invoiceId,
                PublicCommerceFulfillmentReconcilerContract::TRIGGER_STAFF_MANUAL_SYNC,
                $actorId
            );
        } catch (\Throwable $e) {
            slog('error', 'public-commerce.staff_reconcile', 'Fulfillment reconciliation failed.', [
                'invoice_id' => $invoiceId,
                'exception' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error_code' => 'reconcile_failed', 'message' => 'Fulfillment reconciliation failed.'];
        }

        $this->fulfillmentReconcileRecovery->recordPostReconcileOutcome(
            $invoiceId,
            PublicCommerceFulfillmentReconcilerContract::TRIGGER_STAFF_MANUAL_SYNC,
            $actorId,
            $result
        );

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_ERROR) {
            return ['ok' => false, 'error_code' => 'reconcile_failed', 'message' => 'Fulfillment reconciliation failed.'];
        }

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_NOOP_NO_PURCHASE) {
            return ['ok' => false, 'error_code' => 'not_public_commerce', 'message' => 'No public commerce purchase is linked to this invoice.'];
        }

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_SKIPPED
            && ($result['reason'] ?? '') === PublicCommerceFulfillmentReconcilerContract::REASON_INVOICE_NOT_PAID) {
            return [
                'ok' => false,
                'error_code' => 'invoice_not_paid',
                'message' => 'Record payment in Sales until the invoice status is paid; anonymous finalize never creates trusted payment.',
            ];
        }

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_BLOCKED) {
            return ['ok' => false, 'error_code' => 'purchase_terminal', 'message' => 'This purchase cannot be fulfilled in its current state.'];
        }

        $after = $this->purchases->findCorrelatedToInvoiceRow($inv, $invoiceId) ?? $purchase;
        $afterStatus = (string) ($after['status'] ?? '');

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_SKIPPED
            && ($result['reason'] ?? '') === PublicCommerceFulfillmentReconcilerContract::REASON_PURCHASE_ROW_MISSING) {
            return ['ok' => false, 'error_code' => 'reconcile_failed', 'message' => 'Fulfillment reconciliation failed.'];
        }

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_SKIPPED
            && ($result['reason'] ?? '') === PublicCommerceFulfillmentReconcilerContract::REASON_ALREADY_FULFILLED) {
            return ['ok' => true, 'data' => ['state' => 'already_complete', 'purchase_status' => $afterStatus]];
        }

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_APPLIED) {
            return ['ok' => true, 'data' => ['state' => 'fulfillment_applied', 'purchase_status' => $afterStatus]];
        }

        if (($result['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_STILL_PENDING) {
            return [
                'ok' => true,
                'data' => [
                    'state' => 'pending_internal_prerequisite',
                    'purchase_status' => $afterStatus,
                    'message' => 'Invoice is paid; fulfillment is deferred (e.g. membership not activated yet). Completing membership activation triggers an automatic reconciliation; staff sync uses the same path.',
                ],
            ];
        }

        return ['ok' => false, 'error_code' => 'reconcile_failed', 'message' => 'Fulfillment reconciliation failed.'];
    }

    /**
     * @return array{ok: true, pc: array}|array{ok: false, public_message: string}
     */
    public function requireBranchAnonymousPublicCommerceApi(int $branchId, string $endpoint = 'unknown'): array
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM branches WHERE id = ? AND deleted_at IS NULL',
            [$branchId]
        );
        if (!$row) {
            return ['ok' => false, 'public_message' => 'Branch not found or inactive.'];
        }
        if ($this->lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId)) {
            return ['ok' => false, 'public_message' => self::ERROR_ORGANIZATION_SUSPENDED];
        }
        if ($this->settings->isPlatformFounderKillPublicCommerce()) {
            return ['ok' => false, 'public_message' => 'Public purchases are temporarily unavailable.'];
        }
        if ($this->settings->isPlatformFounderKillAnonymousPublicApis()) {
            return ['ok' => false, 'public_message' => 'Public purchase API is temporarily unavailable.'];
        }
        $pc = $this->settings->getPublicCommerceSettings($branchId);
        if (!$pc['enabled']) {
            return ['ok' => false, 'public_message' => 'Public purchases are not enabled for this branch.'];
        }
        if (!$pc['public_api_enabled']) {
            return ['ok' => false, 'public_message' => 'Public purchase API is not available for this branch.'];
        }

        return ['ok' => true, 'pc' => $pc];
    }

    /**
     * @return array{success: bool, data?: array, public_message?: string}
     */
    public function getCatalog(int $branchId): array
    {
        $gate = $this->requireBranchAnonymousPublicCommerceApi($branchId, 'catalog');
        if (!$gate['ok']) {
            return ['success' => false, 'public_message' => $gate['public_message']];
        }
        $pc = $gate['pc'];
        $currency = $this->settings->getEffectiveCurrencyCode($branchId);
        $items = [];
        if ($pc['allow_gift_cards']) {
            $items[] = [
                'kind' => 'gift_card',
                'rules' => [
                    'min_amount' => $pc['gift_card_min_amount'],
                    'max_amount' => $pc['gift_card_max_amount'],
                    'currency' => $currency,
                ],
            ];
        }
        if ($pc['allow_packages']) {
            foreach ($this->packages->listPublicPurchasableForBranch($branchId) as $p) {
                $items[] = [
                    'kind' => 'package',
                    'id' => (int) $p['id'],
                    'name' => (string) ($p['name'] ?? ''),
                    'description' => $p['description'] ?? null,
                    'price' => round((float) ($p['price'] ?? 0), 2),
                    'currency' => $currency,
                    'total_sessions' => (int) ($p['total_sessions'] ?? 0),
                    'validity_days' => isset($p['validity_days']) && $p['validity_days'] !== null ? (int) $p['validity_days'] : null,
                    'branch_id' => isset($p['branch_id']) && $p['branch_id'] !== null ? (int) $p['branch_id'] : null,
                ];
            }
        }
        if ($pc['allow_memberships']) {
            foreach ($this->membershipDefinitions->listPublicPurchasableForBranch($branchId) as $m) {
                $items[] = [
                    'kind' => 'membership',
                    'id' => (int) $m['id'],
                    'name' => (string) ($m['name'] ?? ''),
                    'description' => $m['description'] ?? null,
                    'price' => round((float) ($m['price'] ?? 0), 2),
                    'currency' => $currency,
                    'duration_days' => (int) ($m['duration_days'] ?? 0),
                    'branch_id' => isset($m['branch_id']) && $m['branch_id'] !== null ? (int) $m['branch_id'] : null,
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'branch_id' => $branchId,
                'currency' => $currency,
                'items' => $items,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, data?: array<string, mixed>, public_message?: string}
     */
    public function initiatePurchase(int $branchId, array $body): array
    {
        $gate = $this->requireBranchAnonymousPublicCommerceApi($branchId, 'purchase_initiate');
        if (!$gate['ok']) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        $pc = $gate['pc'];
        $kind = strtolower(trim((string) ($body['product_kind'] ?? '')));
        if (!in_array($kind, ['gift_card', 'package', 'membership'], true)) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        if ($kind === 'gift_card' && !$pc['allow_gift_cards']) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        if ($kind === 'package' && !$pc['allow_packages']) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        if ($kind === 'membership' && !$pc['allow_memberships']) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }

        $clientPayload = [
            'first_name' => $body['first_name'] ?? '',
            'last_name' => $body['last_name'] ?? '',
            'email' => $body['email'] ?? '',
            'phone' => $body['phone'] ?? null,
        ];

        $pdo = $this->db->connection();
        $tokenPlain = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenPlain);

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                $resolved = $this->publicClientResolution->resolve(
                    $branchId,
                    $clientPayload,
                    'public_commerce',
                    (bool) $pc['allow_new_clients']
                );
                $clientId = $resolved['client_id'];
            } catch (\InvalidArgumentException) {
                $pdo->rollBack();
                return ['success' => false, 'public_message' => self::ERROR_GENERIC];
            }

            $packageId = null;
            $membershipDefinitionId = null;
            $giftCardAmount = null;
            $membershipSaleId = null;
            $invoiceId = 0;
            $packageSnapshotJson = null;

            if ($kind === 'gift_card') {
                $amt = round((float) ($body['gift_card_amount'] ?? 0), 2);
                if (!is_finite($amt) || $amt < $pc['gift_card_min_amount'] - 0.0001 || $amt > $pc['gift_card_max_amount'] + 0.0001) {
                    $pdo->rollBack();
                    return ['success' => false, 'public_message' => self::ERROR_GENERIC];
                }
                $giftCardAmount = $amt;
                $invoiceId = Application::container()->get(\Modules\Sales\Services\InvoiceService::class)->create([
                    'branch_id' => $branchId,
                    'client_id' => $clientId,
                    'appointment_id' => null,
                    'status' => 'open',
                    'notes' => 'public_commerce:gift_card',
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'items' => [
                        [
                            'item_type' => 'manual',
                            'source_id' => null,
                            'description' => sprintf('Gift card (public purchase) — %s %s', $this->settings->getEffectiveCurrencyCode($branchId), number_format($amt, 2, '.', '')),
                            'quantity' => 1,
                            'unit_price' => $amt,
                            'discount_amount' => 0,
                            'tax_rate' => 0,
                        ],
                    ],
                ]);
            } elseif ($kind === 'package') {
                $pid = (int) ($body['package_id'] ?? 0);
                $pkg = $pid > 0 ? $this->packages->findBranchOwnedPublicPurchasable($pid, $branchId) : null;
                if (!$pkg) {
                    $pdo->rollBack();
                    return ['success' => false, 'public_message' => self::ERROR_GENERIC];
                }
                $price = round((float) ($pkg['price'] ?? 0), 2);
                if ($price <= 0) {
                    $pdo->rollBack();
                    return ['success' => false, 'public_message' => self::ERROR_GENERIC];
                }
                $packageId = $pid;
                $packageSnapshotJson = PackageEntitlementSnapshot::encode(
                    PackageEntitlementSnapshot::fromPackageRow($pkg, $branchId)
                );
                $pname = trim((string) ($pkg['name'] ?? 'Package'));
                $invoiceId = Application::container()->get(\Modules\Sales\Services\InvoiceService::class)->create([
                    'branch_id' => $branchId,
                    'client_id' => $clientId,
                    'appointment_id' => null,
                    'status' => 'open',
                    'notes' => 'public_commerce:package_id=' . $pid,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'items' => [
                        [
                            'item_type' => 'manual',
                            'source_id' => null,
                            'description' => sprintf('Package: %s (public purchase)', $pname),
                            'quantity' => 1,
                            'unit_price' => $price,
                            'discount_amount' => 0,
                            'tax_rate' => 0,
                        ],
                    ],
                ]);
            } else {
                $mid = (int) ($body['membership_definition_id'] ?? 0);
                $def = $mid > 0 ? $this->membershipDefinitions->findBranchOwnedPublicPurchasable($mid, $branchId) : null;
                if (!$def) {
                    $pdo->rollBack();
                    return ['success' => false, 'public_message' => self::ERROR_GENERIC];
                }
                $membershipDefinitionId = $mid;
                $startsAt = trim((string) ($body['membership_starts_at'] ?? ''));
                if ($startsAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt) !== 1) {
                    $pdo->rollBack();
                    return ['success' => false, 'public_message' => self::ERROR_GENERIC];
                }
                $saleResult = Application::container()->get(MembershipSaleService::class)->createSaleAndInvoice([
                    'membership_definition_id' => $mid,
                    'client_id' => $clientId,
                    'branch_id' => $branchId,
                    'starts_at' => $startsAt,
                ], ['public_commerce_checkout' => true]);
                $invoiceId = (int) $saleResult['invoice_id'];
                $membershipSaleId = (int) $saleResult['membership_sale_id'];
            }

            $purchaseId = $this->purchases->insert([
                'token_hash' => $tokenHash,
                'branch_id' => $branchId,
                'client_id' => $clientId,
                'client_resolution_reason' => $resolved['reason'],
                'product_kind' => $kind,
                'package_id' => $packageId,
                'membership_definition_id' => $membershipDefinitionId,
                'package_snapshot_json' => $packageSnapshotJson,
                'gift_card_amount' => $giftCardAmount,
                'membership_sale_id' => $membershipSaleId,
                'invoice_id' => $invoiceId,
                'status' => self::PURCHASE_INITIATED,
            ]);

            $this->audit->log('public_commerce_purchase_initiated', 'public_commerce_purchase', $purchaseId, null, $branchId, [
                'invoice_id' => $invoiceId,
                'product_kind' => $kind,
                'client_id' => $clientId,
            ]);

            $pdo->commit();
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }

        $inv = $this->invoiceRepo->findForPublicCommerceCorrelatedBranch($invoiceId, $branchId);
        $total = $inv ? round((float) ($inv['total_amount'] ?? 0), 2) : 0.0;
        $paid = $inv ? round((float) ($inv['paid_amount'] ?? 0), 2) : 0.0;

        return [
            'success' => true,
            'data' => [
                'confirmation_token' => $tokenPlain,
                'purchase_id' => $purchaseId,
                'invoice_id' => $invoiceId,
                'product_kind' => $kind,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'balance_due' => max(0.0, round($total - $paid, 2)),
                'currency' => $this->settings->getEffectiveCurrencyCode($branchId),
                'invoice_status' => $inv['status'] ?? null,
                'membership_sale_id' => $membershipSaleId,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{success: bool, data?: array<string, mixed>, public_message?: string}
     */
    public function finalizePurchase(array $body): array
    {
        $token = trim((string) ($body['confirmation_token'] ?? ''));
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        $hash = hash('sha256', $token);
        $requestHash = $this->hashFinalizePayload($body);

        $pdo = $this->db->connection();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        } catch (\Throwable) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }

        try {
            $purchase = $this->purchases->findForUpdateByTokenHash($hash);
            if ($purchase === null) {
                $pdo->rollBack();

                return ['success' => false, 'public_message' => self::ERROR_GENERIC];
            }

            $branchId = (int) ($purchase['branch_id'] ?? 0);
            $gate = $this->requireBranchAnonymousPublicCommerceApi($branchId, 'purchase_finalize');
            if (!$gate['ok']) {
                $pdo->rollBack();

                return ['success' => false, 'public_message' => self::ERROR_GENERIC];
            }

            $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
            $inv = $this->invoiceRepo->findForPublicCommerceCorrelatedBranch($invoiceId, $branchId);
            if (!$inv) {
                $pdo->rollBack();

                return ['success' => false, 'public_message' => self::ERROR_GENERIC];
            }

            $purchaseId = (int) ($purchase['id'] ?? 0);
            $pStatus = (string) ($purchase['status'] ?? '');

            if ($pStatus === self::PURCHASE_FAILED || $pStatus === self::PURCHASE_CANCELLED) {
                $this->audit->log('public_commerce_finalize_blocked', 'public_commerce_purchase', $purchaseId, null, $branchId, [
                    'reason' => 'purchase_terminal_state',
                    'purchase_status' => $pStatus,
                    'invoice_id' => $invoiceId,
                ]);
                $pdo->rollBack();

                return ['success' => false, 'public_message' => self::ERROR_GENERIC];
            }

            $total = round((float) ($inv['total_amount'] ?? 0), 2);
            $paidAmt = round((float) ($inv['paid_amount'] ?? 0), 2);
            $balanceDue = max(0.0, round($total - $paidAmt, 2));
            $invStatus = (string) ($inv['status'] ?? '');
            $invoicePaid = $balanceDue <= 0.0001 || $invStatus === 'paid';

            if (
                $pStatus === self::PURCHASE_PAID
                && !empty($purchase['fulfillment_applied_at'])
                && $invoicePaid
            ) {
                $pdo->commit();
                if ($this->isFulfillmentReconcileRecoveryPending($purchase)) {
                    return $this->buildRecoveryPendingFinalizeResponse($purchase, $inv);
                }

                return $this->buildFinalizeResponse($purchase, $inv, true);
            }

            if ($invoicePaid) {
                $reconcileResult = $this->fulfillmentReconciler->reconcile(
                    $invoiceId,
                    PublicCommerceFulfillmentReconcilerContract::TRIGGER_PUBLIC_FINALIZE_TRUSTED_INVOICE,
                    null
                );
                $this->fulfillmentReconcileRecovery->recordPostReconcileOutcome(
                    $invoiceId,
                    PublicCommerceFulfillmentReconcilerContract::TRIGGER_PUBLIC_FINALIZE_TRUSTED_INVOICE,
                    null,
                    $reconcileResult
                );
                $purchase = $this->purchases->findByTokenHash($hash) ?? $purchase;
                $inv = $this->invoiceRepo->findForPublicCommerceCorrelatedBranch($invoiceId, $branchId) ?? $inv;
                $pdo->commit();

                if (($reconcileResult['outcome'] ?? '') === PublicCommerceFulfillmentReconcilerContract::OUTCOME_ERROR) {
                    return $this->buildRecoveryPendingFinalizeResponse($purchase, $inv);
                }

                return $this->buildFinalizeResponse($purchase, $inv, true);
            }

            if ($pStatus === self::PURCHASE_AWAITING_VERIFICATION
                && ($purchase['finalize_last_request_hash'] ?? '') === $requestHash
            ) {
                $this->audit->log('public_commerce_finalize_idempotent', 'public_commerce_purchase', $purchaseId, null, $branchId, [
                    'invoice_id' => $invoiceId,
                    'request_hash' => $requestHash,
                ]);
                $pdo->commit();

                return $this->buildAwaitingVerificationResponse($purchase, $inv);
            }

            $prevCount = (int) ($purchase['finalize_attempt_count'] ?? 0);
            $newCount = $prevCount + 1;
            $prevStoredHash = (string) ($purchase['finalize_last_request_hash'] ?? '');

            $this->purchases->update($purchaseId, [
                'status' => self::PURCHASE_AWAITING_VERIFICATION,
                'finalize_attempt_count' => $newCount,
                'finalize_last_request_hash' => $requestHash,
                'finalize_last_received_at' => date('Y-m-d H:i:s'),
            ]);

            $this->audit->log('public_commerce_finalize_recorded', 'public_commerce_purchase', $purchaseId, null, $branchId, [
                'invoice_id' => $invoiceId,
                'request_hash' => $requestHash,
                'attempt' => $newCount,
                'supersedes_prior_hash' => $prevStoredHash !== '' && $prevStoredHash !== $requestHash,
                'note' => 'client_payload_not_trusted_no_payment_created',
            ]);

            $purchase = $this->purchases->findByTokenHash($hash) ?? $purchase;
            $pdo->commit();

            return $this->buildAwaitingVerificationResponse($purchase, $inv);
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, public_message?: string}
     */
    public function getPurchaseStatus(string $tokenPlain): array
    {
        $token = trim($tokenPlain);
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        $hash = hash('sha256', $token);
        $purchase = $this->purchases->findByTokenHash($hash);
        if ($purchase === null) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        $branchId = (int) ($purchase['branch_id'] ?? 0);
        $gate = $this->requireBranchAnonymousPublicCommerceApi($branchId, 'purchase_status');
        if (!$gate['ok']) {
            return ['success' => false, 'public_message' => self::ERROR_GENERIC];
        }
        $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
        $inv = $this->invoiceRepo->findForPublicCommerceCorrelatedBranch($invoiceId, $branchId);

        $recoveryPending = $this->isFulfillmentReconcileRecoveryPending($purchase);

        $data = [
            'purchase_status' => (string) ($purchase['status'] ?? ''),
            'checkout_state' => $this->resolveCheckoutState($purchase, $inv),
            'fulfillment_reconcile_recovery_pending' => $recoveryPending,
            'product_kind' => (string) ($purchase['product_kind'] ?? ''),
            'invoice_id' => $invoiceId,
            'invoice_status' => $inv['status'] ?? null,
            'total_amount' => $inv ? round((float) ($inv['total_amount'] ?? 0), 2) : null,
            'paid_amount' => $inv ? round((float) ($inv['paid_amount'] ?? 0), 2) : null,
            'balance_due' => $inv ? max(0.0, round((float) ($inv['total_amount'] ?? 0) - (float) ($inv['paid_amount'] ?? 0), 2)) : null,
            'currency' => $this->settings->getEffectiveCurrencyCode($branchId),
            'finalize_attempt_count' => (int) ($purchase['finalize_attempt_count'] ?? 0),
            'fulfillment' => $this->fulfillmentSummary($purchase),
        ];
        if ($recoveryPending) {
            $data['message'] = 'Payment was recorded, but fulfillment needs staff verification before this purchase is complete. Please contact the spa with your confirmation details.';
        }

        return ['success' => true, 'data' => $data];
    }

    public function syncFulfillmentForInvoice(int $invoiceId, ?string $syncSource = null): void
    {
        $trigger = $syncSource ?? PublicCommerceFulfillmentReconcilerContract::TRIGGER_INVOICE_SETTLEMENT;
        try {
            $result = $this->fulfillmentReconciler->reconcile($invoiceId, $trigger, null);
            $this->fulfillmentReconcileRecovery->recordPostReconcileOutcome($invoiceId, $trigger, null, $result);
        } catch (\Throwable $e) {
            slog('error', 'public-commerce.sync_fulfillment', 'syncFulfillmentForInvoice failed.', [
                'invoice_id' => $invoiceId,
                'exception' => $e->getMessage(),
            ]);
            $this->fulfillmentReconcileRecovery->persistRecoveryAfterReconcileThrowable($invoiceId, $trigger, null, $e);
        }
    }

    /** @param array<string, mixed> $purchase */
    private function fulfillmentSummary(array $purchase): array
    {
        $kind = (string) ($purchase['product_kind'] ?? '');
        $out = [
            'state' => (string) ($purchase['status'] ?? ''),
            'membership_sale_id' => isset($purchase['membership_sale_id']) && (int) $purchase['membership_sale_id'] > 0 ? (int) $purchase['membership_sale_id'] : null,
            'client_package_id' => isset($purchase['client_package_id']) && (int) $purchase['client_package_id'] > 0 ? (int) $purchase['client_package_id'] : null,
            'gift_card_id' => isset($purchase['gift_card_id']) && (int) $purchase['gift_card_id'] > 0 ? (int) $purchase['gift_card_id'] : null,
        ];
        if ($this->isFulfillmentReconcileRecoveryPending($purchase)) {
            $out['fulfillment_reconcile_recovery_pending'] = true;
            $out['receipt_safe'] = [
                'product' => $kind,
                'pending' => true,
                'operator_repair_required' => true,
            ];

            return $out;
        }
        if ($kind === 'gift_card' && $out['gift_card_id'] === null) {
            $out['receipt_safe'] = ['product' => 'gift_card', 'pending' => true];
        } elseif ($kind === 'gift_card' && $out['gift_card_id'] !== null) {
            $out['receipt_safe'] = ['product' => 'gift_card', 'gift_card_id' => $out['gift_card_id']];
        } elseif ($kind === 'package') {
            $out['receipt_safe'] = ['product' => 'package', 'client_package_id' => $out['client_package_id']];
        } else {
            $out['receipt_safe'] = ['product' => 'membership', 'membership_sale_id' => $out['membership_sale_id']];
        }

        return $out;
    }

    /** @param array<string, mixed> $purchase */
    private function buildFinalizeResponse(array $purchase, array $inv, bool $commerciallyComplete = true): array
    {
        if ($commerciallyComplete && $this->isFulfillmentReconcileRecoveryPending($purchase)) {
            return $this->buildRecoveryPendingFinalizeResponse($purchase, $inv);
        }
        $branchId = (int) ($purchase['branch_id'] ?? 0);
        $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);

        return [
            'success' => true,
            'data' => [
                'checkout_state' => $commerciallyComplete ? 'complete' : 'in_progress',
                'verification_required' => false,
                'fulfillment_reconcile_recovery_pending' => $this->isFulfillmentReconcileRecoveryPending($purchase),
                'invoice_id' => $invoiceId,
                'invoice_status' => (string) ($inv['status'] ?? ''),
                'total_amount' => $total,
                'paid_amount' => $paid,
                'balance_due' => max(0.0, round($total - $paid, 2)),
                'currency' => $this->settings->getEffectiveCurrencyCode($branchId),
                'purchase_status' => (string) ($purchase['status'] ?? ''),
                'finalize_attempt_count' => (int) ($purchase['finalize_attempt_count'] ?? 0),
                'fulfillment' => $this->fulfillmentSummary($purchase),
            ],
        ];
    }

    /**
     * Fail-closed: invoice may be paid while fulfillment reconcile failed post-commit (H-001).
     *
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $inv
     * @return array{success: true, data: array<string, mixed>}
     */
    private function buildRecoveryPendingFinalizeResponse(array $purchase, array $inv): array
    {
        $branchId = (int) ($purchase['branch_id'] ?? 0);
        $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);

        return [
            'success' => true,
            'data' => [
                'checkout_state' => 'fulfillment_reconcile_recovery_pending',
                'verification_required' => true,
                'fulfillment_reconcile_recovery_pending' => true,
                'message' => 'Payment was recorded, but fulfillment needs staff verification before this purchase is complete. Please contact the spa with your confirmation details.',
                'staff_operational_paths' => [
                    'list_awaiting_verification' => '/sales/public-commerce/awaiting-verification',
                    'sync_fulfillment_post' => '/sales/public-commerce/invoices/{invoice_id}/sync-fulfillment',
                ],
                'invoice_id' => $invoiceId,
                'invoice_status' => (string) ($inv['status'] ?? ''),
                'total_amount' => $total,
                'paid_amount' => $paid,
                'balance_due' => max(0.0, round($total - $paid, 2)),
                'currency' => $this->settings->getEffectiveCurrencyCode($branchId),
                'purchase_status' => (string) ($purchase['status'] ?? ''),
                'finalize_attempt_count' => (int) ($purchase['finalize_attempt_count'] ?? 0),
                'fulfillment' => $this->fulfillmentSummary($purchase),
            ],
        ];
    }

    /** @param array<string, mixed> $purchase */
    private function buildAwaitingVerificationResponse(array $purchase, array $inv): array
    {
        $branchId = (int) ($purchase['branch_id'] ?? 0);
        $invoiceId = (int) ($purchase['invoice_id'] ?? 0);
        $total = round((float) ($inv['total_amount'] ?? 0), 2);
        $paid = round((float) ($inv['paid_amount'] ?? 0), 2);

        return [
            'success' => true,
            'data' => [
                'checkout_state' => 'awaiting_verification',
                'verification_required' => true,
                'unsupported_trusted_completion_via_public_finalize' => true,
                'staff_operational_paths' => [
                    'list_awaiting_verification' => '/sales/public-commerce/awaiting-verification',
                    'sync_fulfillment_post' => '/sales/public-commerce/invoices/{invoice_id}/sync-fulfillment',
                    'record_trusted_payment' => '/sales/invoices/{invoice_id}/payments/create',
                ],
                'message' => 'Payment is not confirmed from this endpoint. Staff records trusted payment on the linked invoice in Sales (invoice becomes paid). For memberships, the purchase reaches paid when the membership sale activates internally; until then it may show payment received with fulfillment pending. Staff sync-fulfillment remains available as a fallback.',
                'invoice_id' => $invoiceId,
                'invoice_status' => (string) ($inv['status'] ?? ''),
                'total_amount' => $total,
                'paid_amount' => $paid,
                'balance_due' => max(0.0, round($total - $paid, 2)),
                'currency' => $this->settings->getEffectiveCurrencyCode($branchId),
                'purchase_status' => self::PURCHASE_AWAITING_VERIFICATION,
                'finalize_attempt_count' => (int) ($purchase['finalize_attempt_count'] ?? 0),
                'fulfillment' => $this->fulfillmentSummary($purchase),
            ],
        ];
    }

    /** @param array<string, mixed> $body */
    private function hashFinalizePayload(array $body): string
    {
        $subset = [
            'amount' => isset($body['amount']) ? (string) $body['amount'] : '',
            'payment_method' => isset($body['payment_method']) ? trim((string) $body['payment_method']) : '',
            'payment_status' => isset($body['payment_status']) ? strtolower(trim((string) $body['payment_status'])) : '',
            'transaction_reference' => isset($body['transaction_reference']) ? trim((string) $body['transaction_reference']) : '',
        ];
        ksort($subset);

        return hash('sha256', json_encode($subset, JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed>|null $inv */
    private function resolveCheckoutState(array $purchase, ?array $inv): string
    {
        if ($this->isFulfillmentReconcileRecoveryPending($purchase)) {
            return 'fulfillment_reconcile_recovery_pending';
        }
        $ps = (string) ($purchase['status'] ?? '');
        if ($ps === self::PURCHASE_PAID && !empty($purchase['fulfillment_applied_at'])) {
            return 'complete';
        }
        if ($inv !== null) {
            $total = round((float) ($inv['total_amount'] ?? 0), 2);
            $paid = round((float) ($inv['paid_amount'] ?? 0), 2);
            $balanceDue = max(0.0, round($total - $paid, 2));
            $invStatus = (string) ($inv['status'] ?? '');
            if ($balanceDue <= 0.0001 || $invStatus === 'paid') {
                return 'payment_received_pending_fulfillment';
            }
        }
        if ($ps === self::PURCHASE_AWAITING_VERIFICATION) {
            return 'awaiting_verification';
        }

        return 'initiated';
    }

    /** @param array<string, mixed> $purchase */
    private function isFulfillmentReconcileRecoveryPending(array $purchase): bool
    {
        $raw = $purchase['fulfillment_reconcile_recovery_at'] ?? null;

        return $raw !== null && $raw !== '';
    }
}
