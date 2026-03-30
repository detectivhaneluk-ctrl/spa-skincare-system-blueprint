# Organization-scoped client list provider — consumer truth audit (FOUNDATION-19)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-19 — ORGANIZATION-SCOPED-CLIENT-LIST-PROVIDER-CONSUMER-TRUTH-AUDIT  
**Kind:** Read-only, proof-first; **no** implementation, enforcement, UI, refactor, schema, or FOUNDATION-20 design.  
**Accepted upstream:** FOUNDATION-06–17 (ZIP accepted); FOUNDATION-18 = minimal **`ClientRepository::list` / `count`** org predicate; **`ClientListProviderImpl`** intentionally unchanged in F-18.

---

## 1) Exact files reviewed (this wave)

| Area | Path |
|------|------|
| Provider + contract | `system/modules/clients/providers/ClientListProviderImpl.php`, `system/core/contracts/ClientListProvider.php` |
| Repository delegation target | `system/modules/clients/repositories/ClientRepository.php` (`list`, `count`) |
| Org scope helper | `system/core/organization/OrganizationRepositoryScope.php` |
| DI binding | `system/modules/bootstrap/register_clients.php` |
| Consumers | `system/modules/sales/controllers/InvoiceController.php`, `system/modules/appointments/controllers/AppointmentController.php`, `system/modules/gift-cards/controllers/GiftCardController.php`, `system/modules/packages/controllers/ClientPackageController.php`, `system/modules/memberships/controllers/ClientMembershipController.php` |
| Consumer DI | `register_sales_public_commerce_memberships_settings.php`, `register_appointments_online_contracts.php`, `register_gift_cards.php`, `register_packages.php` |
| Routes (staff auth proof) | `system/routes/web/register_sales_public_commerce_staff.php`, `system/routes/web/register_appointments_calendar.php`, `system/modules/gift-cards/routes/web.php`, `system/modules/packages/routes/web.php`, `system/modules/memberships/routes/web.php` |

**In-repo `ClientListProvider` PHP references:** contract, implementation, five controllers, four bootstrap factories, F-18 verifier comments only — **no** additional production consumers beyond the five controllers.

---

## 2) Provider surface in `ClientListProviderImpl`

| Method | Signature | Behavior |
|--------|-----------|----------|
| **`list`** | `list(?int $branchId = null): array` | Builds `$filters = $branchId !== null ? ['branch_id' => $branchId] : []`, then **`$this->repo->list($filters, 500, 0)`**. |

The interface **`Core\Contracts\ClientListProvider`** exposes **only** `list(?int $branchId = null): array`. There is **no** `count`, search, or alternate entrypoint on the provider.

---

## 3) Delegation chain (every consumer)

**Truth:** Every `ClientListProvider::list` call resolves to **`ClientListProviderImpl`** (singleton in `register_clients.php`) and therefore reaches **`ClientRepository::list`** with **no** intermediate wrapper besides filter construction and fixed **limit 500 / offset 0**.

---

## 4) Org-scope inheritance after FOUNDATION-18 (strict)

**A) Does `ClientListProviderImpl` inherit F-18 org scoping in all relevant methods, or only some paths?**

