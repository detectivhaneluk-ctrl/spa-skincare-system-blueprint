<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

/**
 * Normalizes raw POST `items[]` rows into canonical invoice line payloads (including optional line_meta).
 */
final class CashierLineItemParser
{
    /**
     * @param array<string, mixed> $post Typically $_POST
     * @return list<array<string, mixed>>
     */
    public function parseItemsFromPost(array $post): array
    {
        $raw = $post['items'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = $this->parseRow($row);
            if ($parsed === null) {
                continue;
            }
            $out[] = $parsed;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row): ?array
    {
        $rawType = strtolower(trim((string) ($row['item_type'] ?? '')));
        $type = CashierInvoiceLineType::isKnown($rawType) ? $rawType : CashierInvoiceLineType::MANUAL;

        $qty = (float) ($row['quantity'] ?? 0);
        $price = (float) ($row['unit_price'] ?? 0);
        $desc = trim((string) ($row['description'] ?? ''));
        $sourceId = isset($row['source_id']) && $row['source_id'] !== '' && $row['source_id'] !== null
            ? (int) $row['source_id']
            : null;
        if ($sourceId !== null && $sourceId <= 0) {
            $sourceId = null;
        }

        $contract = CashierInvoiceLineType::contractByType();
        $meta = $this->parseLineMeta($row);

        if ($type === CashierInvoiceLineType::TIP) {
            $qty = 1.0;
            $price = (float) ($row['unit_price'] ?? 0);
            if ($desc === '') {
                $desc = 'Tip';
            }
        } elseif ($type === CashierInvoiceLineType::MEMBERSHIP) {
            $qty = 1.0;
            if ($sourceId === null || $sourceId <= 0) {
                return null;
            }
            if ($desc === '') {
                $desc = 'Membership';
            }
        } elseif ($type === CashierInvoiceLineType::GIFT_CARD) {
            if ($desc === '') {
                $desc = 'Gift card';
            }
        } elseif ($type === CashierInvoiceLineType::GIFT_VOUCHER) {
            if ($desc === '') {
                $desc = 'Gift voucher';
            }
        } elseif ($type === CashierInvoiceLineType::SERIES) {
            if ($desc === '') {
                $desc = 'Series (package)';
            }
        } elseif ($type === CashierInvoiceLineType::CLIENT_ACCOUNT) {
            if ($desc === '') {
                $desc = 'Client account charge';
            }
        }

        if (!$contract[$type]['quantity_allowed']) {
            $qty = 1.0;
        }

        if ($desc === '' && $qty <= 0 && $price <= 0) {
            return null;
        }

        if ($type === CashierInvoiceLineType::MANUAL && trim($desc) === '' && $price <= 0) {
            return null;
        }

        if (trim($desc) === '') {
            $desc = 'Item';
        }

        $qty = $qty > 0 ? $qty : 1.0;

        return [
            'item_type' => $type,
            'source_id' => $sourceId,
            'description' => $desc,
            'quantity' => $qty,
            'unit_price' => $price,
            'discount_amount' => (float) ($row['discount_amount'] ?? 0),
            'tax_rate' => (float) ($row['tax_rate'] ?? 0),
            'line_meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function parseLineMeta(array $row): ?array
    {
        $meta = [];
        if (isset($row['line_meta_json']) && is_string($row['line_meta_json']) && trim($row['line_meta_json']) !== '') {
            $decoded = json_decode($row['line_meta_json'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $emp = isset($row['employee_user_id']) && $row['employee_user_id'] !== '' && $row['employee_user_id'] !== null
            ? (int) $row['employee_user_id']
            : null;
        if ($emp !== null && $emp > 0) {
            $meta['employee_user_id'] = $emp;
        }
        if (isset($row['membership_starts_at'])) {
            $s = trim((string) $row['membership_starts_at']);
            if ($s !== '') {
                $meta['membership_starts_at'] = $s;
            }
        }
        if (isset($row['line_meta']) && is_array($row['line_meta'])) {
            foreach ($row['line_meta'] as $k => $v) {
                if (is_string($k) && $k !== '') {
                    $meta[$k] = $v;
                }
            }
        }

        return $meta === [] ? null : $meta;
    }
}
