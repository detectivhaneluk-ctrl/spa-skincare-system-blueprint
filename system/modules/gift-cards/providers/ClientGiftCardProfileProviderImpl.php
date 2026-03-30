<?php

declare(strict_types=1);

namespace Modules\GiftCards\Providers;

use Core\Contracts\ClientGiftCardProfileProvider;
use Modules\Clients\Services\ClientProfileAccessService;
use Modules\GiftCards\Repositories\GiftCardRepository;
use Modules\GiftCards\Repositories\GiftCardTransactionRepository;

final class ClientGiftCardProfileProviderImpl implements ClientGiftCardProfileProvider
{
    public function __construct(
        private ClientProfileAccessService $profileAccess,
        private GiftCardRepository $cards,
        private GiftCardTransactionRepository $transactions,
    ) {
    }

    public function getSummary(int $clientId): array
    {
        $summary = [
            'total' => 0,
            'active' => 0,
            'used' => 0,
            'expired' => 0,
            'cancelled' => 0,
            'total_balance' => 0.0,
        ];
        $client = $this->profileAccess->resolveForProviderRead($clientId);
        if (!$client) {
            return $summary;
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return $summary;
        }
        try {
            $rows = $this->cards->listByClientIdInBranchTenantScope($clientId, $branchId, 500);
        } catch (\DomainException) {
            return $summary;
        }

        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $balances = $this->transactions->latestBalancesForCardIds($ids);

        foreach ($rows as $r) {
            $summary['total']++;
            $status = (string) ($r['status'] ?? 'active');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
            $cid = (int) ($r['id'] ?? 0);
            $summary['total_balance'] += $balances[$cid] ?? (float) ($r['original_amount'] ?? 0);
        }
        $summary['total_balance'] = round($summary['total_balance'], 2);

        return $summary;
    }

    public function listRecent(int $clientId, int $limit = 10): array
    {
        $client = $this->profileAccess->resolveForProviderRead($clientId);
        if (!$client) {
            return [];
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return [];
        }
        try {
            $rows = $this->cards->listByClientIdInBranchTenantScope($clientId, $branchId, max(1, (int) $limit));
        } catch (\DomainException) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $balances = $this->transactions->latestBalancesForCardIds($ids);

        return array_map(function (array $r) use ($balances): array {
            $cid = (int) ($r['id'] ?? 0);

            return [
                'id' => $cid,
                'code' => (string) ($r['code'] ?? ''),
                'status' => (string) ($r['status'] ?? 'active'),
                'current_balance' => $balances[$cid] ?? (float) ($r['original_amount'] ?? 0),
                'original_amount' => (float) ($r['original_amount'] ?? 0),
                'expires_at' => $r['expires_at'] ?? null,
                'created_at' => (string) ($r['created_at'] ?? ''),
            ];
        }, $rows);
    }
}
