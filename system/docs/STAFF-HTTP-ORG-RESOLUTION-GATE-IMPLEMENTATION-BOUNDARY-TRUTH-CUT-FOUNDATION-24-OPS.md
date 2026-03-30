# Staff HTTP org resolution gate — implementation boundary truth cut (FOUNDATION-24)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-24 — STAFF-HTTP-ORGANIZATION-CONTEXT-HARDENING-IMPLEMENTATION-BOUNDARY-TRUTH-CUT  
**Mode:** Read-only boundary definition — **no** implementation, code edits, or enforcement.  
**Policy source:** `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-DECISION-CLOSURE-FOUNDATION-23-OPS.md` (FOUNDATION-23) + exception map.

**Companion:** `STAFF-HTTP-ORG-RESOLUTION-GATE-FILE-IMPACT-MATRIX-FOUNDATION-24.md`

---

## A) Exact future implementation choke point (minimal)

**Chosen choke:** **Immediately after `AuthMiddleware` has accepted the session** (all checks inside `AuthMiddleware::handle` passed) and **before** `$next()` invokes the remainder of the route pipeline (typically `PermissionMiddleware` → controller).

**Why not earlier in the global stack:** `OrganizationContextMiddleware` runs **before** `AuthMiddleware` (```44:47:system/core/router/Dispatcher.php```). It **cannot** know staff authentication outcome. **Policy (F-23)** requires **post-auth** semantics.

**Why not primary placement in `OrganizationContextResolver` / `OrganizationContextMiddleware`:** Resolver must stay **branch + DB org-count only** (F-09); mixing **“is staff”** into resolver **conflates** identity with tenancy derivation. Middleware that runs **before** auth **cannot** apply **staff-only** rules without duplicating auth logic.

**Minimal structural pattern (two equivalent options — pick one at implementation time):**

| Pattern | Files touched (implementation estimate) | Notes |
|---------|----------------------------------------|--------|
| **P1 — Auth hook + policy service** | `AuthMiddleware.php` + **new** policy class + `bootstrap.php` registration | `AuthMiddleware` is often `new AuthMiddleware()` (see ```60:61:system/core/router/Dispatcher.php```); hook should use **`Application::container()->get(...)`** inside `handle()` **or** register `AuthMiddleware` in container — **proof:** container registration decision is part of implementation. |
| **P2 — New middleware + Dispatcher pipeline insert** | **New** `MiddlewareInterface` + `Dispatcher.php` (insert class **after** `AuthMiddleware` entry in per-route list) | Keeps `AuthMiddleware` file free of org policy; requires **deterministic** insertion rule (e.g. after first `AuthMiddleware::class` in `$match['middleware']`). |

**Recommended for smallest behavioral clarity:** **P1** (single **known** post-auth point; **no** pipeline rewriting). **Recommended for separation of concerns:** **P2**.

---

## B) Exact file / function list (future implementation)

| Artifact | Role |
|----------|------|
| **`Core\Middleware\AuthMiddleware::handle`** **or** **`Core\Router\Dispatcher::dispatch` / `runPipeline`** | **Only one** of these should gain the **call site** for the gate (P1 vs P2). |
| **New:** e.g. `Core\Organization\StaffMultiOrgOrganizationResolutionGate` (final class) | Encapsulate: `active_orgs > 1` ∧ `OrganizationContext::getCurrentOrganizationId() === null` ∧ authenticated → **deny** (403/409/302 per F-23 product choice); else no-op. **No** UI. |
| **`system/bootstrap.php`** | `singleton` registration for the new gate (and optionally move `AuthMiddleware` to container if constructor DI chosen). |
| **Optional (non-rule change):** `OrganizationContextResolver` | **Public read-only** `countActiveOrganizations()` (or equivalent) **only** if duplicating SQL in the gate is unacceptable — **must not** alter `resolveForHttpRequest` branching. |

**Explicitly not the primary choke:** `OrganizationContextMiddleware`, `BranchContextMiddleware`, `OrganizationContextResolver::resolveForHttpRequest` **logic**, any **repository**, any **controller**.

---

## C) Exact downstream affected surfaces

**Behavioral (no file edits required in these layers for minimal wave):**

- **Every HTTP route** whose middleware stack includes **`AuthMiddleware`** (staff session after successful auth). Guest-only routes (`GuestMiddleware` without auth success path) **unaffected** by a post-auth gate **inside** auth success path.
- **All controllers** behind those routes: they **stop receiving** requests where **multi-org** + **staff** + **unresolved org** previously reached **legacy** `OrganizationRepositoryScope` paths (F-21).
- **Indirect:** F-13/F-14/F-16/F-18 **runtime** behavior **changes** only because **request never arrives** with null org under those conditions — **SQL is unchanged**.

