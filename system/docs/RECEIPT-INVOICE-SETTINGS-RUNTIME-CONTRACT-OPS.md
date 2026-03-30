# Receipt & invoice settings — runtime contract (OPS)

Task: **RECEIPT-INVOICE-RUNTIME-CONTRACT-REAPPLY-05A**

## Canonical keys (`receipt_invoice.*`)

| Key | Type | Default (read API) | Purpose |
|-----|------|-------------------|---------|
| `receipt_invoice.show_establishment_name` | bool | true | Show spa name on invoice view |
| `receipt_invoice.show_establishment_address` | bool | true | Show establishment address |
| `receipt_invoice.show_establishment_phone` | bool | true | Show establishment phone |
| `receipt_invoice.show_establishment_email` | bool | true | Show establishment email |
| `receipt_invoice.show_client_block` | bool | true | Show client section (name + optional fields) |
| `receipt_invoice.show_client_phone` | bool | false | Append client phone when stored |
| `receipt_invoice.show_client_address` | bool | false | Append client address when stored (column may be absent) |
| `receipt_invoice.show_recorded_by` | bool | false | Line from latest completed payment’s `created_by` |
| `receipt_invoice.show_item_barcode` | bool | false | Extra column; product lines load `products.barcode` |
| `receipt_invoice.footer_bank_details` | string | '' | Bank / payment details body (max 500) |
| `receipt_invoice.footer_text` | string | '' | Footer text (max 500) |
| `receipt_invoice.item_header_label` | string | `Description` | First column header (max 40) |
| `receipt_invoice.item_sort_mode` | string | `as_entered` | `description_asc` or `as_entered` |
| `receipt_invoice.receipt_message` | string | '' | Receipt footer; max 1000; syncs first 500 chars to `payments.receipt_notes` when patched |
| `receipt_invoice.invoice_message` | string | '' | Message above detail block on invoice view |

Related keys outside this group:

- `payments.receipt_notes` — legacy footer; still read when `receipt_message` is empty.
- `hardware.use_receipt_printer` — unchanged; editable from the same Payment Settings receipt form (org-wide hardware patch).

## Branch scope

- **Read in UI:** Payment Settings uses the branch selected in the payments scope bar (`payments_branch_id`). Organization default = branch id `0` / null.
- **Write:** Receipt & invoice PATCH uses `payments_context_branch_id` on POST (must match the selector), same as gift-card limits for that screen.
- **Invoice show:** Uses the invoice’s `branch_id` (nullable) for `getReceiptInvoiceSettings` and `getEffectiveReceiptFooterText`, consistent with establishment settings on that screen.

## Runtime consumption

| Consumer | Behavior |
|----------|----------|
| `Modules\Sales\Services\ReceiptInvoicePresentationService::buildForInvoiceShow` | Resolves config, optional barcode enrichment, sort, recorded-by line, client phone/address, effective receipt footer |
| `modules/sales/views/invoices/show.php` | Renders header toggles, client block, invoice message, line table, footers, receipt footer |
| `Modules\Sales\Controllers\InvoiceController::show` | Loads client row when needed; sets `$invoice['receipt_notes']` to effective footer for the summary block |
| `Modules\Sales\Services\PaymentService::receiptPrintSettingsForAudit` | Audit `receipt_notes` = `getEffectiveReceiptFooterText` |
| `Modules\Sales\Services\InvoiceService` (gift-card redeem audit) | Same effective footer for `receipt_notes` in audit payload |

## Write contract

- POST fields: `settings[receipt_invoice.*]` with short-key mapping in `SettingsController::receiptInvoicePatchFromPost` → `SettingsService::patchReceiptInvoiceSettings`.
- Allowlists: `SettingsController::PAYMENT_WRITE_KEYS`, `SECTION_ALLOWED_KEYS['payments']`, `ALL_ALLOWED_WRITE_KEYS`.

## Deferred (not implemented)

- Header logo, website, tax ID as separate toggles (no asset/tax fields in this contract).
- “Special offers” visibility (no offer line model wired to invoice output).
- Barcodes for non-product lines.

## Verifier

```bash
php system/scripts/verify_receipt_invoice_runtime_contract_reapply_05a.php
```
