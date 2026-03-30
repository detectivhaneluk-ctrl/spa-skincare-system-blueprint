# Organization-scoped client read surfaces — truth audit (FOUNDATION-15)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-15 — ORGANIZATION-SCOPED-CLIENT-READ-SURFACES-TRUTH-AUDIT  
**Type:** Read-only proof only (no repository/service/controller/runtime changes).  
**Parent truth:** FOUNDATION-06 through FOUNDATION-14 (F-11 choke points, F-12 repo pattern audit, F-13/F-14 repository org scoping families).  
**Companion:** `CLIENT-READ-SURFACE-COVERAGE-MATRIX-FOUNDATION-15.md` (tabular index).

---

## 1) Executive summary

Client **mutations** on agreed paths are guarded by **FOUNDATION-11** (`OrganizationScopedBranchAssert` inside `ClientService` after `ClientRepository::find` / `findForUpdate`). Client **reads** still rely heavily on **ID-only** repository loads and **unscoped or optionally branch-filtered** list/search/duplicate queries. **`OrganizationContext` is not applied at the repository layer** for clients. Several read surfaces (index search, duplicate assist, merge preview, invoice client picker, public resolution) either **never** hit F-11 or only use **branch** checks, not **organization** predicates.

This audit answers Q1–Q10 with **file/class/method** evidence. It defines a **minimal** first implementation perimeter (not executed here) and explicit **non-goals**.

---

## 2) Q1 — Client read surfaces that exist today (inventory)

| Surface | Location | Evidence |
|--------|----------|----------|
| **Index / list** | `Modules\Clients\Controllers\ClientController::index` | `ClientRepository::list($filters, …)` / `count($filters)` with `$filters = ['search' => …]` only — **no** `branch_id` in filters (`ClientController.php` ~L43–L54). |
| **Search / autocomplete-style list** | Same + `registrationsShow` | Index: search string. Registrations: `ClientRepository::list(['search' => $search], 20, 0)` when `client_search` non-empty (`ClientController.php` ~L408–L409). |
| **Show / detail** | `ClientController::show` | `ClientRepository::find($id)` then `ensureBranchAccess($client)` (`ClientController.php` ~L102–L111). |
| **Edit / load form** | `ClientController::edit` | `ClientRepository::find($id)` + `ensureBranchAccess` (`ClientController.php` ~L184–L192). |
| **Duplicate search / merge assist (index)** | `ClientController::index` | `ClientService::searchDuplicates(…, null, true, $dupPartial, 50)` (`ClientController.php` ~L59–L65). |
| **Duplicate panel on profile** | `ClientController::show` | `ClientService::findDuplicates($id, ['email' => …, 'phone' => …])` (`ClientController.php` ~L113). |
| **Merge preview (read)** | `ClientController::mergePreview` | `ClientService::getMergePreview($primaryId, $secondaryId)` (`ClientController.php` ~L245–L256). |
| **Merge action (mutate)** | `ClientController::mergeAction` | `ClientService::mergeClients` — F-11 guarded; out of scope for “read” but couples merge preview. |
| **Notes read** | `ClientController::show` | `ClientRepository::listNotes($id, 20)` (`ClientController.php` ~L126). |
| **Audit/history read** | `ClientController::show` | `ClientRepository::listAuditHistory($id, 20)` (`ClientController.php` ~L127). |
| **Custom field definitions (read)** | `ClientController::create`, `edit`, `show`, `validate`, `customFieldsIndex` | `ClientService::getCustomFieldDefinitions(null, …)` → `ClientFieldDefinitionRepository::list(null, …)` (`ClientController.php` ~L78, L115, L197, L282, L542; `ClientService.php` ~L246–L248). |
| **Custom field values (read)** | `ClientController::show`, `edit`, `update` validation path | `ClientService::getClientCustomFieldValuesMap($id)` → `ClientFieldValueRepository::listByClientId` (`ClientController.php` ~L114, L198; `ClientService.php` ~L251–L258). |
| **Issue flags (read)** | `ClientController::show` | `ClientIssueFlagRepository::listByClient($id, 'open', 50)` (`ClientController.php` ~L116). |
| **Related sales / appointments / packages / gift cards (read)** | `ClientController::show` | `ClientAppointmentProfileProvider`, `ClientSalesProfileProvider`, `ClientPackageProfileProvider`, `ClientGiftCardProfileProvider` (`ClientController.php` ~L117–L125). Sales impl: `ClientSalesProfileProviderImpl` queries **`invoices` / `payments`** by `client_id` only (see §6). |
| **Registration requests list/show** | `ClientController::registrationsIndex`, `registrationsShow` | `ClientRegistrationRequestRepository::list` / `find` — separate table; show uses `ClientRepository::list` for client search (`ClientController.php` ~L340–L415). |
| **Intake staff: client touch** | `IntakeFormService::assignTemplate` | `ClientRepository::find($clientId)` then `BranchContext::assertBranchMatch` on client branch (`IntakeFormService.php` ~L166–L170). |