**Route inventory:** `AuthMiddleware::class` appears across central `system/routes/web/register_*.php` and module `routes/web.php` files (grep-backed count in matrix doc).

---

## D) Exact non-targeted / out-of-scope areas (must remain untouched in minimal wave)

| Area | Reason |
|------|--------|
| **`OrganizationContextResolver::resolveForHttpRequest`** | **No** new guessing; **no** staff rules in resolver. |
| **`OrganizationContextMiddleware`** | Runs pre-auth; **wrong** lifecycle for staff-only gate. |
| **`BranchContextMiddleware`** | Branch selection policy is **separate**; F-23 does not redefine it in minimal slice. |
| **All repositories** (`ClientRepository`, marketing, payroll, …) | F-23 rejected repo-wide hardening; gate **supersedes** need for staff multi-org **legacy** path. |
| **`OrganizationScopedBranchAssert`**, `InvoiceService`, `ClientService`, … | F-11 choke points **unchanged** in minimal wave. |
| **Schema, migrations, `users.organization_id`** | Out of scope. |
| **Controllers** | **No** per-controller preconditions in minimal wave (F-23). |
| **UI / views** | Product; redirect target may be **URL only** in config. |

**Exception allowlists (F-23 E1–E5):** If added, they live **in the gate class or small config** — **still** within **B)** file set; **must not** sprawl into repositories.

---

## E) Exact smallest smoke / test boundary (future code wave)

| # | Scenario | Expected |
|---|----------|----------|
| S1 | **Single** active org, staff, **null** branch | **200** on a typical dashboard (org **resolved** via fallback). |
| S2 | **≥2** active orgs, staff HQ **no** branch/session/request branch | **403/409** or **302** (per product) — **not** 200 to normal app surface. |
| S3 | **≥2** orgs, staff with **valid** `branch_id` context | **200**; org **`branch_derived`**. |
| S4 | **GET /login** (Guest) | **Unchanged** (no auth success path). |
| S5 | **POST /login** success → first redirect | **Product-defined** (may need branch pick) — document **separately**. |
| S6 | Documented **waiver** route (if any) | **200** or **intended** behavior per allowlist. |

**Automated:** Optional read-only script later **not** required for F-24 boundary doc; **manual** S1–S4 suffice for **minimal** proof.

---

## F) Exact risks if scope drifts wider than this boundary

| Drift | Risk |
|-------|------|
| Edit **resolver** to auto-select org | **Violates F-09**; cross-org data bleed. |
| Add **repository** `if (staff)` checks | **Fragmented** policy, misses paths, duplicates F-21 dual-path. |
| **Controller** preconditions everywhere | **Omissions**, merge conflicts, inconsistent HTTP codes. |
| Gate in **global** middleware **before** Auth | **Wrong** session/touch order; may block or allow incorrectly. |
| **PermissionMiddleware** before gate | Gate should run **before** permission checks only if product wants **403 org before 403 permission** — **document** order; default F-23: **after full AuthMiddleware success**, typically **before** `PermissionMiddleware` if inserted as separate middleware **between** Auth and Permission. |
| Large **allowlist** without audit | **Silent** legacy cross-org reads persist on “forgotten” routes. |

**Order proof (typical route):** `[AuthMiddleware, PermissionMiddleware, …]` — gate should be **immediately after Auth** and **before Permission** so **unauthenticated** still 401, **authenticated but no org** fails **before** permission noise.

---

## G) Answers to task goals (summary)

1. **Minimal files:** **AuthMiddleware** *or* **Dispatcher** + **new gate class** + **bootstrap** (+ **optional** resolver **read-only** accessor).  
2. **Primary locus:** **Not** `OrganizationContextMiddleware` / resolver rules; **yes** **post-auth** (**mixed minimal** = hook in Auth **or** injected middleware + Dispatcher).  
3. **Downstream:** All **AuthMiddleware**-protected staff routes; **legacy repo paths** avoided by **prevention**, not SQL edits.  
4. **Smallest smoke:** S1–S4 (+ S5/S6 as product requires).  
5. **Not touched:** Repos, resolver semantics, branch middleware, controllers (minimal wave).

---

## Naming alignment (F-23 vs this wave)

FOUNDATION-23 named the **code** slice **“FOUNDATION-24 — … GATE (minimal enforcement).”** **This** deliverable is **FOUNDATION-24 = boundary truth cut only** (docs). The **first code wave** that applies the gate should be **re-numbered in governance** (e.g. **FOUNDATION-25**) so **F-24** stays **audit-only** in the artifact trail, **unless** program management explicitly collapses boundary + code under one id.

---

## Checkpoint readiness

FOUNDATION-24 **boundary cut** documented. **No** code wave executed. **Next:** **implementation** wave (suggest **FOUNDATION-25** if ids must stay distinct) when approved.
