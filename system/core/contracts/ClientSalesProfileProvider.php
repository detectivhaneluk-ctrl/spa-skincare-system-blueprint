<?php

declare(strict_types=1);

namespace Core\Contracts;

interface ClientSalesProfileProvider
{
    /**
     * Client-level sales rollup. Scalars total_billed / total_paid / total_due are null when multi-currency makes a single figure misleading; use *_by_currency.
     *
     * @return array{
     *   invoice_count: int,
     *   total_billed: float|null,
     *   billed_mixed_currency: bool,
     *   billed_by_currency: list<array{currency: string, total_billed: float, invoice_count: int}>,
     *   total_paid: float|null,
     *   paid_mixed_currency: bool,
     *   paid_by_currency: list<array{currency: string, total_paid: float, payment_count: int}>,
     *   total_due: float|null,
     *   payment_count: int
     * }
     */
    public function getSummary(int $clientId): array;

    /**
     * @return array<int, array{id:int,invoice_number:string,total_amount:float,paid_amount:float,status:string,created_at:string}>
     */
    public function listRecentInvoices(int $clientId, int $limit = 10): array;

    /**
     * Tenant- and client-scoped invoice search with server-side filters and pagination.
     * Uses the same visibility gate as {@see listRecentInvoices()} (profile access + sales invoice clause).
     *
     * @return array{rows: list<array{id:int,invoice_number:string,total_amount:float,paid_amount:float,status:string,created_at:string}>, total: int}
     */
    public function listInvoicesForClientFiltered(
        int $clientId,
        string $invoiceNumberContains,
        ?string $createdDateFromYmd,
        ?string $createdDateToYmd,
        int $page,
        int $perPage
    ): array;

    /**
     * @return array<int, array{id:int,invoice_id:int,payment_method:string,amount:float,currency:string,status:string,paid_at:string|null,created_at:string}>
     */
    public function listRecentPayments(int $clientId, int $limit = 10): array;

    /**
     * Recent retail product lines only (`invoice_items.item_type` = product, `source_id` = products.id).
     * Tenant-scoped via invoices + {@see \Modules\Sales\Services\SalesTenantScope::invoiceClause}. Excludes soft-deleted invoices.
     * Client visibility matches other profile methods (same gate as getSummary / listRecentInvoices).
     *
     * @return list<array{
     *   invoice_item_id:int,
     *   invoice_id:int,
     *   product_id:int,
     *   product_name:string,
     *   description:string,
     *   quantity:float,
     *   unit_price:float,
     *   line_total:float,
     *   invoice_number:string,
     *   invoice_status:string,
     *   currency:string,
     *   invoice_sort_at:string
     * }>
     */
    public function listRecentProductInvoiceLines(int $clientId, int $limit = 15): array;
}