---

## 3) Q2 — F-11 choke-point coverage vs read-only exposure

**FOUNDATION-11** (`verify_organization_scoped_choke_points_foundation_11_readonly.php`) proves asserts on `ClientService`: **`create`**, **`update`**, **`delete`**, **`addClientNote`**, **`deleteClientNote`**, **`mergeClients`**, **`createCustomFieldDefinition`**, **`updateCustomFieldDefinition`**.

| Category | Protected by F-11? | Notes |
|----------|--------------------|-------|
| Client **create / update / delete** | **Yes** (service transactional paths) | Still begin with **`ClientRepository::find` / `findForUpdate`** without org SQL — assert uses **loaded row’s `branch_id`**. |
| Notes **add / delete** | **Yes** | Same pattern: `find` then branch + org assert. |
| Merge **execute** | **Yes** | `mergeClients` asserts both primaries’ branches vs org. |
| Custom field definition **create / update** | **Yes** | Uses `fieldDefinitions->find` + assert on definition’s `branch_id`. |
| **Show / edit** load | **No** (read-only) | Controller: **`find` then `ensureBranchAccess`** — **`BranchContext::assertBranchMatch` only**, not `OrganizationScopedBranchAssert` (`ClientController::ensureBranchAccess` ~L562–L571). |
| **Index list/count + duplicate search** | **No** | No `ClientService` call with F-11; repo **`list` / `count` / `searchDuplicates`** unscoped by org. |
| **`findDuplicates` / profile duplicate panel** | **No** | `ClientService::findDuplicates` → repo — no assert. |
| **`getMergePreview`** | **No** | `ClientService::getMergePreview` uses **`repo->find`** for both IDs, **`countLinkedRecords`** — **no** `BranchContext` or `OrganizationScopedBranchAssert` (`ClientService.php` ~L171–L191). |
| **Custom field definition list** | **No** | `getCustomFieldDefinitions(null, …)` lists **all** non-deleted definitions with **no** org filter (`ClientFieldDefinitionRepository::list`). |
| **Custom field values map** | **No** (in service) | `getClientCustomFieldValuesMap` does not load client or assert; callers that skip `find`+`ensureBranchAccess` would be unsafe (current staff show/edit do assert). |
| **Notes/history/issue flags lists** | **No** at repo layer | `listNotes` / `listAuditHistory` / `listByClient` keyed by `client_id` only. |
| **Sales profile aggregates** | **No** | Direct SQL by `client_id` (`ClientSalesProfileProviderImpl`). |

---

## 4) Q3 — ID-only / optional branch / search / null-global semantics (evidence)

### `ClientRepository` (`Modules\Clients\Repositories\ClientRepository`)

| Method | Pattern |
|--------|---------|
| `find` / `findForUpdate` | **ID-only** (`WHERE id = ?`) — L15–L27. |
| `list` / `count` | **Optional** `branch_id` only if present in `$filters`; **search** applies `LIKE` across name/email/phone **without** org predicate — L121–L157. |
| `findDuplicates` | **ID exclude + email/phone OR** — **no** branch — L187–L205. |
| `searchDuplicates` | **No** branch/org — L212–L263. |
| `listNotes` / `findNote` / `listAuditHistory` | **client_id** (or note id) only — L335–L392. |
| `lockActiveByEmailBranch` / `lockActiveByPhoneDigitsBranch` / `findActiveClientIdByPhoneDigitsExcluding` | **Branch-scoped** (`branch_id <=> ?`) — public resolution — L36–L111. |

### `ClientFieldDefinitionRepository`

