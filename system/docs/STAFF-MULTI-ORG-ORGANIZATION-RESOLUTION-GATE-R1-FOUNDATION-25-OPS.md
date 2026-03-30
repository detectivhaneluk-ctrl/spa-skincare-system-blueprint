# Staff multi-org organization resolution gate — minimal R1 (FOUNDATION-25)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-25 — STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-MINIMAL-IMPLEMENTATION-R1  
**Policy source:** FOUNDATION-22 / **23** / **24**; **evidence** FOUNDATION-21 (unresolved org → legacy repo paths).

---

## 1) Exact old behavior

- After `AuthMiddleware` succeeded, **any** staff request could reach controllers with **`OrganizationContext::getCurrentOrganizationId()`** **null** when **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** or **`MODE_UNRESOLVED_NO_ACTIVE_ORG`** (F-09 resolver unchanged).
- **Single-org:** null branch still received **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** with a **resolved** org id — **not** null (unchanged by this wave).

---

## 2) Exact new gate behavior

- **Placement:** End of **`AuthMiddleware::handle`**, **after** session/auth/password checks, **before** `$next()` (FOUNDATION-24 pattern **P1**).
- **Predicate:** If **`OrganizationContextResolver::countActiveOrganizations() > 1`** **and** **`OrganizationContext::getCurrentOrganizationId()`** is **null** or **≤ 0** → **fail closed** (no controller, no permission middleware).
- **Single-org (`count ≤ 1`):** gate **returns immediately** — **no** new block solely for null branch; **`single_active_org_fallback`** behavior **preserved** (org already resolved when count is 1).
- **No guessing:** resolver rules **unchanged**; gate does **not** set org.

---

## 3) Exact response shape / status

- **HTTP 403** (not 409) for blocked requests.
- **JSON** (`Accept` contains `application/json`):  
  `{ "success": false, "error": { "code": "ORGANIZATION_CONTEXT_REQUIRED", "message": "<stable text>" } }`
- **Non-JSON:** **`text/plain`** body, same human-readable message (no redirect in this wave).

**Stable message:**  
`Organization context is required before continuing. Select a branch or contact an administrator.`

---

## 4) Tiny exceptions (session lifecycle only — no broad allowlist)

| Path | Reason |
|------|--------|
| `POST /logout` | Staff must be able to end session when otherwise blocked. |
| `GET` / `POST /account/password` | Aligns with existing password-expiry exempt routes; allows password change without org pivot. |

**No** other allowlist entries in R1.

---

## 5) Exact unchanged behaviors

- **Guest/public:** routes **without** successful `AuthMiddleware` completion **never** hit the gate.
- **`OrganizationContextResolver::resolveForHttpRequest`:** **same** branching; only **internal** rename + **public** `countActiveOrganizations()` delegating to the **same** SQL as before.
- **`OrganizationContextMiddleware`**, **`BranchContextMiddleware`:** **untouched**.
- **Repositories, controllers (business logic), views, schema:** **untouched**.

---

## 6) Acceptance mapping (F-23 / F-24 gates)

| Gate | How R1 satisfies |
|------|------------------|
| **G-F24-SINGLE-ORG** | `countActiveOrganizations() <= 1` → gate **no-op**. |
| **G-F24-MULTI-ORG-HQ** | `count > 1` + null org → **403**. |
| **G-F24-BRANCH-DERIVED** | Resolved org id → gate **no-op**. |
| **G-F24-GUEST-PUBLIC** | No auth success → gate **not** invoked. |
| **G-F24-STRANDED-USER** | Inactive assigned branch → null context + multi-org → **403** except logout/account password — **documented**; recovery = data/admin. |
| **G-F24-REGRESSION-SMOKE** | **Manual** (see §8). |
| **G-F24-EXCEPTION-LIST** | **Tiny** fixed paths only (§4); **no** broad inventory. |

---

## 7) Non-targeted areas (intentionally untouched)

All domain repositories; `OrganizationScopedBranchAssert` call sites; marketing/payroll/client SQL; UI; resolver **resolution rules**; pipeline **rewrite** (no `Dispatcher` change).

---

## 8) Manual smoke minimum (operator)

1. **Single active org**, staff, null branch: open **`GET /dashboard`** → **200** (org fallback).  
2. **≥2 active orgs**, HQ user, no branch in session/request: **`GET /dashboard`** → **403** plain text or JSON per `Accept`.  
3. **≥2 orgs**, valid branch (session or `users.branch_id`): **`GET /dashboard`** → **200**.  
4. **`GET /login`** (guest) → **unchanged** (200 show form).  
5. **Stranded** (inactive `users.branch_id`), multi-org: authenticated route → **403**; **`POST /logout`** → **must** succeed.  
6. **Representative auth route** before permission noise: e.g. **`GET /`** or **`GET /dashboard`** with `AuthMiddleware` only.

---

## 9) Risks / waivers

- **Zero active organizations:** `count > 1` is **false** — gate **does not** block; remains **degenerate** ops issue (unchanged from strict F-25 predicate).  
- **Product** may later add **org pivot** or **branch picker** URL — **out of scope** for R1.

---

## 10) Proof commands (optional)

```bash
cd system
# If PHP available:
php -l core/Organization/StaffMultiOrgOrganizationResolutionGate.php
php -l core/Organization/OrganizationContextResolver.php
php -l core/middleware/AuthMiddleware.php
```

---

## 11) ZIP checkpoint

Build via `handoff/build-final-zip.ps1` → `distribution/spa-skincare-system-blueprint-FOUNDATION-25-STAFF-ORG-GATE-R1-CHECKPOINT.zip` (or project naming convention).

**Do not** open FOUNDATION-26 from this wave.
