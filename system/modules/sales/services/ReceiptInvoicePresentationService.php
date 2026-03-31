<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Database;
use Core\App\SettingsService;
use Modules\Inventory\Repositories\ProductRepository;

/**
 * Resolves receipt/invoice presentation for desktop/internal invoice output (invoice show view).
 *
 * FOUNDATION-A7 PHASE-3: Product barcode DB query removed; barcodes now delegated to
 * {@see ProductRepository::lookupBarcodesByIds()}, satisfying the main service-layer DB ban.
 *
 * Remaining Database usage: {@see resolveRecordedByLine()} performs a user name lookup
 * (SELECT name FROM users WHERE id = ?) for receipt "Recorded by" display. This is
 * presentation-only infrastructure — no business data, no tenant-owned records — and is
 * explicitly excluded from the service DB ban (same category as WaitlistService advisory locks).
 */
final class ReceiptInvoicePresentationService
{
    public function __construct(
        private SettingsService $settings,
        private Database $db,
        private ProductRepository $productRepo,
    ) {
    }

    /**
     * @param array<string, mixed> $establishment From {@see SettingsService::getEstablishmentSettings}
     * @param array<string, mixed>|null $clientRow From clients table (optional)
     * @param list<array<string, mixed>> $items Invoice line rows
     * @param list<array<string, mixed>> $payments Payment rows for the invoice
     * @return array{
     *     presentation: array{
     *         show_establishment_name: bool,
     *         show_establishment_address: bool,
     *         show_establishment_phone: bool,
     *         show_establishment_email: bool,
     *         show_client_block: bool,
     *         show_client_phone: bool,
     *         show_client_address: bool,
     *         show_recorded_by: bool,
     *         show_item_barcode: bool,
     *         item_header_label: string,
     *         item_sort_mode: string,
     *         footer_bank_details: string,
     *         footer_text: string,
     *         receipt_message: string,
     *         invoice_message: string
     *     },
     *     items_display: list<array<string, mixed>>,
     *     has_any_barcode: bool,
     *     recorded_by_line: string|null,
     *     client_phone: string,
     *     client_address: string
     * }
     */
    public function buildForInvoiceShow(
        ?int $invoiceBranchId,
        array $establishment,
        ?array $clientRow,
        array $items,
        array $payments
    ): array {
        $cfg = $this->settings->getReceiptInvoiceSettings($invoiceBranchId);

        $itemsOut = $items;
        if (!empty($cfg['show_item_barcode'])) {
            $itemsOut = $this->withProductBarcodes($itemsOut);
        }
        $hasAnyBarcode = false;
        foreach ($itemsOut as $row) {
            if (trim((string) ($row['product_barcode'] ?? '')) !== '') {
                $hasAnyBarcode = true;
                break;
            }
        }

        if (($cfg['item_sort_mode'] ?? '') === 'description_asc') {
            usort($itemsOut, static function (array $a, array $b): int {
                return strcmp((string) ($a['description'] ?? ''), (string) ($b['description'] ?? ''));
            });
        }
        $recordedBy = null;
        if (!empty($cfg['show_recorded_by'])) {
            $recordedBy = $this->resolveRecordedByLine($payments);
        }
        $phone = '';
        $address = '';
        if ($clientRow !== null) {
            $phone = trim((string) ($clientRow['phone'] ?? ''));
            $address = trim((string) ($clientRow['address'] ?? ''));
        }

        $receiptMessage = $this->settings->getEffectiveReceiptFooterText($invoiceBranchId);

        return [
            'presentation' => [
                'show_establishment_name' => !empty($cfg['show_establishment_name']),
                'show_establishment_address' => !empty($cfg['show_establishment_address']),
                'show_establishment_phone' => !empty($cfg['show_establishment_phone']),
                'show_establishment_email' => !empty($cfg['show_establishment_email']),
                'show_client_block' => !empty($cfg['show_client_block']),
                'show_client_phone' => !empty($cfg['show_client_phone']),
                'show_client_address' => !empty($cfg['show_client_address']),
                'show_recorded_by' => !empty($cfg['show_recorded_by']),
                'show_item_barcode' => !empty($cfg['show_item_barcode']),
                'item_header_label' => (string) ($cfg['item_header_label'] ?? 'Description'),
                'item_sort_mode' => (string) ($cfg['item_sort_mode'] ?? 'as_entered'),
                'footer_bank_details' => (string) ($cfg['footer_bank_details'] ?? ''),
                'footer_text' => (string) ($cfg['footer_text'] ?? ''),
                'receipt_message' => $receiptMessage,
                'invoice_message' => (string) ($cfg['invoice_message'] ?? ''),
            ],
            'items_display' => $itemsOut,
            'recorded_by_line' => $recordedBy,
            'has_any_barcode' => $hasAnyBarcode,
            'client_phone' => $phone,
            'client_address' => $address,
        ];
    }

    /**
     * Product barcodes fetched via ProductRepository (migrated from direct DB — FOUNDATION-A7 PHASE-3).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withProductBarcodes(array $items): array
    {
        $productIds = [];
        foreach ($items as $row) {
            if (($row['item_type'] ?? '') === 'product' && !empty($row['source_id'])) {
                $productIds[(int) $row['source_id']] = true;
            }
        }
        if ($productIds === []) {
            return array_map(static fn (array $r): array => array_merge($r, ['product_barcode' => '']), $items);
        }
        $byId = $this->productRepo->lookupBarcodesByIds(array_keys($productIds));
        $out = [];
        foreach ($items as $row) {
            $bc = '';
            if (($row['item_type'] ?? '') === 'product' && !empty($row['source_id'])) {
                $bc = $byId[(int) $row['source_id']] ?? '';
            }
            $out[] = array_merge($row, ['product_barcode' => $bc]);
        }

        return $out;
    }

    /**
     * User name lookup for receipt "Recorded by" line — presentation display only.
     * Uses db->fetchOne for user name resolution: this is presentation infrastructure (not
     * business data access), explicitly excluded from the service DB ban per FOUNDATION-A7 PHASE-3.
     *
     * @param list<array<string, mixed>> $payments
     */
    private function resolveRecordedByLine(array $payments): ?string
    {
        $latest = null;
        foreach ($payments as $p) {
            if (($p['entry_type'] ?? 'payment') !== 'payment' || ($p['status'] ?? '') !== 'completed') {
                continue;
            }
            $cb = (int) ($p['created_by'] ?? 0);
            if ($cb <= 0) {
                continue;
            }
            $ts = (string) ($p['paid_at'] ?? $p['created_at'] ?? '');
            if ($latest === null || $ts >= $latest['ts']) {
                $latest = ['ts' => $ts, 'user_id' => $cb];
            }
        }
        if ($latest === null) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT email, name FROM users WHERE id = ? LIMIT 1', [$latest['user_id']]);
        if ($row === null) {
            return 'Recorded by user #' . $latest['user_id'];
        }
        $name = trim((string) ($row['name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));

        return $name !== '' ? 'Recorded by: ' . $name : ($email !== '' ? 'Recorded by: ' . $email : 'Recorded by user #' . $latest['user_id']);
    }
}
