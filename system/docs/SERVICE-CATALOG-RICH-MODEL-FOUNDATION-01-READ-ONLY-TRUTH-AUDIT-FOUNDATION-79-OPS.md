# SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01 — Read-only truth audit (FOUNDATION-79)

**Program:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-79`  
**Scope:** Read-only inventory of the current `services` catalog row and in-tree dependents, as **wave 1** of **§5.C Phase 3** task **SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01** (roadmap **3.2**).  
**Verdict:** **B** (complete for stated scope with explicit waivers below).  
**Contradiction check:** No code read in this audit contradicts **FOUNDATION-64**, **FOUNDATION-68**, **FOUNDATION-72**, or **FOUNDATION-78** (organization-context lane closure, HTTP 403 classification, repository-scope doc closure, post-org exit / §5.C Phase 3 lane selection). This wave does not analyze organization-scoped repository SQL or assert consumers beyond noting **`ServiceService`** / **`ServiceController`** use **`BranchContext`** consistent with existing branch semantics.

---

## 1. Schema truth: `services` table shape and later alterations

### 1.1 Canonical DDL (aggregated)

**Source of truth in repo:** chained migrations under `system/data/migrations/` and consolidated snapshot `system/data/full_project_schema.sql`.

**Create (migration `022_create_services_table.sql`):** defines `services` with columns:

| Column | Type / notes |
|--------|----------------|
| `id` | `BIGINT UNSIGNED` PK AI |
| `category_id` | `BIGINT UNSIGNED NULL`, FK → `service_categories(id)` `ON DELETE SET NULL` |
| `name` | `VARCHAR(200) NOT NULL` |
| `duration_minutes` | `INT NOT NULL DEFAULT 60` |
| `buffer_before_minutes` | `INT NOT NULL DEFAULT 0` |
| `buffer_after_minutes` | `INT NOT NULL DEFAULT 0` |
| `price` | `DECIMAL(10,2) NOT NULL DEFAULT 0` |
| `vat_rate_id` | `BIGINT UNSIGNED NULL` (no FK in `022`; comment in `047` notes optional future FK) |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |
| `branch_id` | `BIGINT UNSIGNED NULL`, FK → `branches(id)` `ON DELETE SET NULL` |
| `created_by`, `updated_by` | `BIGINT UNSIGNED NULL`, FK → `users(id)` `ON DELETE SET NULL` |
| `created_at`, `updated_at` | timestamps |
| `deleted_at` | `TIMESTAMP NULL` (soft delete) |

**Later migration altering `services` (grep of `system/data/migrations/*.sql` for `ALTER TABLE services` or new columns on `services`):**

- **`024_phase2a_integrity_rules.sql`:** `ALTER TABLE services ADD INDEX idx_services_deleted (deleted_at);` — **only** index addition; **no** new columns.

**No other migration in `system/data/migrations` adds or renames `services` columns** (verified via repository search for `ALTER TABLE services` / `CREATE TABLE services`).

**Foreign keys from other tables to `services(id)`** (downstream coupling; schema does not change `services` columns): including `023` (`service_staff`, `service_rooms`, `service_equipment`), `025` (`appointments.service_id`), `039` (waitlist, nullable), `045` (document consent linkage), `057` (series), `065` (`service_staff_groups`), `076` (`payroll_compensation_rules.service_id`). These prove **service id** as a stable join key across domains.

**`system/data/full_project_schema.sql`:** `CREATE TABLE services` matches **`022` + `024`** (same columns + `idx_services_deleted`).

### 1.2 Waiver — path label vs spec

**W-79-1:** Task text referenced `system/database/migrations/022_create_services_table.sql`. In this tree, migrations live under **`system/data/migrations/`**. Content is as audited above.

---

## 2. Application module path truth

**W-79-2:** Task text referenced `system/modules/services/`. The implementation lives under **`system/modules/services-resources/`** (`ServiceRepository`, `ServiceService`, `ServiceController`, views). All citations below use actual paths.

---

## 3. Repository / service / controller — methods and field usage

### 3.1 `ServiceRepository`

- **`find(int $id)`:** `SELECT s.*, c.name AS category_name` from `services s` left join `service_categories c` … `s.deleted_at IS NULL`; enriches with **`staff_ids`**, **`staff_group_ids`**, **`room_ids`**, **`equipment_ids`** from junction tables (`service_staff`, `ServiceStaffGroupRepository`, `service_rooms`, `service_equipment`).
- **`list(?int $categoryId, ?int $branchId)`:** same join; filters `s.deleted_at IS NULL`; optional `category_id`; branch filter **`(s.branch_id = ? OR s.branch_id IS NULL)`**; order `c.sort_order, c.name, s.name`. **Does not filter `is_active`.**
- **`create` / `update`:** `normalize()` **whitelist:** `category_id`, `name`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `price`, `vat_rate_id`, `is_active`, `branch_id`, `created_by`, `updated_by`. Junction arrays handled separately. **`created_at` / `updated_at` not in whitelist** — DB defaults/triggers apply on insert; update only touches keys present in normalized subset.
- **`softDelete`:** sets `deleted_at = NOW()`.

### 3.2 `ServiceService`

- **`create`:** transaction; strips `staff_group_ids` from payload for branch enforcement; **`BranchContext::enforceBranchOnCreate`**; **`VatRateService::assertActiveVatRateAssignableToServiceBranch`** on resolved `vat_rate_id` + service branch; normalizes and validates staff group ids via **`StaffGroupRepository`**, **`AuditService`** for `service_created` / `service_staff_groups_set`.
- **`update`:** transaction; **`repo->find`**; **`BranchContext::assertBranchMatch`** on existing `branch_id`; merges `branch_id` / `vat_rate_id` for VAT assertion; may **auto-trim `staff_group_ids`** when `branch_id` changes and payload omitted groups; validates groups; **`enforceBranchIdImmutableWhenScoped` is not called here** — branch immutability when scoped is enforced in **`BranchContext`** only if a caller invokes it; **`ServiceController`** does not call **`enforceBranchIdImmutableWhenScoped`** on service update (see §4).
- **`delete`:** branch match + soft delete + audit.
- **`validateStaffGroupIdsForService`:** public helper for controller validation.

### 3.3 `ServiceController`

- **`index`:** `repo->list($categoryId, $listBranchId)` with **`BranchContext::getCurrentBranchId()`**.
- **`create` / `store` / `show` / `edit` / `update` / `destroy`:** parse/validate, **`ensureBranchAccess`** (`assertBranchMatch` on entity `branch_id`), CSRF, redirects.
- **`parseInput`:** reads `category_id`, `name`, `duration_minutes`, buffers, `price`, `vat_rate_id`, `is_active` (checkbox), **`branch_id` from POST**, staff/room/equipment ids, `staff_group_ids` when create or when `staff_group_ids_sync` on update.
- **`validate`:** name non-empty; duration ≥ 1; price ≥ 0; optional staff group validation via **`ServiceService::validateStaffGroupIdsForService`**.

**Admin-editable fields (HTTP) — evidenced by `create.php` / `edit.php` + `parseInput`:**  
`category_id`, `name`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `price`, `vat_rate_id`, `is_active`, `staff_ids`, `room_ids`, `equipment_ids`, `staff_group_ids`.  

**`branch_id`:** parsed in **`parseInput`** but **no `branch_id` input** in **`create.php` / `edit.php`** — effective branch for new rows comes from **`BranchContext::enforceBranchOnCreate`** when current branch is set; when branch context is **null**, `branch_id` remains **null** unless POST is crafted manually.

**Read-only / system:** `id`, `created_at`, `updated_at` (not in form); **`created_by` / `updated_by`** set in **`ServiceService`** (not in controller form).

**Show view:** displays name, category_name, duration, buffers, price, active — **not** VAT, branch, audit ids, junction detail.

---

## 4. Field semantics (code-backed)

| Topic | Current semantics |
|--------|---------------------|
| **Name / title** | Single field **`name`** (`VARCHAR(200)`). No separate public title / internal name / slug in schema. |
| **Duration** | **`duration_minutes`** required in UI (min 1). **Booking runtime** uses **`AvailabilityService::getServiceTiming` / `getServiceDurationMinutes`** → SQL `duration_minutes` where `deleted_at IS NULL` AND **`is_active = 1`**. **Appointment duration move** uses **`AvailabilityService::getServiceDurationMinutes`** (same gate). |
| **Buffers** | **`buffer_before_minutes`**, **`buffer_after_minutes`**. Used in **`AvailabilityService::isSlotAvailable`** / **`isStaffWindowAvailable`** via **`getServiceTiming`**. |
| **Price** | **`price`** `DECIMAL(10,2)`. Editable in admin. **`ServiceListProvider`** exposes **`price`** to **`AppointmentCheckoutProviderImpl`** for invoice prefill (`service_price`). **Invoice line totals** use line `unit_price` / items — service catalog price is not auto-overwritten by invoice logic in the audited **`InvoiceService`** slice (tax path uses **`vat_rate_id`**). |
| **Active / visibility** | **`is_active`** tinyint. **Admin** checkbox. **Runtime booking/slots:** **`AvailabilityService`** and **`AppointmentService::lockActiveStaffAndServiceRows`** require **`is_active = 1`** and non-deleted. **Service catalog admin list:** **`ServiceRepository::list`** does **not** filter inactive — inactive rows can appear in **services index** and in **`ServiceListProvider::list`** output shape issues (see **W-79-3**). |
| **Tax / VAT** | **`vat_rate_id`** nullable FK to **`vat_rates`** (enforced at service write time by **`VatRateService::assertActiveVatRateAssignableToServiceBranch`**). **`InvoiceService::applyCanonicalTaxRatesForServiceLines`** overwrites line **`tax_rate`** from **`vat_rates.rate_percent`** when service line resolves via **`ServiceListProvider::find`** and **`vat_rate_id` > 0**. |
| **Category** | **`category_id`** → **`service_categories`**. **`ServiceRepository`** joins category name for display. Roadmap **3.1** added hierarchy on **categories**; **`services.category_id`** remains single FK (no second category field on `services`). |
| **Branch applicability** | **`branch_id` NULL = global** (visible in lists when listing branch filters with **`OR branch_id IS NULL`** in **`ServiceRepository::list`**). **Public booking** uses **`AvailabilityService::getActiveServiceForScope`** — if service has non-null **`branch_id`**, it must match requested branch. |
| **Staff applicability** | **`service_staff`** junction (optional: if empty, **`AvailabilityService::getEligibleStaff`** uses broader staff query with staff-group SQL). **`service_staff_groups`** + **`ServiceStaffGroupEligibilityService`** enforce group eligibility for booking. |
| **Rooms / equipment** | **`service_rooms`**, **`service_equipment`** — edited in admin forms; not re-audited here beyond repository sync. |
| **Display / content / meta** | **No** description, long HTML, image, SKU, or marketing fields on **`services`** in **022** / **`full_project_schema`**. |

---

## 5. Contract: `ServiceListProvider` (cross-module surface)

**`Core\Contracts\ServiceListProvider`:** **`list` / `find`** return **`id`, `name`, `duration_minutes`, `price`, `vat_rate_id`, `category_id`, `category_name`**.  

**`ServiceListProviderImpl`:** maps **`ServiceRepository`** rows accordingly; **does not expose `is_active`, `branch_id`, buffers** in the typed return shape (PHPDoc omits them).

**W-79-3 (asymmetry risk):** **`AppointmentController`** uses **`serviceList->list($branchId)`** for dropdowns. **`ServiceListProviderImpl::mapRow`** drops **`is_active`**, while **`ServiceRepository::list`** still returns inactive services. UI cannot distinguish inactive rows without reading raw repository or extending the contract. Runtime **`AppointmentService`** / **`AvailabilityService`** still enforce active service on locked paths. This is a **catalog-list vs booking-gate asymmetry** for a future rich model (filtering, badges, separate “bookable” flag).

---

## 6. Downstream modules (dependency on service fields)

### 6.1 Appointments / booking / availability

- **`AvailabilityService`:** direct SQL on **`services`** for **`duration_minutes`**, buffers, **`branch_id`**, **`is_active`**, **`deleted_at`**; staff/service group eligibility; slot generation.
- **`AppointmentService`:** locks **`services`** (`id`, `branch_id`, `is_active`, `deleted_at`); duration from **`AvailabilityService`**; conflicts use **`getServiceTiming`** for buffers.
- **`AppointmentSeriesService`:** validates service row active/not deleted.
- **`PublicBookingService`:** uses **`AvailabilityService::getActiveServiceForScope`** + **`getActiveStaffForScope`** — depends on **active** service for branch.
- **`WaitlistService`:** **`service_id`** and timing via service duration (referenced in codebase search).

### 6.2 Sales / invoices

- **`InvoiceService`:** service lines `item_type = service`, `source_id = services.id`; **`applyCanonicalTaxRatesForServiceLines`** uses **`ServiceListProvider::find`** + **`vat_rate_id`**.
- **`AppointmentCheckoutProviderImpl`:** prefill **`service_price`** from **`ServiceListProvider::find`** → **`price`**.
- **`CatalogSellableReadModelProviderImpl`:** uses **`ServiceRepository::list`**, filters **`is_active === 1`**, maps **`price`** to **`unit_price`** for unified read model.

### 6.3 Payroll

- **`PayrollService::fetchEligibleServiceLineEvents`:** `LEFT JOIN services s` for **`s.category_id AS service_category_id`** (rule matching); appointment carries **`service_id`**.

### 6.4 Reports

- **`ReportRepository`:** audited file has **no** `services` table reference (revenue/payments/VAT from invoices/payments). **No** direct report dimension by service name in this slice.

### 6.5 Documents / consents

- **`service_required_consents`**, repositories under **`documents`** — keyed by **`service_id`** (FK in **`045`** migration). Enforcement text in **`ConsentService`** / **`AppointmentService`** (see module docblocks).

### 6.6 Other

- **`SalesLineDomainBoundaryTruthAuditService`**, drift script **`verify_services_vat_rate_drift_readonly.php`**: reference **`services`** / **`vat_rate_id`** for audits.

---

## 7. Thin / incomplete vs roadmap §5.C 3.2

Roadmap **§5.C** row **3.2** calls for evolving **`services`** as a catalog row: **visibility/status**, **pricing/duration/buffer** as needed, **branch/staff applicability hooks**, **richer content**, **future product linkage**.

**Code-backed gap summary:**

- **Visibility:** only **`is_active`** + soft delete; no online-only, staff-only, or channel-specific flags.
- **Content:** **`name`** only; no description, media, or SEO/slug fields.
- **Product linkage:** no **`services`** column linking to **`products`** (unified catalog roadmap item **3.4** addresses broader domain).
- **Pricing:** single **`price`**; no tiers, deposits, or branch-specific price overrides on the row.
- **Applicability:** **`branch_id`** + junction tables + staff groups — **operational**, not a separate “visibility matrix” model.

---

## 8. One-pass rich-model risks (explicit)

1. **Contract drift:** **`ServiceListProvider`** is consumed by **appointments**, **invoices**, **checkout prefill** — any new fields require coordinated contract and mapper updates.
2. **Dual paths to `services`:** **`AvailabilityService`** queries **`services`** directly; **`ServiceListProvider`** uses **`ServiceRepository`** — schema changes must update **both** or introduce a single read model deliberately.
3. **Inactive service listing:** admin list and **`ServiceListProvider`** mapping omit **`is_active`** in the contract — UI/booking parity must be designed when “bookable” semantics expand.
4. **Payroll rules** key off **`service_id`** and **category** from **`services`** join — hierarchy on categories (**3.1**) may change rule specificity if not re-tested.
5. **`vat_rate_id` integrity:** invoice line tax recomputation and **`VatRateService`** guards are sensitive to FK/orphan behavior (already strict in **`InvoiceService`** for missing VAT rows).
6. **`branch_id` mutation:** **`ServiceService::update`** does not invoke **`BranchContext::enforceBranchIdImmutableWhenScoped`** (no matches under **`services-resources`**). **`assertBranchMatch`** runs against the **pre-update** row only. **`parseInput`** can supply **`branch_id`** even though create/edit views omit it — reassignment or crafted POST is a **behavior surface** to account for in a rich-model pass (out of scope to fix here).

---

## 9. Recommended next program (exactly one)

**`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01` — narrow schema + model implementation program (chartered Phase 3.2 wave 2):** product-approved field list, single migration batch (or sequenced migrations), **`ServiceRepository::normalize`** + forms + **`ServiceListProvider`** contract + **`AvailabilityService`** / **`InvoiceService`** touch points per §6, with explicit QA on booking, invoice tax, payroll category rules, and unified catalog read model.  

**Not selected:** a docs-only prerequisite wave (audit already delivered); **“no implementation yet”** would only apply if product defers scope — default recommendation here is **one bounded implementation program** aligned with roadmap **3.2** dependency order (**after 3.1** per roadmap; **3.1** is marked shipped in §5.C).

**Do not open FOUNDATION-80** in this wave (per task brief).

---

## 10. Waivers / risks register

| ID | Waiver / risk |
|----|----------------|
| W-79-1 | Migration path documented as **`system/data/migrations/`**, not `system/database/migrations/`. |
| W-79-2 | Module path **`services-resources`**, not `modules/services/`. |
| W-79-3 | **`ServiceListProvider`** omits **`is_active`** / **`branch_id`** / buffers; **`ServiceRepository::list`** includes inactive rows — dropdown vs runtime gate asymmetry. |
| W-79-4 | **`branch_id`** not shown in service create/edit views; **`parseInput`** still accepts **`branch_id`** — hidden/manual POST surface. |
| W-79-5 | Reports module (sample) does not join **`services`** for revenue; payroll joins for **category** only — other analytics may be added later. |
| W-79-6 | This audit did not execute runtime DB or enumerate **every** PHP file mentioning `services` — dependency list is **code-search-backed** for primary modules; obscure references may exist. |
| W-79-7 | **`enforceBranchIdImmutableWhenScoped`** is **not** used on service update; **`branch_id`** may be changeable via POST where **`normalize()`** accepts it — see §8.6. |

---

## 11. Verdict rationale

**A** would require zero material waivers and exhaustive proof of every reference site. **B** is appropriate: schema and primary surfaces are **fully code-backed**, with explicit path renames and contract/UI asymmetry waivers **W-79-1–W-79-6**.

**C** is **not** selected — no contradiction with **FOUNDATION-64/68/72/78** was found, and core catalog shape is provable from migrations + **`ServiceRepository`** + consumers above.
