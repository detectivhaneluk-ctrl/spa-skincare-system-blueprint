# Staff multi-org organization resolution gate R1 — post-implementation truth audit (FOUNDATION-26)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-26 — STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-POST-IMPLEMENTATION-TRUTH-AUDIT  
**Mode:** Read-only code audit — **no** implementation, enforcement edits, middleware rewrites, repositories, controllers, UI, schema, refactors, or verifiers unless separately approved.  
**Subject:** FOUNDATION-25 minimal gate as merged in repo (ZIP parity assumed; **manual runtime smoke** still not proven by this document alone).  
**Policy / design baselines:** FOUNDATION-23 (closure), FOUNDATION-24 (implementation boundary), FOUNDATION-25 (implementation ops).  
**Companion matrix:** `STAFF-MULTI-ORG-GATE-R1-TRIGGER-ESCAPE-MATRIX-FOUNDATION-26.md`

---

## 1) Files reviewed (evidence scope)

| Path | Role |
|------|------|
| `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php` | Gate predicate, exemptions, 403 response |
| `system/core/middleware/AuthMiddleware.php` | Post-auth insertion point; ordering vs password expiry |
| `system/core/Organization/OrganizationContextResolver.php` | `countActiveOrganizations()` + unchanged `resolveForHttpRequest` |
| `system/core/Organization/OrganizationContext.php` | `getCurrentOrganizationId()` contract |
| `system/core/middleware/OrganizationContextMiddleware.php` | Pre-auth resolution call |
| `system/core/router/Dispatcher.php` | Global vs per-route pipeline order |
| `system/bootstrap.php` | DI registration for gate + resolver |
| `system/docs/STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-FOUNDATION-25-OPS.md` | Declared R1 behavior |
| `system/docs/STAFF-HTTP-ORG-RESOLUTION-GATE-IMPLEMENTATION-BOUNDARY-TRUTH-CUT-FOUNDATION-24-OPS.md` | F-24 boundary intent |
| `system/docs/STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-DECISION-CLOSURE-FOUNDATION-23-OPS.md` | F-23 policy text |

---

## 2) Exact runtime boundary of the gate

- **Locus:** Only inside `AuthMiddleware::handle`, after `AuthService::check()`, inactivity touch, and password-expiry handling on the **non-early-return** success path, **immediately before** `$next()` (which continues the per-route stack, typically `PermissionMiddleware` → controller).  
- **Evidence:** ```50:52:system/core/middleware/AuthMiddleware.php
        Application::container()->get(StaffMultiOrgOrganizationResolutionGate::class)->enforceForAuthenticatedStaff();
        $next();
```
- **Not in gate:** Guest/public routes without `AuthMiddleware`, failed auth (401/redirect), failed inactivity (logout + deny), password-expired block on non-exempt paths (403/redirect **without** invoking the org gate).
- **Global context already computed:** For every dispatched request, `BranchContextMiddleware` then `OrganizationContextMiddleware` run **before** any per-route middleware, so `OrganizationContext` reflects F-09 resolution **before** `AuthMiddleware` runs.  
- **Evidence (order):** ```20:47:system/core/router/Dispatcher.php
    private array $globalMiddleware = [
        \Core\Middleware\CsrfMiddleware::class,
        \Core\Middleware\ErrorHandlerMiddleware::class,
        \Core\Middleware\BranchContextMiddleware::class,
        \Core\Middleware\OrganizationContextMiddleware::class,
    ];
    // ...
        $pipeline = array_merge(
            $this->globalMiddleware,
            $match['middleware'],
```

---

## 3) Exact post-auth insertion point and execution order

1. Global: CSRF → ErrorHandler → **BranchContext** → **OrganizationContext** (`resolveForHttpRequest`).  
2. Per-route: **`AuthMiddleware`** (session OK → optional password expiry branch → **then** `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()` → `$next()`).  
3. **Relative to permission checks:** Gate runs **before** `PermissionMiddleware` when both are listed after `AuthMiddleware` (typical route shape in repo).  
- **Password expiry exempt short-circuit:** If password is expired **and** path is `POST /logout` or `GET`/`POST /account/password`, `AuthMiddleware` calls `$next()` **without** calling the org gate (```43:45:system/core/middleware/AuthMiddleware.php```). This is an **Auth-layer** bypass of the gate for that narrow case (see matrix).

---

## 4) Exact response behavior / status / message on block

- **HTTP status:** `403` for both JSON and plain responses.  
- **JSON** when `HTTP_ACCEPT` contains substring `application/json` (same pattern as other `AuthMiddleware` denies):  
  - `Content-Type: application/json; charset=utf-8`  
  - Body: `{ "success": false, "error": { "code": "ORGANIZATION_CONTEXT_REQUIRED", "message": "<stable message>" } }`  
- **Non-JSON:** `Content-Type: text/plain; charset=utf-8`, body = same human-readable message.  
- **Stable message:** `Organization context is required before continuing. Select a branch or contact an administrator.`  
- **Termination:** `exit` after headers/body — no controller, no further middleware in the pipeline.

**Evidence:** ```67:85:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php```

---

## 5) Exact condition set that triggers the block

All of the following must hold:

1. Request reaches `enforceForAuthenticatedStaff()` (see §3 for prerequisites).  
2. **Not** an exempt path (`isExemptRequestPath()` false): not `POST /logout`, not `GET`/`POST /account/password` on normalized path `/account/password`.  
3. `OrganizationContextResolver::countActiveOrganizations() > 1` (SQL: `COUNT(*)` of `organizations` where `deleted_at IS NULL`).  
4. `OrganizationContext::getCurrentOrganizationId()` is `null` **or** `<= 0`.