| Method | Pattern |
|--------|---------|
| `list(?int $branchId, …)` | If `$branchId === null`: **all** rows (all branches/orgs). If set: **`branch_id = ? OR branch_id IS NULL`** — **null = global** within DB, not org-scoped — L15–L28. |
| `find` | **ID-only** — L30–L33. |

### `ClientFieldValueRepository`

| Method | Pattern |
|--------|---------|
| `listByClientId` | Join definitions by **`client_id`** only — L18–L28. |

### `ClientListProvider` / `ClientListProviderImpl`

| Method | Pattern |
|--------|---------|
| `list(?int $branchId)` | `$branchId !== null` → `['branch_id' => $branchId]`; else **empty filters** → **up to 500 clients** with no branch filter — L16–L20. |

**Consumers of `ClientListProvider` (cross-domain read):** e.g. `InvoiceController` uses `$this->clientList->list($branchId)` — L80, L194, L352, L390, L451 (`InvoiceController.php`); plus **`ClientRepository::find($clientId)`** for display — L390. **Invoice domain is explicitly out of scope for this audit wave’s implementation**, but this is a **high-coupling read surface** for any future client repo scope.

---

## 5) Q4 — Safest first repo-scoping target (staff-isolated)

**Relative safest (minimal blast radius):** org-aware enforcement on **`ClientRepository::find` and `findForUpdate`** when **`OrganizationContext::resolvedOrganizationId()`** is non-null, using the same **branch → organization** EXISTS pattern as F-13/F-14 (`OrganizationRepositoryScope`), **without** changing `PublicClientResolutionService` entrypoints in the same wave.

**Rationale:** Staff detail/edit/update/delete/merge **already** load via `find` / `findForUpdate`; adding an org predicate is **defense-in-depth** when org context exists, and does not by itself widen list/search behavior. **Do not** bundle invoice pickers or merge preview in the first repo wave without separate acceptance (see §8).

---

## 6) Q5 — Defer: higher-risk or coupled surfaces

Defer or sequence **after** narrow `find`/`findForUpdate` proof:

- **`searchDuplicates` / `findDuplicates`** — PII leakage across branches/orgs if only org context is added to `find` but search remains global.
- **`ClientRepository::list` / `count`** without mandatory branch + org predicate — index and registration client search.
- **`getMergePreview` + `countLinkedRecords` + `remapClientReferences`** — touches **`invoices`**, **`payments`**, appointments, packages, gift cards, notes, issue flags, registrations (`ClientRepository` L269–L323).
- **`ClientSalesProfileProviderImpl`** (and peers) — **`client_id`-only** SQL on money tables; org scoping belongs to a **coordinated** sales/read wave, not a blind client-repo change.
- **`PublicClientResolutionService`** — anonymous public booking/commerce; uses **`lockActiveByEmailBranch`** etc.; **must not** be “fixed” by the same staff-repo predicate without a dedicated public-flow design (F-07 scope).
- **`ClientListProviderImpl::list(null)`** — unscoped 500 rows; used from **sales** controllers.
- **`ClientFieldDefinitionRepository::list(null)`** — cross-org definition leakage if multi-org DB is populated; resolving **NULL `branch_id` “global” definitions** under resolved org requires an explicit product rule (fail-closed vs org-shared globals).

---

## 7) Q6 — Exact boundary: first client implementation wave vs stay out

### In scope for a **minimal** next implementation wave (suggested F-16; **not opened here**)

- **`ClientRepository::find`**, **`ClientRepository::findForUpdate`** — append org EXISTS via `clients.branch_id` → `branches.organization_id` when resolved org non-null (mirror payroll/marketing discipline).
- **Smallest DI wiring:** `OrganizationRepositoryScope` (or new clause helper) + `register_clients.php` constructor injection — only if required by the chosen pattern.
- **Read-only verifier script** listing covered methods (pattern: F-13/F-14).

### Explicitly **out** of the first client repo wave (unless separately tasked and acceptance-written)

- **`list` / `count` / `searchDuplicates` / `findDuplicates`**
- **`listNotes` / `listAuditHistory` / `findNote` / `softDeleteNote`**
- **`countLinkedRecords` / `remapClientReferences` / `markMerged`**
- **`ClientFieldDefinitionRepository` / `ClientFieldValueRepository`**
- **`ClientListProviderImpl`** and **invoice/membership** client pickers
- **`PublicClientResolutionService`** and lock-by-email/phone methods
- **Any UI**, **settings**, **RBAC**, **appointments** module edits

