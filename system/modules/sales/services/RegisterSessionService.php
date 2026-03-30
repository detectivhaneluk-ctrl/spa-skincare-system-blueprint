<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Sales\Repositories\CashMovementRepository;
use Modules\Sales\Repositories\PaymentRepository;
use Modules\Sales\Repositories\RegisterSessionRepository;

final class RegisterSessionService
{
    public function __construct(
        private RegisterSessionRepository $sessions,
        private CashMovementRepository $movements,
        private PaymentRepository $payments,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext
    ) {
    }

    public function openSession(int $branchId, float $openingCashAmount, ?string $notes = null): int
    {
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('branch_id is required.');
        }
        $this->branchContext->assertBranchMatchStrict($branchId);
        if (!is_finite($openingCashAmount) || $openingCashAmount < 0) {
            throw new \DomainException('Opening cash amount cannot be negative.');
        }

        return $this->transactional(function () use ($branchId, $openingCashAmount, $notes): int {
            $open = $this->sessions->findOpenByBranchForUpdate($branchId);
            if ($open) {
                throw new \DomainException('An open register session already exists for this branch.');
            }

            $userId = $this->currentUserId();
            if ($userId === null) {
                throw new \DomainException('Authenticated user is required.');
            }

            $sessionId = $this->sessions->create([
                'branch_id' => $branchId,
                'opened_by' => $userId,
                'opened_at' => date('Y-m-d H:i:s'),
                'opening_cash_amount' => round($openingCashAmount, 2),
                'status' => 'open',
                'notes' => $notes,
            ]);

            $this->audit->log('register_session_opened', 'register_session', $sessionId, $userId, $branchId, [
                'branch_id' => $branchId,
                'opening_cash_amount' => round($openingCashAmount, 2),
                'notes' => $notes,
            ]);

            return $sessionId;
        }, 'register open');
    }

    public function closeSession(int $sessionId, float $closingCashAmount, ?string $notes = null): array
    {
        if ($sessionId <= 0) {
            throw new \InvalidArgumentException('register_session_id is required.');
        }
        if (!is_finite($closingCashAmount) || $closingCashAmount < 0) {
            throw new \DomainException('Closing cash amount cannot be negative.');
        }

        return $this->transactional(function () use ($sessionId, $closingCashAmount, $notes): array {
            $session = $this->sessions->findForUpdate($sessionId);
            if (!$session) {
                throw new \RuntimeException('Register session not found.');
            }
            if ((string) ($session['status'] ?? '') !== 'open') {
                throw new \DomainException('Register session is already closed.');
            }
            $this->branchContext->assertBranchMatchStrict((int) $session['branch_id']);

            $branchId = (int) $session['branch_id'];
            $userId = $this->currentUserId();
            if ($userId === null) {
                throw new \DomainException('Authenticated user is required.');
            }

            $opening = round((float) ($session['opening_cash_amount'] ?? 0), 2);
            $cashSalesByCurrency = $this->payments->getCompletedCashTotalsByCurrencyForRegisterSession($sessionId);
            $cashSalesMixedCurrency = $this->registerCashSalesMixedCurrency($cashSalesByCurrency);
            $cashSalesTotal = null;
            if (!$cashSalesMixedCurrency) {
                $cashSalesTotal = 0.0;
                foreach ($cashSalesByCurrency as $row) {
                    $cashSalesTotal += (float) ($row['total'] ?? 0);
                }
                $cashSalesTotal = round($cashSalesTotal, 2);
            }

            $cashIn = $this->movements->sumBySessionAndType($sessionId, 'cash_in');
            $cashOut = $this->movements->sumBySessionAndType($sessionId, 'cash_out');
            $expected = null;
            $variance = null;
            if (!$cashSalesMixedCurrency) {
                $expected = round((float) $opening + (float) $cashSalesTotal + (float) $cashIn - (float) $cashOut, 2);
                $variance = round($closingCashAmount - $expected, 2);
            }

            $this->sessions->update($sessionId, [
                'closed_by' => $userId,
                'closed_at' => date('Y-m-d H:i:s'),
                'closing_cash_amount' => round($closingCashAmount, 2),
                'expected_cash_amount' => $expected,
                'variance_amount' => $variance,
                'status' => 'closed',
                'notes' => $notes !== null && $notes !== '' ? $notes : ($session['notes'] ?? null),
            ]);

            $auditPayload = [
                'opening_cash_amount' => $opening,
                'cash_sales_by_currency' => $cashSalesByCurrency,
                'cash_sales_mixed_currency' => $cashSalesMixedCurrency,
                'cash_in_total' => $cashIn,
                'cash_out_total' => $cashOut,
                'expected_cash_amount' => $expected,
                'closing_cash_amount' => round($closingCashAmount, 2),
                'variance_amount' => $variance,
                'notes' => $notes,
            ];
            if (!$cashSalesMixedCurrency) {
                $auditPayload['cash_sales_total'] = $cashSalesTotal;
            }
            $this->audit->log('register_session_closed', 'register_session', $sessionId, $userId, $branchId, $auditPayload);

            return [
                'session_id' => $sessionId,
                'branch_id' => $branchId,
                'opening_cash_amount' => $opening,
                'cash_sales_total' => $cashSalesTotal,
                'cash_sales_by_currency' => $cashSalesByCurrency,
                'cash_sales_mixed_currency' => $cashSalesMixedCurrency,
                'cash_in_total' => $cashIn,
                'cash_out_total' => $cashOut,
                'expected_cash_amount' => $expected,
                'closing_cash_amount' => round($closingCashAmount, 2),
                'variance_amount' => $variance,
            ];
        }, 'register close');
    }

    public function addCashMovement(
        int $sessionId,
        string $type,
        float $amount,
        string $reason,
        ?string $notes = null
    ): int {
        if ($sessionId <= 0) {
            throw new \InvalidArgumentException('register_session_id is required.');
        }
        if (!in_array($type, ['cash_in', 'cash_out'], true)) {
            throw new \InvalidArgumentException('Invalid movement type.');
        }
        if (!is_finite($amount) || $amount <= 0) {
            throw new \DomainException('Cash movement amount must be greater than zero.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Movement reason is required.');
        }

        return $this->transactional(function () use ($sessionId, $type, $amount, $reason, $notes): int {
            $session = $this->sessions->findForUpdate($sessionId);
            if (!$session) {
                throw new \RuntimeException('Register session not found.');
            }
            if ((string) ($session['status'] ?? '') !== 'open') {
                throw new \DomainException('Cash movements are only allowed on open register sessions.');
            }
            $this->branchContext->assertBranchMatchStrict((int) $session['branch_id']);

            $branchId = (int) $session['branch_id'];
            $userId = $this->currentUserId();

            $movementId = $this->movements->create([
                'register_session_id' => $sessionId,
                'branch_id' => $branchId,
                'type' => $type,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $event = $type === 'cash_in' ? 'register_cash_in_recorded' : 'register_cash_out_recorded';
            $this->audit->log($event, 'cash_movement', $movementId, $userId, $branchId, [
                'register_session_id' => $sessionId,
                'type' => $type,
                'amount' => round($amount, 2),
                'reason' => $reason,
                'notes' => $notes,
            ]);

            return $movementId;
        }, 'register movement');
    }

    public function getOpenSessionForBranch(int $branchId): ?array
    {
        if ($branchId <= 0) {
            return null;
        }
        return $this->sessions->findOpenByBranch($branchId);
    }

    /**
     * True when completed cash payments in the session use more than one distinct payments.currency (after trim/upper).
     * Empty currency is treated as its own bucket so empty + non-empty counts as mixed.
     *
     * @param list<array{currency: string, total: float}> $byCurrency
     */
    private function registerCashSalesMixedCurrency(array $byCurrency): bool
    {
        $keys = [];
        foreach ($byCurrency as $row) {
            $keys[(string) ($row['currency'] ?? '')] = true;
        }

        return count($keys) > 1;
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'sales.transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Register session operation failed.');
        }
    }
}
