# Post-gate staff HTTP unresolved-org surface matrix (FOUNDATION-27)

**Companion:** `POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-RECHECK-FOUNDATION-27-OPS.md`  
**Prerequisite truth:** FOUNDATION-26 (gate trigger: `countActiveOrganizations() > 1` ∧ org id null/≤0 ∧ `AuthMiddleware` success ∧ not exempt).

**Legend**

| Class | Meaning |
|-------|---------|
| **Contained (multi-org staff HTTP)** | For `count > 1` + unresolved org + non-exempt `AuthMiddleware` route, request **does not** reach controller → F-21 legacy path **not entered** via that HTTP combination. |
| **Still reachable (staff HTTP)** | Request can still hit controller / services / repos with unresolved org under stated conditions. |
| **Non–staff-HTTP / other** | CLI, public/guest routes, or not the authenticated staff pipeline. |

---

## A) FOUNDATION-09 — resolver / global org middleware

| Surface | Staff HTTP post-F-25 |
|---------|----------------------|
| `OrganizationContextResolver` / `OrganizationContextMiddleware` (sets unresolved modes) | **Still runs** globally before auth; **not** “blocked” — gate **consumes** outcome. |
| `DomainException` invalid branch–org link | **Fail-closed** (not “unresolved id”) — **unchanged**; **outside** gate predicate. |

---

## B) FOUNDATION-10 (baseline) + F-11 — assert + choke services

| F-21 matrix row | Contained (multi-org + unresolved + Auth, non-exempt) | Still reachable (staff HTTP) | Notes |
|-----------------|------------------------------------------------------|------------------------------|--------|
| `OrganizationScopedBranchAssert` no-op when org null | Yes — controller not reached | **Yes** if `count ≤ 1` + unresolved | Assert code unchanged |
| `BranchDirectory` update/delete/createBranch | Yes | **Yes** if `count ≤ 1` + unresolved (createBranch MIN-org behavior) | |
| `InvoiceService` / `PaymentService` / `ClientService` / marketing / payroll assert sites | Yes | **Yes** if `count ≤ 1` + unresolved | |

---

## C) F-13 — marketing repositories

| F-21 matrix row | Contained | Still reachable (staff HTTP) |
|-----------------|-----------|------------------------------|
| Campaign / run / recipient methods with legacy branches when org null | Multi-org + unresolved + non-exempt Auth | **`count ≤ 1`** + unresolved; exempt Auth paths |

---

## D) F-14 — payroll repositories

| F-21 matrix row | Contained | Still reachable (staff HTTP) |
|-----------------|-----------|------------------------------|
| `listRecent(null)` global SQL when unresolved | Multi-org + unresolved + non-exempt Auth | **`count ≤ 1`** + unresolved |
| Other explicit / empty-fragment legacy paths | Same | Same |

---

## E) F-16 / F-18 — `ClientRepository`

| Method | Contained | Still reachable (staff HTTP) |
|--------|-----------|------------------------------|
| `find` / `findForUpdate` / `list` / `count` legacy when unresolved | Multi-org + unresolved + non-exempt Auth | **`count ≤ 1`** + unresolved |

---

## F) F-19 / F-20 — `ClientListProvider` consumers

| Surface | Contained | Still reachable (staff HTTP) |
|---------|-----------|------------------------------|
| Inherits `ClientRepository::list` | Multi-org + unresolved + non-exempt Auth | **`count ≤ 1`** + unresolved |

---

## G) Authenticated exemptions (multi-org + unresolved still runs controller)

| Route (representative registrar) | Middleware | Post-gate behavior |
|----------------------------------|------------|--------------------|
| `POST /logout` | `AuthMiddleware` | Gate **exempt** — controller runs |
| `GET` / `POST /account/password` | `AuthMiddleware` | Gate **exempt** — controller runs |

---

## H) Explicitly non–staff-authenticated HTTP (out of line for “staff HTTP unresolved”)

| Pattern | Example | Gate |
|---------|---------|------|
| `GuestMiddleware` | `/login` | Not invoked |
| `[]` | `/api/public/booking/*`, `/public/intake/*` | Not invoked |

---

## I) CLI / non-HTTP

| Context | F-25 gate |
|---------|-----------|
| No `AuthMiddleware` / HTTP dispatch | **Does not apply** — F-21 dual-path remains relevant for CLI callers |
