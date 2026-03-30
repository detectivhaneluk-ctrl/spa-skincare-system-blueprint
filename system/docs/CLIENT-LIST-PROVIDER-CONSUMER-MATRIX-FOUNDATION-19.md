# Client list provider — consumer matrix (FOUNDATION-19)

**Companion:** `ORGANIZATION-SCOPED-CLIENT-LIST-PROVIDER-CONSUMER-TRUTH-AUDIT-FOUNDATION-19-OPS.md`  
**Provider call:** `ClientListProvider::list(?int $branchId = null)` → `ClientListProviderImpl::list` → `ClientRepository::list($filters, 500, 0)`.

**Legend — reaches `ClientRepository::list`:** All rows **yes**, via provider only.  
**Org predicate (F-18):** Applied in **`ClientRepository::list`** when `OrganizationRepositoryScope::resolvedOrganizationId()` is non-null; **absent** when null (legacy).

---

## Matrix

| Consumer | Method(s) | Provider call | Representative route(s) | Middleware (proof) | Staff-only? | Branch/org context before call | Unresolved org behavior | Dropdown / UX under org scope | Safe for F-20 minimal provider enforcement? | Recommended status |
|----------|-----------|---------------|-------------------------|--------------------|-------------|--------------------------------|-------------------------|------------------------------|---------------------------------------------|-------------------|
| **InvoiceController** | `create` | `$this->clientList->list($branchId)` | `GET /sales/invoices/create` | `AuthMiddleware`, `PermissionMiddleware::for('sales.create')` | **Yes** (staff + permission) | `$branchId` from prefill / `$_GET['branch_id']` / **`BranchContext::getCurrentBranchId()`** (context wins) | Legacy unscoped list (no EXISTS) | Options shrink to in-org branches; **NULL `branch_id` clients omitted** when org resolves | **No** — redundant with F-18 repo; **QA** for dropdown + membership checkout `find` pairing | **NEEDS CONTAINMENT** |
| **InvoiceController** | `edit`, `renderEditForm` | `$this->clientList->list($invoice['branch_id'] ?? null)` | `GET /sales/invoices/{id}/edit`; error re-render from `update` | `AuthMiddleware`, `sales.edit` / `sales.view` | **Yes** | Invoice row branch may be **null** | Same as above | Editing draft with **null** invoice branch → **no** `branch_id` filter + org EXISTS → in-org clients only, NULL-branch clients excluded | **No** | **NEEDS CONTAINMENT** |
| **InvoiceController** | `renderCreateForm` | `$this->clientList->list($branchId)` | `POST /sales/invoices` validation failure | `AuthMiddleware`, `sales.create` | **Yes** | `$data['branch_id']` may be null | Same | Same | **No** | **NEEDS CONTAINMENT** |
| **AppointmentController** | `create` | `$this->clientList->list($branchId)` | `GET /appointments/create` | `AuthMiddleware`, `appointments.create` | **Yes** | `$branchId = queryBranchId()` = **`BranchContext::getCurrentBranchId()` only** (no GET override in helper) | If context null → **no** branch filter; with org resolved → **all in-org branches** up to 500 | **Material** vs per-branch picker: HQ/no branch context still org-scoped when org resolves | **No** | **NEEDS CONTAINMENT** |
| **AppointmentController** | `edit`, `renderEditForm` | `$this->clientList->list($branchId)` | `GET /appointments/{id}/edit` | `AuthMiddleware`, `appointments.edit` | **Yes** | Branch from appointment row | Unresolved org → legacy | NULL-branch clients excluded when org resolves | **No** | **NEEDS CONTAINMENT** |
| **AppointmentController** | `waitlistCreate` | `$this->clientList->list($branchId)` | `GET /appointments/waitlist/create` | `AuthMiddleware`, `appointments.create` | **Yes** | Same as `create` (context-only branch) | Same | Same | **No** | **NEEDS CONTAINMENT** |
| **AppointmentController** | `renderCreateForm` | `$this->clientList->list($branchId)` | `POST /appointments` / create-path validation failure | `AuthMiddleware`, `appointments.create` | **Yes** | Parsed payload branch | Same | Same | **No** | **NEEDS CONTAINMENT** |
| **GiftCardController** | `issue` | `$this->clients->list($branchId)` | `GET /gift-cards/issue` | `AuthMiddleware`, `gift_cards.create` | **Yes** | GET `branch_id` / context branch | Same | Same | **No** | **NEEDS CONTAINMENT** |
| **GiftCardController** | `storeIssue` (error paths) | `$this->clients->list($data['branch_id'] ?? null)` | `POST /gift-cards/issue` validation / catch | `AuthMiddleware`, `gift_cards.issue` | **Yes** | Posted branch may be empty | Same | Re-render can pass **null** branch → broader in-org list | **No** | **NEEDS CONTAINMENT** |
| **ClientPackageController** | `assign` | `$this->clients->list($branchId)` | `GET /packages/client-packages/assign` | `AuthMiddleware`, `packages.assign` | **Yes** | GET / context branch | Same | Same | **No** | **NEEDS CONTAINMENT** |
| **ClientPackageController** | `storeAssign` (error paths) | `$this->clients->list($data['branch_id'])` | `POST …/assign` validation / catch | `AuthMiddleware`, `packages.assign` | **Yes** | Uses submitted `branch_id` (may be invalid empty depending on parse) | Same | Package assign validation uses branch from POST | **No** | **NEEDS CONTAINMENT** |
| **ClientMembershipController** | `renderAssignForm` | `$this->clientListProvider->list($listBranchId)` | `GET /memberships/client-memberships/assign`; `storeAssign` errors | `AuthMiddleware`, `memberships.manage` | **Yes** | **`resolveAssignListScope`**: context branch **or** explicit assign branch param **or** **`ClientRepository::find`** for HQ client branch **or null** | **Null list branch** possible at HQ with no pin and no client → **no** branch filter + org resolved → in-org-wide list | HQ assign UX; **coupled** to `find` for scope | **No** | **NEEDS CONTAINMENT** |

---

## Call-site index (line proof)

| File | Lines (approx.) | Notes |
|------|-----------------|-------|
| `InvoiceController.php` | 80, 194, 352, 451 | `create`, `edit`, `renderCreateForm`, `renderEditForm` |
| `AppointmentController.php` | 79, 256, 421, 866, 883 | `create`, `edit`, `waitlistCreate`, `renderCreateForm`, `renderEditForm` |
| `GiftCardController.php` | 98, 110, 125 | `issue`, `storeIssue` ×2 |
| `ClientPackageController.php` | 67, 81, 96 | `assign`, `storeAssign` ×2 |
| `ClientMembershipController.php` | 159 | `renderAssignForm` only |

---

## Status semantics (F-19)

- **NEEDS CONTAINMENT:** Inherits F-18 SQL correctly, but **cross-module**, **nullable branch**, and/or **HQ** paths mean **product/QA containment** before treating dropdown behavior as “closed.”  
- **SAFE FOR FOUNDATION-20:** Not assigned — **no** consumer is provably low-risk for an **additional** minimal **provider** enforcement wave beyond F-18 (see primary ops doc).  
- **DEFER / WAIVER ONLY:** Use when product accepts **inherited repo behavior** without per-module smoke; still document **unresolved org** legacy exposure.

---

## Smallest next boundary (restatement)

**Repository:** `ClientRepository::list` (F-18) — already the effective security boundary for org SQL.  
**Provider:** Thin delegate — **no** smaller **safe** enforcement layer to add without product decisions.  
**FOUNDATION-20:** Prefer **waiver + QA matrix** (this document) over provider code changes.
