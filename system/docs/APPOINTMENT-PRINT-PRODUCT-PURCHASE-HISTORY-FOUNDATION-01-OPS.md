# APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01 — ops truth

**Scope:** `GET /appointments/{id}/print` optional section **Client product purchase history**, gated by **`appointments.print_show_client_product_purchase_history`** (default **false**, opt-in).

## Truth: what counts as product purchase history

- **Line level only:** rows from **`invoice_items`** joined to **`invoices`**.
- **Product rows only:** `invoice_items.item_type = 'product'` and `source_id` is a positive integer (maps to **`products.id`** in normal retail flows).
- **Not included:** service lines, package lines, gift card lines, membership lines, or any non-`product` `item_type`.
- **Tenant / branch:** same **`SalesTenantScope::invoiceClause('i')`** predicate on **`invoices`** as `ClientSalesProfileProviderImpl::listRecentInvoices` / payments (org-safe reads).
- **Deleted filters:** `invoices.deleted_at IS NULL`. Product catalog join is **`LEFT JOIN products`** with `products.deleted_at IS NULL` for display name only; the line still appears if the catalog row was soft-deleted (name falls back to line description / `Product #id`).
- **Client safety:** empty list when `ClientProfileAccessService::resolveForProviderRead($clientId)` is null (same as other sales profile methods).
- **Sort / bound:** `ORDER BY COALESCE(i.issued_at, i.created_at) DESC, invoice_items.id DESC`, limit **15** on print (provider caps at **50**).

## Invoice status, refunds, voids

- The list **does not** interpret payment/refund events or net amounts per line.
- Each row shows **`invoices.status`** as stored (e.g. draft, paid, cancelled) for context only.
- **Safe rule:** this is a **recent line-item ledger-style excerpt**, not a financial reconciliation view. Operators must not treat it as “cash collected per product” without reading the invoice and payments.

## Setting ↔ section (1:1)

| Setting key | Section on print |
| --- | --- |
| `appointments.print_show_client_product_purchase_history` | **Client product purchase history** (hidden when setting off) |

## Verifier

From `system/`:

```bash
php scripts/verify_appointment_print_product_purchase_history_foundation_01.php
```

Companion: `verify_appointment_print_settings_supported_sections_implementation_01.php`, `verify_appointment_print_consumer_foundation_01.php`.