**Evidence:** ```27:42:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php```

---

## 6) Exact conditions that do **not** trigger the block

- **≤ 1 active organization** (`countActiveOrganizations() <= 1`): immediate return — includes **0** and **1** active org rows.  
- **Resolved org:** `getCurrentOrganizationId() !== null` **and** `> 0`.  
- **Exempt paths:** `POST /logout`, `GET`/`POST /account/password` (normalized path; trailing slash stripped to match `/logout` and `/account/password`).  
- **Never reaches gate:** unauthenticated routes, auth failure, inactivity failure, password-expired denial on non-exempt paths, password-expired **exempt** early `$next()` (gate skipped).

---

## 7) Unchanged paths (code-level confirmation)

| Path | Why unchanged |
|------|----------------|
| **Single active org (count ≤ 1)** | Gate returns before org-id check when `countActiveOrganizations() <= 1`. Resolver still sets `MODE_SINGLE_ACTIVE_ORG_FALLBACK` when count is 1 and branch is null (F-09). |
| **Guest / public** | No `AuthMiddleware` success → `enforceForAuthenticatedStaff()` never called. |
| **Branch-derived resolution** | With valid branch, resolver sets non-null org id before auth; gate sees `getCurrentOrganizationId() > 0` and returns. |
| **Resolver / org middleware** | `resolveForHttpRequest` logic unchanged aside from delegating count to shared private query; `OrganizationContextMiddleware` unchanged. |

---

## 8) Code-level exemptions and escape paths (actual)

| Mechanism | Detail |
|-----------|--------|
| **Gate class** | `POST /logout`; `GET`/`POST /account/password`. |
| **AuthMiddleware password-expiry branch** | Expired password + same two path/method combos → `$next()` **without** org gate (session lifecycle alignment with password change). |
| **No `isStaff()` predicate** | Method name `enforceForAuthenticatedStaff()`; implementation does **not** assert staff group — boundary is **any** session that passes `AuthMiddleware`. In this product, that is effectively the staff app user; **governance text** says “staff” while **code** says “authenticated on Auth routes”. |
| **0 active orgs** | `count <= 1` → gate **does not** block; F-25 ops §9 documents this; F-23 “degenerate DB” bucket expected **operational** treatment, not an extra gate branch in R1. |

---

## 9) FOUNDATION-23 / FOUNDATION-24 intent vs FOUNDATION-25 code reality

| Topic | Intent (F-23 / F-24) | Code reality (F-25) | Assessment |
|-------|----------------------|---------------------|------------|
| Choke placement | Post-`AuthMiddleware`, before controller | P1: hook at end of `AuthMiddleware` before `$next()` | **Aligned** |
| Resolver rules | No staff logic in resolver | Only public `countActiveOrganizations()`; `resolveForHttpRequest` unchanged | **Aligned** |
| Predicate | Multi-org + unresolved org for authenticated staff | `count > 1` + (`orgId` null or ≤ 0); no explicit `MODE_*` check | **Aligned in effect** for normal DB states; **slightly broader** than “null only” if id were ever non-positive |
| Response shape | 403 / 409 / 302 per product | Fixed **403**; JSON + text/plain | **Product choice** documented in F-25; F-24 listed options |
| Downstream | Auth-protected routes | All routes using `AuthMiddleware` on success path | **Aligned** |
| Exception map E1–E6 | Waivers product-defined | **Only** two path exemptions in code (+ Auth expiry short-circuit) | **Minimal R1** — HQ cross-org tooling / report waivers **not** implemented as extra allowlist entries |

---

## 10) Explicit answers (audit questions A–F)

| ID | Answer |
|----|--------|
| **A** | Block when: on gate entry path **and** not exempt **and** `countActiveOrganizations() > 1` **and** (`getCurrentOrganizationId()` is `null` or `<= 0`). |
| **B** | No block when: exempt path; or `count <= 1`; or resolved `orgId > 0`; or auth fails / gate not reached; or password-expired exempt `$next()` without gate. |
| **C** | Gate runs only after successful `AuthMiddleware` checks (for routes that include `AuthMiddleware`). It is **not** limited by an HTTP method check beyond exemptions; **not** “staff-only” in code beyond route registration conventions. |
| **D** | **403**; JSON shape with `ORGANIZATION_CONTEXT_REQUIRED` or **text/plain** same message; `exit`. |
| **E** | Yes: path exemptions; password-expired early `$next()` on same paths; `count <= 1` includes **zero** orgs (no block); no RBAC/staff flag bypass. |
| **F** | **Drift:** governance “staff” vs code “authenticated Auth routes”; F-23 degenerate zero-org “treat as error” vs R1 **no** gate block at `count === 0`; F-23 wording “null org” vs code “null **or** ≤ 0”. |

---

## 11) Items intentionally not advanced this wave

- No manual runtime smoke execution; no new verifier script; no FOUNDATION-27 / next code wave opened; no resolver, middleware, or route edits.

---

## 12) Checkpoint readiness

FOUNDATION-25 **implementation** is **traceable** in-repo to a **single post-auth choke**, **documented** predicate, **403** responses, and **explicit** exemptions. **Governance** and **ZIP** acceptance remain with program; **runtime** proof still relies on **manual** smoke per F-25 §8, not this audit alone.