- **All** provider usage is the single method **`list`**, which **always** calls **`ClientRepository::list`**.  
- **`ClientRepository::list`** appends **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause('c')`** on every invocation when org resolves (see ```132:154:system/modules/clients/repositories/ClientRepository.php```).  
- **Conclusion:** Inheritance is **complete** for the provider: there is **no** code path through `ClientListProviderImpl` that bypasses `ClientRepository::list`.

**When organization context does *not* resolve** (`OrganizationRepositoryScope::resolvedOrganizationId()` → null), the scope helper returns an **empty** fragment (```30:35:system/core/organization/OrganizationRepositoryScope.php```) — **legacy unscoped** `list` behavior (same F-18 unresolved-org rule). This is **not** “safe isolation”; it is **explicitly legacy** until context resolution is guaranteed.

**When organization context resolves:** clients with **`branch_id` NULL** are **excluded** from `list` (F-18 fail-closed alignment).

**B) Which consumers effectively inherit org scoping?**

- **All five controllers** inherit the **same** repository predicate **whenever** the request’s resolved organization id is non-null, because **all** use only `ClientListProvider` → `ClientListProviderImpl` → `ClientRepository::list`.

**C) High-coupling / containment**

- **Cross-module blast radius:** One interface feeds **sales, appointments, gift cards, packages, memberships** — any future change to **`ClientListProvider`** or **`ClientRepository::list`** semantics affects **all** dropdowns together.  
- **Per-controller branch arguments:** Multiple call sites pass **`null`** branch (HQ / no context / validation re-render). That does **not** skip org scope when org resolves; it only skips the **optional** `branch_id = ?` filter — meaning **up to 500 clients across all in-org branches** can appear, which is a **product/UX** concern distinct from “no org predicate.”

**D) Smallest safe FOUNDATION-20 enforcement boundary**

- **No additional provider-level enforcement boundary is provably necessary** for org SQL: the **repository** already applies the org fragment on every `list`. **`ClientListProviderImpl`** adds no alternate query path.  
- A **provider-only** “enforcement” wave would either **duplicate** repository logic (unsafe to drift) or impose **new** rules (e.g. reject `null` branch) that are **not** provably safe without per-product decisions — **high regression risk** on HQ and error re-render paths.  
- **Recommendation:** Treat **FOUNDATION-20** as **waiver / containment / QA** (documented smoke per consumer or explicit product waiver), **or** a **controller-level** program (non-minimal) if the goal is stricter branch pinning — **not** a minimal provider patch.

---

## 5) Required questions — short answers

| Q | Answer |
|---|--------|
| **A** | **All** provider paths inherit F-18 scoping via **`ClientRepository::list`** — single method, no bypass. |
| **B** | **InvoiceController**, **AppointmentController**, **GiftCardController**, **ClientPackageController**, **ClientMembershipController** — all inherit when org resolves. |
| **C** | **All five** are high-coupling relative to a narrow “clients module only” perimeter; **ClientMembershipController** additionally composes **`ClientRepository::find`** for HQ scope resolution (F-15 noted coupling). |
| **D** | **None** at **provider** layer that is both **minimal** and **non-redundant** with F-18; name explicitly: **no safe provider-level enforcement boundary beyond existing `ClientRepository::list`**. |
| **E** | **FOUNDATION-20** should be **waiver / containment / QA** (or defer to a deliberate controller/product wave), **not** provider-only enforcement for org SQL. |

---

## 6) Staff vs public vs background

| Evidence | Conclusion |
|----------|------------|
| Every HTTP route for the five controllers uses **`AuthMiddleware::class`** plus domain **`PermissionMiddleware::for(...)`** (see route files cited in §1). | **Staff-authenticated, permission-gated** — **not** anonymous public entrypoints. |
| No `ClientListProvider` usage in CLI/cron files in repo grep. | **No** in-repo **background/automation** consumer of this contract. |

**Mixed/internal:** Staff UI flows that combine **HTML forms** and **JSON** elsewhere in the same controller class do **not** change the **client list provider** call sites audited here — **`ClientListProvider` is not used** by `AppointmentController::dayCalendar()` (JSON); that action does not touch the provider.

---

## 7) Deliverables

| Artifact | Purpose |
|----------|---------|
| This file | Primary F-19 ops truth |
| `CLIENT-LIST-PROVIDER-CONSUMER-MATRIX-FOUNDATION-19.md` | Per-consumer matrix (file, method, call, route, auth, org/null-branch risk, F-20 status) |

**Verifier script:** Not added — static binding and single implementation already give **exact** proof; F-18 verifier remains the repo SQL gate.

---

## 8) Explicitly not advanced this wave

- **FOUNDATION-20** specification, implementation, or ZIP.  
- Any edit to **`ClientListProviderImpl`**, **`ClientRepository`**, controllers, routes, or middleware.  
- **Public** booking/commerce client pickers (out of scope for this provider audit).  
- **`ClientRepository::count`** via provider (provider has **no** `count`).

---

## 9) Checkpoint readiness

This wave is **complete** as a **read-only** consumer and inheritance audit. **Next step** for governance: **full ZIP truth review** (human/process). **Waiver/QA closure:** `ORGANIZATION-SCOPED-CLIENT-LIST-CONSUMER-WAIVER-CONTAINMENT-QA-FOUNDATION-20-OPS.md` + `CLIENT-LIST-PROVIDER-MANUAL-SMOKE-MATRIX-FOUNDATION-20.md` (FOUNDATION-20).
