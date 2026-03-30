# Organization-scoped client list/count — truth audit (FOUNDATION-17)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-17 — ORGANIZATION-SCOPED-CLIENT-INDEX-COUNT-TRUTH-AUDIT  
**Type:** Read-only proof only (no repository/service/controller/runtime changes).  
**Parent truth:** FOUNDATION-06 through FOUNDATION-16 (F-11 `ClientService` mutates, F-15 client read audit, F-16 `ClientRepository::find` / `findForUpdate` org EXISTS).  
**Companion matrix:** `CLIENT-LIST-COUNT-CALLER-MATRIX-FOUNDATION-17.md`.

---

## Q1 — Which staff/internal surfaces currently call `ClientRepository::list` and `ClientRepository::count`?

**Direct calls** (PHP references `ClientRepository` instance → `list` / `count`):

| Method | `list` | `count` | Location |
|--------|--------|---------|----------|
| `ClientController::index` | Yes | Yes | ```43:54:system/modules/clients/controllers/ClientController.php``` |
| `ClientController::registrationsShow` | Yes (conditional) | **No** | ```398:409:system/modules/clients/controllers/ClientController.php``` |
| `ClientListProviderImpl::list` | Yes | **No** (provider has no `count`) | ```16:20:system/modules/clients/providers/ClientListProviderImpl.php``` |

**No other** `ClientRepository::list` / `::count` call sites exist under `system/modules` (verified via repository-wide search for `ClientRepository` usage: services such as `MembershipService`, `IntakeFormService`, `OutboundTransactionalNotificationService` use `find` / `findForUpdate` only, not list/count).

**Indirect** exposure to `ClientRepository::list`: any code calling `Core\Contracts\ClientListProvider::list`, because the only implementation is `ClientListProviderImpl`, which delegates to `ClientRepository::list` (```16:20:system/modules/clients/providers/ClientListProviderImpl.php```). Registered consumers include (bootstrap): `InvoiceController`, `AppointmentController`, `GiftCardController`, `ClientPackageController`, `ClientMembershipController` (`register_sales_public_commerce_memberships_settings.php`, `register_packages.php`, `register_gift_cards.php`, `register_appointments_online_contracts.php`).

---

## Q2 — Which callers are staff-only and isolated enough for a first index/count implementation wave?

**Staff-authenticated, clients-module-only (direct repo):**

- **`ClientController::index`** — route `GET /clients` with `AuthMiddleware` + `PermissionMiddleware::for('clients.view')` (```9:9:system/routes/web/register_clients.php```).
- **`ClientController::registrationsShow`** — route `GET /clients/registrations/{id}` with `clients.view` (```19:19:system/routes/web/register_clients.php```). Uses `ClientRepository::list` only for optional `client_search` autocomplete-style results.

These two are **the only** direct callers and are **confined to the clients module** and **staff auth**.

**Not “isolated” for a behavioral change that only touches these methods without touching the repository:** any future **repository-wide** `list`/`count` change also hits **`ClientListProviderImpl`** and therefore **cross-domain** staff UIs (see Q3).

---

## Q3 — Which callers must be deferred (invoice dropdowns, providers, public, merge, etc.)?

