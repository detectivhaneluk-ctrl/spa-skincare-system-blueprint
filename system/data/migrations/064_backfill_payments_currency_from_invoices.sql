-- Align legacy payment rows with invoice-stored currency when present (post-062). Skips rows already matching invoice. Rows with empty invoice.currency remain for repair script + SettingsService fallback.
UPDATE payments p
INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
SET p.currency = UPPER(TRIM(i.currency))
WHERE TRIM(COALESCE(i.currency, '')) <> ''
  AND UPPER(TRIM(COALESCE(p.currency, ''))) <> UPPER(TRIM(i.currency));
