# FOUNDATION-88 — Wave-4 appointment display-only first consumer (`<option title>`)

**Program:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-4-APPOINTMENT-DISPLAY-ONLY-FIRST-CONSUMER-IMPLEMENTATION`  
**Charter:** FOUNDATION-87 option **B** — appointment display-only; no booking/sales/contract/schema changes.

**Scope:** Four appointment module views only. No controllers, services, JS, invoice surfaces, `ServiceListProvider`, migrations, routes, or CSS.

---

## What changed

For each service `<option>` built from `$services` / `($services ?? [])`:

- **Option `value` and visible label text** are unchanged (same `htmlspecialchars` / duration patterns as before).
- When `trim((string)($s['description'] ?? ''))` is non-empty, the option includes **`title="..."`** with **`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`**.
- When description is null, missing, or whitespace-only after trim, **no** `title` attribute is output.

**Files:**

- `modules/appointments/views/create.php`
- `modules/appointments/views/edit.php`
- `modules/appointments/views/waitlist.php`
- `modules/appointments/views/waitlist-create.php`

---

## Behavioral guarantees (explicit)

- **Display-only metadata** on appointment-side service **`<option>`** elements (`title`). No new visible inline copy, hints, spans, or layout blocks in this wave.
- **Visible option labels** (the text between `<option>...</option>`) are **unchanged** from pre-F-88 behavior.
- **No booking logic, POST payload, duration auto-calc, pricing, VAT, or checkout behavior** was modified — no PHP outside these views, no JS edits, no `AppointmentController` / `parseInput` / slot loader / `AppointmentCheckoutProviderImpl` / `AvailabilityService` / invoice code changes.
- **Browser behavior for `<option title>`** varies by engine and platform (tooltips on native selects are inconsistent); this wave **does not** normalize or polyfill that — it only exposes escaped catalog text when present.

---

## Data source

`$s['description']` comes from **`ServiceListProvider`** rows (FOUNDATION-85) when controllers pass `$services` from `list()` — already nullable catalog long text.

---

## Deliverables

- View edits (four files above).
- This OPS document.
- Roadmap row: `BOOKER-PARITY-MASTER-ROADMAP.md` (FOUNDATION-88).
- ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-88-WAVE-4-APPOINTMENT-DISPLAY-ONLY-FIRST-CONSUMER-CHECKPOINT.zip`.

---

## Stop

FOUNDATION-88 ends here; invoice additive metadata (charter A) and further UX are out of scope.