| Surface | Why defer / treat as high-coupling |
|---------|-----------------------------------|
| **`ClientListProvider` + `ClientListProviderImpl`** | Single implementation feeds **invoice**, **appointment**, **gift card**, **client package**, **client membership** controllers — **not** “client index” only. Any change to `ClientRepository::list` semantics affects **all** `clientList->list($branchId)` call sites unless the implementation is split or gated. |
| **`InvoiceController`** | Uses `$this->clientList->list(...)` for client pickers (e.g. ```80:81:system/modules/sales/controllers/InvoiceController.php``` and similar lines). Explicitly **out of scope** for this audit wave’s implementation; **acceptance** for a repo-level `list` change must include invoice flows or defer provider until a coordinated wave. |
| **`AppointmentController`**, **`GiftCardController`**, **`ClientPackageController`**, **`ClientMembershipController`** | Same pattern: `ClientListProvider` for dropdowns. **Packages / memberships / appointments** are adjacent domains named **out of scope** for F-17 implementation. |
| **Merge / duplicate search** | `ClientController::index` calls `ClientService::searchDuplicates` **after** list/count (```59:65:system/modules/clients/controllers/ClientController.php```). **Does not** use `ClientRepository::list` for duplicates, but **same page** couples UX; org scoping **duplicates** remains a **separate** wave (F-15 already deferred `searchDuplicates` / `findDuplicates`). |
| **Public resolution** | `PublicClientResolutionService` uses **branch-keyed** `ClientRepository` lock/find helpers, **not** `list`/`count` — **no** direct coupling to index/count (no change to this audit’s inventory). |

---

## Q4 — Exact current SQL patterns in `ClientRepository::list` and `count`

Evidence: ```132:168:system/modules/clients/repositories/ClientRepository.php```.

| Aspect | Behavior |
|--------|----------|
| **Base predicate** | `WHERE deleted_at IS NULL` on `clients` (no table alias today). |
| **`branch_id`** | **Optional:** if `!empty($filters['branch_id'])`, append `AND branch_id = ?`. If key absent or empty, **no** branch restriction → **all branches** (and any `branch_id` NULL rows) that match other filters. |
| **Search** | **Optional:** if `!empty($filters['search'])`, `LIKE` on `first_name`, `last_name`, `email`, `phone` (same sub-expression for both `list` and `count`). |
| **Sort / pagination** | `list`: `ORDER BY last_name, first_name LIMIT ? OFFSET ?`. `count`: no order/limit — matches filter set only. |
| **Organization** | **None** at SQL layer (F-16 scoped **`find` / `findForUpdate`** only). |

---

## Q5 — Smallest safe org-aware behavior for `list`/`count` under resolved `OrganizationContext` (without opening duplicate/merge/public/provider work in the same acceptance)