---

## 8) Q7 — Null / global semantics (clients + custom fields)

| Area | Current behavior | Under resolved org (policy note) |
|------|------------------|----------------------------------|
| **`clients.branch_id`** | Created via `BranchContext::enforceBranchOnCreate` in `ClientService::create` | F-11 assert on non-null branch; **null** branch on legacy rows possible — org EXISTS must define **NULL = exclude** when fail-closed (align with payroll compensation NULL rule unless proven otherwise). |
| **`client_field_definitions.branch_id` NULL** | Treated as **global**: included in every `list($branchId, …)` when branch filter set (`OR branch_id IS NULL`) | Under multi-org, “global” may span organizations unless constrained — **cannot** remain implicitly cross-org without a written rule. |
| **`ClientListProvider::list(null)`** | All clients (cap 500) | Incompatible with org isolation until org-aware list exists. |

---

## 9) Q8 — Acceptance gates for the next (implementation) wave

1. **No** changes to **public** booking/commerce/intake resolution in the same wave as staff `find` scope unless explicitly specified and tested.  
2. **`find` / `findForUpdate`** return **`null`** (or equivalent “not found”) for rows whose `branch_id` maps to a branch **outside** resolved organization when org context is resolved.  
3. **No org derived from raw request body** — only **`OrganizationContext`** / resolver chain (F-09).  
4. **Read-only verifier** passes; ops doc updated.  
5. **F-11 verifier** still passes (no removal of `OrganizationScopedBranchAssert` usage).  
6. **Regression checklist:** merge preview, index search, invoice client dropdown — documented as **known gaps** until follow-on waves.  

---

## 10) Q9 — Highest regression risks if scoping is too broad too early

- **Staff index / duplicate search** suddenly empty or inconsistent vs historical “global” search — UX and support burden.  
- **Invoice / membership** flows: `ClientListProvider` + `find` mismatch (dropdown shows clients that `find` then hides or vice versa).  
- **Merge preview / merge**: partial scoping on `find` only without `countLinkedRecords` / remap awareness — **false confidence** or broken merge.  
- **Public flows**: accidental sharing of org predicate with `lockActiveByEmailBranch` — **booking/commerce breakage** or **information disclosure**.  
- **Custom fields**: hiding definitions while values still reference IDs — **validation/save** inconsistency.  

---

## 11) Q10 — Phased order after the first client read-surface wave succeeds

1. **Wave A (minimal):** `ClientRepository::find` / `findForUpdate` + verifier + ops (suggested F-16).  
2. **Wave B:** `list` / `count` with mandatory **branch + org** predicate when org resolved; align **index** and **registration** search filters.  
3. **Wave C:** `findDuplicates` / `searchDuplicates` — require same branch and/or org bounds; coordinate with UX.  
4. **Wave D:** `ClientFieldDefinitionRepository` / value reads — define **NULL global** semantics per org.  
5. **Wave E:** `getMergePreview`, `countLinkedRecords`, remap paths — **with** invoice/payment/appointment integrity review.  
6. **Wave F:** `ClientListProvider` + **InvoiceController** / **membership** client pickers — align with sales org policy.  
7. **Wave G:** Profile providers (`ClientSalesProfileProviderImpl`, etc.) — org predicates on aggregated reads.  
8. **Public:** `PublicClientResolutionService` — separate threat model; not mixed with staff repo waves.  

---

## 12) Non-goals (this wave)

- No code changes, schema, middleware, or ZIP content beyond documentation + roadmap/checklist.  
- No **FOUNDATION-16** implementation.  
- No invoice/payment/appointment/package/membership/public/UI changes.  

---

## 13) Artifact index

| Artifact | Role |
|----------|------|
| This file | Primary Q1–Q10 answers + perimeter + gates |
| `CLIENT-READ-SURFACE-COVERAGE-MATRIX-FOUNDATION-15.md` | Method × exposure matrix |
| `ORGANIZATION-BOUNDARY-ENFORCEMENT-CHECKLIST-FOUNDATION-07.md` | Gate **G-ORG-CLIENT-READ-AUDIT** |
| `BOOKER-PARITY-MASTER-ROADMAP.md` | FOUNDATION-15 row |
