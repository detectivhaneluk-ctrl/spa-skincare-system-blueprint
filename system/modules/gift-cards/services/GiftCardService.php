<?php

declare(strict_types=1);

namespace Modules\GiftCards\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\GiftCards\Repositories\GiftCardRepository;
use Modules\GiftCards\Repositories\GiftCardTransactionRepository;

final class GiftCardService
{
    public const STATUSES = ['active', 'used', 'expired', 'cancelled'];
    public const TX_TYPES = ['issue', 'redeem', 'adjustment', 'expire', 'cancel'];

    public function __construct(
        private GiftCardRepository $cards,
        private GiftCardTransactionRepository $transactions,
        private Database $db,
        private SettingsService $settings,
        private AuditService $audit,
        private BranchContext $branchContext,
        private ClientRepository $clients
    ) {
    }

    public function issueGiftCard(array $data): int
    {
        $amount = (float) ($data['original_amount'] ?? 0);
        if ($amount <= 0) {
            throw new \DomainException('Issue amount must be greater than zero.');
        }

        $data = $this->branchContext->enforceBranchOnCreate($data);
        $branchId = $this->requirePositiveBranchContext(
            isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : $this->branchContext->getCurrentBranchId()
        );
        $data['branch_id'] = $branchId;
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            $code = $this->generateCode();
        }
        $currency = trim((string) ($data['currency'] ?? ''));
        if ($currency === '') {
            $currency = $this->settings->getEffectiveCurrencyCode($branchId !== null ? (int) $branchId : null);
        }

