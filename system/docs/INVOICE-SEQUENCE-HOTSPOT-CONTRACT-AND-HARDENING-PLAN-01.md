# INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01

**Scope:** Invoice numbering allocation, global lock hotspot, and safest evolution path. **No historical renumbering** in this wave.

---

## PHASE A — Truth audit (current contract)

### A.1 Allocation path

| Step | Location | Behavior |
|------|----------|----------|
| Create | `InvoiceService::create()` | Sets `$data['invoice_number'] = $data['invoice_number'] ?? $this->repo->allocateNextInvoiceNumber()` |
| Allocator | `InvoiceRepository::allocateNextInvoiceNumber()` | `SELECT next_number FROM invoice_number_sequences WHERE sequence_key = 'invoice' FOR UPDATE`; seed from `MAX(SUBSTRING(invoice_number,5))` on `INV-[0-9]+` if row missing; `UPDATE … SET next_number = ?` |
| Alias | `InvoiceRepository::getNextInvoiceNumber()` | Delegates to `allocateNextInvoiceNumber()` (legacy name; see `sales-phase-3-4-progress.md`) |

**Transactional context:** Allocation runs inside `InvoiceService::create()`’s transactional closure **before** `repo->create($data)` — same transaction as invoice insert (good for atomicity; bad for holding row lock duration if create does heavy work after number assign).

### A.2 Schema

| Object | Source | Contract |
|--------|--------|----------|
| `invoices.invoice_number` | `027_create_invoices_table.sql` | `VARCHAR(50) NOT NULL`, **`UNIQUE KEY uk_invoices_number (invoice_number)`** — **SaaS-wide unique** display number |
| `invoice_number_sequences` | `043_payment_refunds_and_invoice_sequence.sql` | `sequence_key VARCHAR(50) PRIMARY KEY`, single seeded row `'invoice'` |

**Format:** `INV-` + 8-digit zero-padded decimal (`str_pad` in repository).

### A.3 Other writers / bypasses

- **Manual / tests:** Scripts that call `InvoiceRepository::create()` with an explicit `invoice_number` (e.g. smoke tests) **bypass** the allocator and must avoid colliding with `uk_invoices_number`.
- **Service update path:** `InvoiceController::update` forces `$data['invoice_number'] = $invoice['invoice_number']` — number **not** regenerated on edit (`InvoiceRepository::normalize` allows `invoice_number` on update, but controller pins it).

### A.4 Consumers (display / search; not allocation)

- **Sales:** `InvoiceController` list filter `invoice_number` LIKE; views `index`, `show`, `_cashier_workspace`.
- **Clients:** `ClientSalesProfileProviderImpl` selects `i.invoice_number`; client `show` view.
- **Appointments:** `print.php` line item display.
- **Reports module:** no direct `invoice_number` grep in `modules/reports` (aggregates may use invoice ids / joins elsewhere).

**Assumption surfaced:** Search is **substring** / display; **no code assumes** global monotonicity across tenants for business rules — **uniqueness** is the hard schema constraint today.

### A.5 Product requirement (what uniqueness must mean)

| Interpretation | Supported by current schema? | Product fit |
|----------------|-------------------------------|-------------|
| **Global SaaS-wide unique** `invoice_number` | **Yes** (`uk_invoices_number`) | Matches current DDL; couples all tenants on one sequence |
| **Per-organization unique** | **No** (would need composite unique or prefix) | Natural for multi-tenant SaaS isolation |
| **Per-branch unique** | **No** | Natural for ops/receipts per location; weaker across branches |

**Conclusion:** Runtime **enforces global uniqueness** of the string `invoice_number`. There is **no** separate “scoped display number” — the column is the global document id.

---

## PHASE B — Decision memo (options)

### A. Keep global sequence

| Dimension | Assessment |
|-----------|------------|
| Concurrency | **Poor:** one `FOR UPDATE` row — all invoice creates serialize on this hotspot |
| Tenant isolation | **Poor:** cross-tenant coupling; noisy neighbor |
| Uniqueness | **Simple:** matches current `uk_invoices_number` |
| Reporting/search | **Unchanged** |
| Migration | **None** |
| Backward compatibility | **Trivial** |

**Verdict:** Accept only as **documented technical debt**; **reject** as long-term architecture.

### B. Per-organization sequence

| Dimension | Assessment |
|-----------|------------|
| Concurrency | **Good:** one lock row (or counter) **per org** — scales with tenant count |
| Tenant isolation | **Strong** — numbers don’t advance across orgs |
| Uniqueness | Requires **`uk_invoices_number` replacement** with e.g. **`(organization_id, invoice_number)`** or **prefixed** global string (`ORG-{id}-INV-…`) keeping single unique column |
| Reporting/search | Prefix or org filter; exports must carry org context |
| Migration | **Medium:** new sequence storage + backfill per org `MAX` + cutover allocator; **no** mass renumber if using prefix |
| Backward compatibility | **Medium risk** if changing unique constraint without prefix |

**Verdict:** **Recommended direction** for SaaS multi-tenant fairness and scale.

### C. Per-branch sequence

| Dimension | Assessment |
|-----------|------------|
| Concurrency | **Best granularity** for parallel creates |
| Tenant isolation | **Strong** within branch; **collisions** if unique stays global unless prefix/composite |
| Uniqueness | Same as B: must align `uk_invoices_number` with scope |
| Reporting/search | Branches may share numbers unless prefixed — **confusing** without prefix |
| Migration | **Higher** row count (many branches) |
| Backward compatibility | Same class as B |

**Verdict:** **Optional refinement inside an org** (e.g. branch suffix); **not** a substitute for org-level isolation.

### Recommended contract (chosen)

**Adopt per-organization sequence as the target contract**, with **display-safe uniqueness** via either:

1. **Composite unique** `(organization_id, invoice_number)` after resolving `organization_id` on `invoices` (requires nullable branch/org alignment — `invoices` has `branch_id`, org is via branch), or  
2. **Keep single `invoice_number` column** with **deterministic prefix** embedding org (or branch) so `uk_invoices_number` remains globally unique without DB composite change.

**Phase 2+ (not this pass):** implement scoped allocator + chosen uniqueness strategy; **do not** renumber historical rows in phase 1.

---

## PHASE C — Phase-1 hardening delivered in repo

1. **Named sequence key constant** in `InvoiceRepository` + docblock tying allocation to this plan.
2. **Migration `112_invoice_number_sequence_hotspot_documentation.sql`** — table `COMMENT` only (operational truth in DB).
3. **Read-only verifier** `verify_invoice_number_sequence_hotspot_readonly_01.php` — static proof of global `FOR UPDATE` + unique migration + single service allocation path.

---

## Files / schema touched by a future scoped-sequence change (checklist)

- `system/modules/sales/repositories/InvoiceRepository.php` — allocator, possibly split by scope
- `system/modules/sales/services/InvoiceService.php` — pass scope into allocator
- `system/data/migrations/027_*`, `043_*`, **`uk_invoices_number`** — uniqueness evolution
- `system/data/full_project_schema.sql` — snapshot sync
- Smoke / direct `InvoiceRepository::create` scripts — collision avoidance
- Any export/API that assumes format `^INV-[0-9]+$` (grep before changing format)