**Proved pattern to mirror:** F-16 uses **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause`** with a **`clients` alias** and **resolved org id only** (no request org id).

**Minimal SQL-shaped behavior** for `list`/`count` when `resolvedOrganizationId()` is non-null:

- Add the same **EXISTS (branches ∩ active organizations)** predicate tied to `clients.branch_id`, requiring **non-null** `branch_id` (same fail-closed semantics as F-16 for NULL branch rows).
- When org context is **unresolved**, keep **current** `list`/`count` SQL (empty fragment pattern as in `PayrollRunRepository::find` / F-14).

**What this wave does *not* require in the same acceptance:** changing `ClientService::searchDuplicates`, merge preview, `PublicClientResolutionService`, or **altering** `ClientListProvider` **interface** — but see Q9: a **repository-level** change **does** affect `ClientListProviderImpl` **behavior** without any provider code edit.

---

## Q6 — Null-branch / “global” semantics today; safest treatment when org is resolved

**Today:** `list`/`count` **do not** exclude `branch_id IS NULL` unless a branch filter is applied. Such rows participate in **unscoped** list and in **search-only** filters.

**When org is resolved (recommended alignment with F-16):** treat NULL `branch_id` like **not owned** by any org branch → **exclude** from results (fail-closed). The shared **`branchColumnOwnedByResolvedOrganizationExistsClause`** already enforces **`branch_id IS NOT NULL`** plus EXISTS.

**Data note:** If legacy rows exist with `branch_id` NULL, staff may see them disappear from org-resolved list/count until data is repaired — same class of change as F-16 `find`.

---

## Q7 — Exact methods for first implementation wave vs stay out

**Candidate “first wave” focus (product: client index + registration client search):**

- **`ClientController::index`** — must remain coherent with any `ClientRepository::list`/`count` org rule (primary **index/count** surface).
- **`ClientController::registrationsShow`** — **list** only, search-only filters; should follow the **same** repository rules as index if repo is the enforcement point.

**Stay out of *declared* scope for F-17 audit / do not assume fixed in the same minimal acceptance without explicit sign-off:**

- **`ClientListProvider` / `ClientListProviderImpl`** and all **invoice / appointment / package / gift card / membership** dropdown call sites — **either** included in **regression/acceptance** for a repo-wide `list` change **or** deferred via a **narrower** implementation (e.g. dedicated repository methods used **only** from `ClientController`, leaving provider on legacy `list` until a later wave).

**Stay out (separate waves per F-15):**

- `ClientRepository::searchDuplicates` / `findDuplicates`, merge flows, notes, audit, issue flags, custom fields, profile providers, invoices/payments core, public flows.

---

## Q8 — Acceptance gates for the **next** implementation wave (post-audit)

1. **Predicate source:** Org scope derives from **`OrganizationRepositoryScope::resolvedOrganizationId()`** / **`OrganizationContext`** only — never raw request org id.
2. **Parity:** `list` and `count` apply the **same** filter fragment for a given `$filters` array so pagination totals match rows.
3. **Null org context:** When org unresolved, behavior matches **pre-change** `list`/`count` (empty scope fragment).
4. **NULL `branch_id`:** Documented fail-closed when org resolved (aligned with F-16).
5. **Staff client index:** `GET /clients` and registration show client search behave as expected under multi-org fixtures (manual or scripted smoke).
6. **Cross-domain:** If **`ClientListProviderImpl`** is not split, **explicit** smoke on at least one **invoice** and one **appointment** client dropdown path **or** documented decision to ship client-module-only wrapper methods first.
7. **Proof artifact:** New read-only verifier (pattern: F-13/F-14/F-16) proving `list`/`count` bodies contain the scope hook; ops doc update.

---

## Q9 — Highest regression risks if `list`/`count` scoping is applied too broadly too early

1. **Dropdown cardinality / wrong options:** Invoice or appointment forms that passed `branchId = null` today receive up to **500** clients **globally** (```16:19:system/modules/clients/providers/ClientListProviderImpl.php```). Tightening to org-scoped **changes** who appears **without** UI messaging — can block legitimate cross-branch workflows if product expected global list.
2. **Search-only index:** `ClientController::index` often passes **only** `search` — **no** `branch_id` (```52:54:system/modules/clients/controllers/ClientController.php```). Org EXISTS reduces leakage but **does not** add **current branch** filter; branch semantics still **only** from `BranchContext` elsewhere (e.g. `ensureBranchAccess` on detail). Operators may **expect** index to match **branch** — today it does **not** unless filters extended (behavioral product gap, not org gap).
3. **Count/list mismatch** if one method is updated and the other omitted.
4. **Performance:** EXISTS per row on large lists — acceptable at current pagination (e.g. 20) but worth watching on `ClientListProvider`’s **500** cap.

---

## Q10 — Phased order after the first client index/count wave succeeds

1. **Hardening / proof:** Read-only verifier + ops doc; optional split **`ClientListProvider`** vs **clients-module-only** list API if cross-domain regression risk is unacceptable.
2. **Provider-aligned wave:** Coordinate **`ClientListProviderImpl`** + **invoice / appointment / package / gift / membership** QA; consider **always** passing non-null `branch_id` from `BranchContext` where missing today (controller-level, separate from repo).
3. **Duplicate / merge / searchDuplicates** — F-15 deferred; org predicates **after** list/count boundary is stable.
4. **Broader client read surfaces** (field defs, profile SQL, etc.) — per F-15 matrix, **not** immediate next step unless explicitly prioritized.

---

## Explicit non-goals (FOUNDATION-17)

- No `ClientRepository` edits, no middleware, no schema, no UI, no duplicate/merge implementation, no `ClientListProvider` contract change **in this wave** (audit only).
- No recommendation for a **full** client search/read rollout as the mandatory immediate next step; next step should remain **small**: either **repo `list`/`count` + declared cross-domain acceptance** or **narrow API** used only from `ClientController`.