        return $this->transactional(function () use ($data, $amount, $branchId, $code, $currency): int {
            $cid = isset($data['client_id']) && $data['client_id'] !== '' && $data['client_id'] !== null
                ? (int) $data['client_id']
                : 0;
            if ($cid > 0) {
                if ($this->clients->findLiveForUpdateOnBranch($cid, $branchId) === null) {
                    throw new \DomainException('Client not found or not assignable on this branch.');
                }
            }

            if ($this->cards->findByCodeForUniquenessCheck($code, true)) {
                throw new \DomainException('Gift card code already exists.');
            }

            $userId = $this->currentUserId();
            $issuedAt = $data['issued_at'] ?? date('Y-m-d H:i:s');
            $cardId = $this->cards->create([
                'branch_id' => $branchId,
                'client_id' => $data['client_id'] ?? null,
                'code' => $code,
                'original_amount' => $amount,
                'currency' => strtoupper($currency),
                'issued_at' => $issuedAt,
                'expires_at' => $data['expires_at'] ?? null,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->transactions->create([
                'gift_card_id' => $cardId,
                'branch_id' => $branchId,
                'type' => 'issue',
                'amount' => $amount,
                'balance_after' => $amount,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->audit->log('gift_card_issued', 'gift_card', $cardId, $userId, $branchId !== null ? (int) $branchId : null, [
                'code' => $code,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'client_id' => $data['client_id'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);
            return $cardId;
        }, 'gift card issue');
    }

    public function redeemGiftCard(int $giftCardId, float $amount, array $context = []): void
    {
        if ($amount <= 0) {
            throw new \DomainException('Redeem amount must be greater than zero.');
        }

        $this->transactional(function () use ($giftCardId, $amount, $context): void {
            $operationBranchId = $this->requirePositiveBranchContext($context['branch_id'] ?? $this->branchContext->getCurrentBranchId());
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            $this->expireGiftCardIfNeededFromRow($card, $operationBranchId);
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            if (($card['status'] ?? '') !== 'active') {
                throw new \DomainException('Only active gift cards can be redeemed.');
            }

            $currentBalance = $this->getCurrentBalance((int) $card['id'], $operationBranchId);
            if ($amount > $currentBalance) {
                throw new \DomainException('Redeem amount exceeds current balance.');
            }
            $newBalance = $currentBalance - $amount;

            $userId = $this->currentUserId();
            $this->transactions->create([
                'gift_card_id' => (int) $card['id'],
                'branch_id' => $card['branch_id'] ?? null,
                'type' => 'redeem',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'notes' => $context['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $nextStatus = $newBalance <= 0 ? 'used' : 'active';
            $this->cards->updateInTenantScope((int) $card['id'], $operationBranchId, [
                'status' => $nextStatus,
                'updated_by' => $userId,
            ]);

            $this->audit->log('gift_card_redeemed', 'gift_card', (int) $card['id'], $userId, $card['branch_id'] !== null ? (int) $card['branch_id'] : null, [
                'redeem_amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
            ]);
        }, 'gift card redeem');
    }

    public function adjustGiftCard(int $giftCardId, float $delta, array $context = []): void
    {
        if ($delta == 0.0) {
            throw new \DomainException('Adjustment amount cannot be zero.');
        }

        $this->transactional(function () use ($giftCardId, $delta, $context): void {
            $operationBranchId = $this->requirePositiveBranchContext($context['branch_id'] ?? $this->branchContext->getCurrentBranchId());
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            $this->expireGiftCardIfNeededFromRow($card, $operationBranchId);
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            if (($card['status'] ?? '') === 'cancelled') {
                throw new \DomainException('Cancelled gift cards cannot be adjusted.');
            }

            $currentBalance = $this->getCurrentBalance((int) $card['id'], $operationBranchId);
            $newBalance = $currentBalance + $delta;
            if ($newBalance < 0) {
                throw new \DomainException('Adjustment cannot make balance negative.');
            }

            $userId = $this->currentUserId();
            $this->transactions->create([
                'gift_card_id' => (int) $card['id'],
                'branch_id' => $card['branch_id'] ?? null,
                'type' => 'adjustment',
                'amount' => $delta,
                'balance_after' => $newBalance,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'notes' => $context['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $nextStatus = $newBalance <= 0 ? 'used' : 'active';
            if (($card['status'] ?? '') === 'expired') {
                $nextStatus = 'expired';
            }
            $this->cards->updateInTenantScope((int) $card['id'], $operationBranchId, [
                'status' => $nextStatus,
                'updated_by' => $userId,
            ]);

            $this->audit->log('gift_card_adjusted', 'gift_card', (int) $card['id'], $userId, $card['branch_id'] !== null ? (int) $card['branch_id'] : null, [
                'adjustment_amount' => $delta,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
            ]);
        }, 'gift card adjustment');
    }

    public function cancelGiftCard(int $giftCardId, ?string $notes = null, ?int $branchContext = null): void
    {
        $operationBranchId = $this->requirePositiveBranchContext($branchContext ?? $this->branchContext->getCurrentBranchId());
        $this->transactional(function () use ($giftCardId, $notes, $operationBranchId): void {
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            if (($card['status'] ?? '') === 'cancelled') {
                return;
            }
            if (($card['status'] ?? '') === 'expired') {
                throw new \DomainException('Expired gift cards cannot be cancelled.');
            }

            $balance = $this->getCurrentBalance((int) $card['id'], $operationBranchId);
            $userId = $this->currentUserId();

            $this->transactions->create([
                'gift_card_id' => (int) $card['id'],
                'branch_id' => $card['branch_id'] ?? null,
                'type' => 'cancel',
                'amount' => 0,
                'balance_after' => $balance,
                'reference_type' => null,
                'reference_id' => null,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $this->cards->updateInTenantScope((int) $card['id'], $operationBranchId, [
                'status' => 'cancelled',
                'updated_by' => $userId,
                'notes' => $notes ?: ($card['notes'] ?? null),
            ]);

            $this->audit->log('gift_card_cancelled', 'gift_card', (int) $card['id'], $userId, $card['branch_id'] !== null ? (int) $card['branch_id'] : null, [
                'balance_at_cancel' => $balance,
                'notes' => $notes,
            ]);
        }, 'gift card cancel');
    }

    public function expireGiftCardIfNeeded(int $giftCardId, ?int $branchContext = null): bool
    {
        $operationBranchId = $this->requirePositiveBranchContext($branchContext ?? $this->branchContext->getCurrentBranchId());
        return $this->transactional(function () use ($giftCardId, $operationBranchId): bool {
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            return $this->expireGiftCardIfNeededFromRow($card, $operationBranchId);
        }, 'gift card expire check');
    }

    /**
     * Set or clear expires_at for an active card (tenant-scoped). If the new expiry is already past, runs the same
     * auto-expiry transition as {@see expireGiftCardIfNeededFromRow} in the same transaction.
     *
     * @param string|null $expiresAtRaw empty string or null clears expiry; otherwise Y-m-d (end of day) or parseable datetime
     */
    public function updateExpiresAt(int $giftCardId, ?string $expiresAtRaw, int $operationBranchId): void
    {
        $operationBranchId = $this->requirePositiveBranchContext($operationBranchId);
        $normalized = $this->normalizeExpiresAtInput($expiresAtRaw);

        $this->transactional(function () use ($giftCardId, $operationBranchId, $normalized): void {
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            if (($card['status'] ?? '') !== 'active') {
                throw new \DomainException('Only active gift cards can have their expiration date changed.');
            }

            $issuedTs = strtotime((string) ($card['issued_at'] ?? ''));
            if ($normalized['sql'] !== null) {
                if ($issuedTs === false) {
                    throw new \DomainException('Gift card has an invalid issued_at; cannot set expiry.');
                }
                if (strtotime($normalized['sql']) <= $issuedTs) {
                    throw new \DomainException('Expiry must be after the card issue datetime.');
                }
            }

            $userId = $this->currentUserId();
            $this->cards->updateInTenantScope($giftCardId, $operationBranchId, [
                'expires_at' => $normalized['sql'],
                'updated_by' => $userId,
            ]);

            $this->audit->log('gift_card_expiry_updated', 'gift_card', $giftCardId, $userId, $card['branch_id'] !== null ? (int) $card['branch_id'] : null, [
                'expires_at' => $normalized['sql'],
                'cleared' => $normalized['sql'] === null,
            ]);

            $fresh = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if ($fresh) {
                $this->expireGiftCardIfNeededFromRow($fresh, $operationBranchId);
            }
        }, 'gift card expiry update');
    }

    /**
     * @param list<int> $giftCardIds
     * @return array{updated: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function bulkUpdateExpiresAt(array $giftCardIds, ?string $expiresAtRaw, int $operationBranchId, int $maxPerRequest = 100): array
    {
        $operationBranchId = $this->requirePositiveBranchContext($operationBranchId);
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int) $v, $giftCardIds), static fn (int $id): bool => $id > 0)));
        if (count($ids) > $maxPerRequest) {
            throw new \DomainException('Too many gift cards selected (max ' . $maxPerRequest . ' per request).');
        }

        $updated = [];
        $failed = [];
        foreach ($ids as $id) {
            try {
                $this->updateExpiresAt($id, $expiresAtRaw, $operationBranchId);
                $updated[] = $id;
            } catch (\Throwable $e) {
                $msg = $e instanceof \DomainException || $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Gift card operation failed.';
                $failed[] = ['id' => $id, 'reason' => $msg];
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    public function getCurrentBalance(int $giftCardId, ?int $branchContext = null): float
    {
        $resolvedBranchId = $this->requirePositiveBranchContext($branchContext ?? $this->branchContext->getCurrentBranchId());
        // Always prove tenant ownership via scoped parent load before trusting any FK-only transaction query.
        // FK-only latestForCard() is not self-defending; tenant proof cannot be deferred to a fallback path.
        $card = $this->cards->findInTenantScope($giftCardId, $resolvedBranchId);
        if (!$card) {
            return 0.0;
        }
        $latest = $this->transactions->latestForCard((int) $card['id']);
        if ($latest) {
            return (float) ($latest['balance_after'] ?? 0);
        }
        return (float) ($card['original_amount'] ?? 0);
    }

    public function listUsableForClient(int $clientId, ?int $branchContext = null): array
    {
        if ($branchContext === null || $branchContext <= 0) {
            return [];
        }
        if ($this->clients->find($clientId) === null) {
            return [];
        }
        $rows = $this->cards->listEligibleForClientInTenantScope($clientId, $branchContext);
        $usable = [];
        foreach ($rows as $row) {
            $cardId = (int) $row['id'];
            try {
                if ($branchContext !== null && $branchContext > 0) {
                    $this->expireGiftCardIfNeeded($cardId, $branchContext);
                }
            } catch (\Throwable) {
                // Keep list resilient; strict checks occur during redemption.
            }
            $fresh = $this->cards->findInTenantScope($cardId, $branchContext);
            if (!$fresh || ($fresh['status'] ?? '') !== 'active') {
                continue;
            }
            $balance = $this->getCurrentBalance($cardId, $branchContext);
            if ($balance <= 0) {
                continue;
            }
            $usable[] = [
                'gift_card_id' => $cardId,
                'code' => (string) ($fresh['code'] ?? ''),
                'client_id' => $fresh['client_id'] !== null ? (int) $fresh['client_id'] : null,
                'branch_id' => $fresh['branch_id'] !== null ? (int) $fresh['branch_id'] : null,
                'status' => (string) ($fresh['status'] ?? 'active'),
                'current_balance' => $balance,
                'currency' => (string) ($fresh['currency'] ?? 'USD'),
                'expires_at' => $fresh['expires_at'] ?? null,
            ];
        }
        return $usable;
    }

    public function getBalanceSummary(int $giftCardId): ?array
    {
        $operationBranchId = $this->requirePositiveBranchContext($this->branchContext->getCurrentBranchId());
        $card = $this->cards->findInTenantScope($giftCardId, $operationBranchId);
        if (!$card) {
            return null;
        }
        return [
            'gift_card_id' => (int) $card['id'],
            'status' => (string) ($card['status'] ?? 'active'),
            'current_balance' => $this->getCurrentBalance((int) $card['id'], $operationBranchId),
            'currency' => (string) ($card['currency'] ?? 'USD'),
            'expires_at' => $card['expires_at'] ?? null,
            'branch_id' => $card['branch_id'] !== null ? (int) $card['branch_id'] : null,
            'client_id' => $card['client_id'] !== null ? (int) $card['client_id'] : null,
        ];
    }

    public function hasInvoiceRedemption(int $invoiceId, int $giftCardId): bool
    {
        return $this->transactions->existsRedeemForInvoice($giftCardId, $invoiceId);
    }

    public function redeemForInvoice(
        int $invoiceId,
        int $clientId,
        int $giftCardId,
        float $amount,
        ?int $branchContext = null,
        ?string $notes = null
    ): void {
        if (!is_finite($amount) || $amount <= 0) {
            throw new \DomainException('Redeem amount must be greater than zero.');
        }
        $this->transactional(function () use ($invoiceId, $clientId, $giftCardId, $amount, $branchContext, $notes): void {
            $operationBranchId = $this->requirePositiveBranchContext($branchContext ?? $this->branchContext->getCurrentBranchId());
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            if (!empty($card['client_id']) && (int) $card['client_id'] !== $clientId) {
                throw new \DomainException('Gift card does not belong to invoice client.');
            }
            if ($this->transactions->existsRedeemForInvoice($giftCardId, $invoiceId)) {
                throw new \DomainException('This gift card is already redeemed for the invoice.');
            }
            $this->redeemGiftCard($giftCardId, $amount, [
                'branch_id' => $operationBranchId,
                'reference_type' => 'invoice',
                'reference_id' => $invoiceId,
                'notes' => $notes,
            ]);
        }, 'gift card invoice redeem');
    }

    public function refundInvoiceRedemption(
        int $invoiceId,
        int $giftCardId,
        float $amount,
        ?int $branchContext = null,
        ?string $notes = null,
        ?int $refundPaymentId = null
    ): void {
        if (!is_finite($amount) || $amount <= 0) {
            throw new \DomainException('Refund amount must be greater than zero.');
        }
        $this->transactional(function () use ($invoiceId, $giftCardId, $amount, $branchContext, $notes, $refundPaymentId): void {
            $operationBranchId = $this->requirePositiveBranchContext($branchContext ?? $this->branchContext->getCurrentBranchId());
            $card = $this->loadCardForUpdate($giftCardId, $operationBranchId);
            if (!$card) {
                throw new \RuntimeException('Gift card not found.');
            }
            if (in_array((string) ($card['status'] ?? ''), ['expired', 'cancelled'], true)) {
                throw new \DomainException('Cannot refund to expired/cancelled gift card.');
            }

            $refType = 'invoice_refund';
            $refId = $refundPaymentId ?? $invoiceId;
            if ($refundPaymentId !== null && $this->transactions->existsByTypeAndReference($giftCardId, 'adjustment', $refType, $refId)) {
                throw new \DomainException('Gift card refund adjustment is already recorded for this refund payment.');
            }

            $currentBalance = $this->getCurrentBalance($giftCardId, $operationBranchId);
            $newBalance = $currentBalance + $amount;
            $userId = $this->currentUserId();
            $this->transactions->create([
                'gift_card_id' => $giftCardId,
                'branch_id' => $card['branch_id'] ?? null,
                'type' => 'adjustment',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'notes' => $notes ?: 'Invoice redemption refund',
                'created_by' => $userId,
            ]);
            $this->cards->updateInTenantScope($giftCardId, $operationBranchId, [
                'status' => $newBalance > 0 ? 'active' : ((string) ($card['status'] ?? 'active')),
                'updated_by' => $userId,
            ]);
            $this->audit->log('gift_card_invoice_refunded', 'gift_card', $giftCardId, $userId, $card['branch_id'] !== null ? (int) $card['branch_id'] : null, [
                'invoice_id' => $invoiceId,
                'refund_payment_id' => $refundPaymentId,
                'refund_amount' => round($amount, 2),
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
            ]);
        }, 'gift card invoice refund');
    }

    /**
     * @return array{sql: string|null}
     */
    private function normalizeExpiresAtInput(?string $expiresAtRaw): array
    {
        if ($expiresAtRaw === null) {
            return ['sql' => null];
        }
        $raw = trim($expiresAtRaw);
        if ($raw === '') {
            return ['sql' => null];
        }
        $ymd = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($ymd !== false && $ymd->format('Y-m-d') === $raw) {
            return ['sql' => $raw . ' 23:59:59'];
        }
        $t = strtotime($raw);
        if ($t === false) {
            throw new \DomainException('Invalid expiration date.');
        }

        return ['sql' => date('Y-m-d H:i:s', $t)];
    }

    private function expireGiftCardIfNeededFromRow(array $card, int $operationBranchId): bool
    {
        if (($card['status'] ?? '') !== 'active') {
            return false;
        }
        if (empty($card['expires_at'])) {
            return false;
        }
        if (strtotime((string) $card['expires_at']) > time()) {
            return false;
        }

        $balance = $this->getCurrentBalance((int) $card['id'], $operationBranchId);
        $userId = $this->currentUserId();

        $this->transactions->create([
            'gift_card_id' => (int) $card['id'],
            'branch_id' => $card['branch_id'] ?? null,
            'type' => 'expire',
            'amount' => 0,
            'balance_after' => $balance,
            'reference_type' => null,
            'reference_id' => null,
            'notes' => 'Auto-expired by expires_at',
            'created_by' => $userId,
        ]);

        $this->cards->updateInTenantScope((int) $card['id'], $operationBranchId, [
            'status' => 'expired',
            'updated_by' => $userId,
        ]);

        $this->audit->log('gift_card_expired', 'gift_card', (int) $card['id'], $userId, $card['branch_id'] !== null ? (int) $card['branch_id'] : null, [
            'expired_at' => date('Y-m-d H:i:s'),
            'balance_at_expiry' => $balance,
        ]);

        return true;
    }

    private function loadCardForUpdate(int $giftCardId, int $operationBranchId): ?array
    {
        return $this->cards->findLockedInTenantScope($giftCardId, $operationBranchId);
    }

    private function requirePositiveBranchContext(?int $branchId): int
    {
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for gift card operation.');
        }

        return $branchId;
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function generateCode(): string
    {
        return 'GC-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $startedTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }
            $result = $callback();
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'gift_cards.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Gift card operation failed.');
        }
    }
}
