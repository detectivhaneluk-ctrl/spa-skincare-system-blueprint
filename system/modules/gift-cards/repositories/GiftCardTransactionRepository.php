<?php

declare(strict_types=1);

namespace Modules\GiftCards\Repositories;

use Core\App\Database;

final class GiftCardTransactionRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(array $data): int
    {
        $this->db->insert('gift_card_transactions', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function latestForCard(int $giftCardId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM gift_card_transactions WHERE gift_card_id = ? ORDER BY id DESC LIMIT 1',
            [$giftCardId]
        );
    }

    /**
     * Latest balance_after per card in one round-trip (profile summaries).
     *
     * @param list<int> $giftCardIds
     * @return array<int, float> gift_card_id => balance_after
     */
    public function latestBalancesForCardIds(array $giftCardIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int) $v, $giftCardIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT gct.gift_card_id, gct.balance_after
                FROM gift_card_transactions gct
                INNER JOIN (
                    SELECT gift_card_id, MAX(id) AS max_id
                    FROM gift_card_transactions
                    WHERE gift_card_id IN ({$placeholders})
                    GROUP BY gift_card_id
                ) t ON t.gift_card_id = gct.gift_card_id AND t.max_id = gct.id";
        $rows = $this->db->fetchAll($sql, $ids);
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) ($r['gift_card_id'] ?? 0);
            if ($cid > 0) {
                $out[$cid] = (float) ($r['balance_after'] ?? 0);
            }
        }

        return $out;
    }

    public function listByCard(int $giftCardId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM gift_card_transactions WHERE gift_card_id = ? ORDER BY id DESC',
            [$giftCardId]
        );
    }

    public function existsRedeemForInvoice(int $giftCardId, int $invoiceId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT id
             FROM gift_card_transactions
             WHERE gift_card_id = ?
               AND type = 'redeem'
               AND reference_type = 'invoice'
               AND reference_id = ?
             LIMIT 1",
            [$giftCardId, $invoiceId]
        );
        return $row !== null;
    }

    public function listInvoiceRedemptions(int $invoiceId): array
    {
        return $this->db->fetchAll(
            "SELECT gct.id AS transaction_id,
                    gct.gift_card_id,
                    gc.code,
                    gct.amount,
                    gct.balance_after,
                    gct.branch_id,
                    gct.created_at
             FROM gift_card_transactions gct
             INNER JOIN gift_cards gc ON gc.id = gct.gift_card_id
             WHERE gct.type = 'redeem'
               AND gct.reference_type = 'invoice'
               AND gct.reference_id = ?
             ORDER BY gct.id DESC",
            [$invoiceId]
        );
    }

    public function existsByTypeAndReference(int $giftCardId, string $type, string $referenceType, int $referenceId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT id
             FROM gift_card_transactions
             WHERE gift_card_id = ?
               AND type = ?
               AND reference_type = ?
               AND reference_id = ?
             LIMIT 1",
            [$giftCardId, $type, $referenceType, $referenceId]
        );
        return $row !== null;
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'gift_card_id', 'branch_id', 'type', 'amount', 'balance_after',
            'reference_type', 'reference_id', 'notes', 'created_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
