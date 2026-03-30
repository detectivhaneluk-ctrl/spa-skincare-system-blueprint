# Staff multi-org gate — manual smoke matrix (FOUNDATION-28)

**Companion:** `STAFF-MULTI-ORG-GATE-QA-WAIVER-AND-LAYER-CLOSURE-FOUNDATION-28-OPS.md`  
**Baseline code:** FOUNDATION-25 · **Truth:** FOUNDATION-26 · **Containment:** FOUNDATION-27

**How to use:** Execute in a **controlled environment** (staging or disposable DB). Record **Pass / Fail / N/A** and notes. **Fail** = open a defect or ops ticket; do not reinterpret gate code in this sheet.

**Legend**

- **Multi-org fixture:** ≥2 rows in `organizations` with `deleted_at IS NULL`.  
- **Single-org fixture:** exactly 1 active organization.  
- **Zero-org fixture:** 0 active organizations (degenerate — optional row).  
- **HQ / no branch:** authenticated user with no effective branch in session/request (and `users.branch_id` null or not selecting a branch), per product test data.

---

## A) Core pack (required minimum)

| ID | Fixture / actor | Steps | Expected | Result | Notes |
|----|-----------------|-------|----------|--------|-------|
| **S1** | Single-org; staff; null branch | `GET /dashboard` (or `GET /`) as logged-in user | **200**; app loads | | Confirms F-09 single-org fallback + gate no-op |
| **S2** | Multi-org; HQ / no branch | `GET /dashboard` logged in | **403**; body plain **or** JSON per browser/API `Accept` | | Message contains org-context requirement (F-25 text) |
| **S3** | Multi-org; valid branch | Ensure branch context (session / `users.branch_id` / request per product) | `GET /dashboard` → **200** | | Branch-derived org |
| **S4** | Guest | `GET /login` | **200** (login form); **not** 403 org gate | | Gate not on guest path |
| **S5** | Multi-org; stranded user (inactive assigned branch) | 1) `GET /dashboard` 2) `POST /logout` (valid CSRF) | (1) **403** org gate (2) logout **succeeds** | | Exempt path on (2) |
| **S6** | Multi-org; resolved org | `GET /` (root uses `AuthMiddleware` only) | **200** | | Auth-only stack before permission noise |

---

## B) Extended pack (recommended before production sign-off)

| ID | Fixture / actor | Steps | Expected | Result | Notes |
|----|-----------------|-------|----------|--------|-------|
| **S7** | Multi-org; unresolved | `GET /dashboard` with header **`Accept: application/json`** | **403**; `Content-Type: application/json`; `error.code` = **`ORGANIZATION_CONTEXT_REQUIRED`** | | API-shaped clients |
| **S8** | Multi-org; unresolved | `GET /sales/invoices` (or any `AuthMiddleware` + permission route) | **403** org gate (**before** permission denial) | | Spot-check permission order |
| **S9** | Multi-org; unresolved | `GET /account/password` logged in | **200** (password form) | | R1 exemption |
| **S10** | Multi-org; unresolved | `POST /logout` (valid CSRF if required) | Logout **succeeds** (redirect or 200 per app) | | R1 exemption |
| **S11** | Single-org | `GET /clients` or similar permissioned index | **200** (or 403 **permission** if user lacks perm — not org gate) | | Confirms gate no-op when count ≤ 1 |

---

## C) Optional / degenerate (document outcome; not a gate pass criterion)

| ID | Fixture / actor | Steps | Expected | Result | Notes |
|----|-----------------|-------|----------|--------|-------|
| **O1** | **Zero** active orgs | Staff `GET /dashboard` | **Not** blocked by multi-org gate; behavior = **legacy** / app-defined | | Waiver **W1** — ops fix DB or future product wave |
| **O2** | Password expired + policy on; multi-org unresolved | User with expired password hits **`GET /account/password`** | Can reach password change **without** org gate (F-26) | | Aligns with Auth short-circuit |

---

## D) Sign-off block

| Role | Name | Date | S1–S6 all Pass? | S7–S11 reviewed? |
|------|------|------|-----------------|------------------|
| Operator / QA | | | ☐ | ☐ |
| Product / program (if required) | | | ☐ | ☐ |
