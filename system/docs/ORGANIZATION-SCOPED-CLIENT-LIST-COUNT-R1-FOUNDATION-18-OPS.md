# Organization-scoped client list/count — minimal R1 (FOUNDATION-18)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-18 — ORGANIZATION-SCOPED-CLIENT-LIST-COUNT-MINIMAL-ENFORCEMENT-R1  
**Source of truth:** FOUNDATION-06 through FOUNDATION-17; implementation mirrors F-16 `ClientRepository::find` / `findForUpdate` predicate.

---

## 1) Exact old truth (pre–F-18)

- `ClientRepository::list` / `count` queried `clients` **without** table alias, **`WHERE deleted_at IS NULL`**, optional **`search`** LIKE on name/email/phone, optional **`branch_id = ?`**, **no** organization EXISTS (per F-17 audit).
- F-16 added org scope **only** on **`find` / `findForUpdate`**.

---

## 2) Exact new repository behavior

- Both methods use alias **`c`**, same optional **`search`** and **`branch_id`** predicates (now qualified as **`c.`** columns).
- After those filters, both append **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause('c')`** — identical fragment and parameter merge order so **list and count stay in parity** for the same `$filters`.
- **`list`:** fragment is applied **before** `ORDER BY c.last_name, c.first_name LIMIT … OFFSET …`.

---

## 3) Unresolved organization — unchanged behavior

- When **`OrganizationRepositoryScope::resolvedOrganizationId()`** is null, the scope helper returns an **empty** SQL fragment and **no** extra parameters — effective SQL matches the **pre–F-18** semantics aside from the harmless **`c`** alias and **`c.`** column qualifiers.

---

## 4) Resolved organization + `NULL branch_id` — fail-closed

- The shared clause requires **`c.branch_id IS NOT NULL`** and an **EXISTS** chain to **`branches`** + active **`organizations`** for the resolved org id (same as F-16).
- Rows with **`branch_id` NULL** therefore **never** appear in **`list`/`count`** when org context resolves.

---

## 5) Direct caller perimeter **validated** for this wave (staff)

| Surface | Evidence |
|---------|----------|
| Client index | `ClientController::index` → `$this->repo->list` + `$this->repo->count` (```52:54:system/modules/clients/controllers/ClientController.php```) |
| Registration client search | `ClientController::registrationsShow` → `$this->repo->list(['search' => …], …)` (```408:409:system/modules/clients/controllers/ClientController.php```) |
| Routes | `GET /clients`, `GET /clients/registrations/{id}` — `AuthMiddleware` + `clients.*` permissions (`register_clients.php`) |

**Read-only code proof:** `php scripts/verify_client_repository_org_scope_foundation_18_readonly.php` (method bodies + `ClientController` call sites).

---

## 6) Deferred: `ClientListProviderImpl` and cross-module consumers (explicit waiver)

- **`ClientListProviderImpl` was not edited** in F-18 (per wave rules).
- It still calls **`ClientRepository::list`**; therefore **invoice**, **appointment**, **gift card**, **client package**, and **client membership** dropdowns **inherit** the new org predicate **whenever** HTTP **`OrganizationContext` resolves** — **without** separate smoke or acceptance in this wave.
- **This wave does not claim** cross-module dropdown safety. Follow-up: dedicated QA or a later wave that addresses provider contract / caller `branch_id` behavior if product requires stricter guarantees. **Consumer proof inventory:** `ORGANIZATION-SCOPED-CLIENT-LIST-PROVIDER-CONSUMER-TRUTH-AUDIT-FOUNDATION-19-OPS.md` + `CLIENT-LIST-PROVIDER-CONSUMER-MATRIX-FOUNDATION-19.md` (FOUNDATION-19). **QA / waiver closure:** `ORGANIZATION-SCOPED-CLIENT-LIST-CONSUMER-WAIVER-CONTAINMENT-QA-FOUNDATION-20-OPS.md` + `CLIENT-LIST-PROVIDER-MANUAL-SMOKE-MATRIX-FOUNDATION-20.md` (FOUNDATION-20).

---

## 7) Manual smoke procedure (staff perimeter only)

Prerequisites: staff user with `clients.view`; multi-org fixture optional (single org still exercises “resolved org” on typical requests).

1. **Index:** `GET /clients` — confirm page loads; with search query, rows are only clients whose **`branch_id`** belongs to the resolved organization (no foreign-org clients).
2. **Registrations:** Open `GET /clients/registrations/{id}` for a registration in-branch; use **`client_search`** with a string that matches a client in-org — results should not include out-of-org clients.

---

## 8) Proof commands

```bash
cd system
php scripts/verify_client_repository_org_scope_foundation_18_readonly.php
php scripts/verify_client_repository_org_scope_foundation_16_readonly.php
php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php
```

---

## 9) Explicit non-goals (F-18)

- No **`ClientListProvider` / `ClientListProviderImpl`** changes, no UI changes, no duplicate/merge/searchDuplicates work, no new features, no FOUNDATION-19.
