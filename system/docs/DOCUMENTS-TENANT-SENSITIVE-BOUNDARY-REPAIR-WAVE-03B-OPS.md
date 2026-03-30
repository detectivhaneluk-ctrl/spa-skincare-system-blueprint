# Documents tenant-sensitive boundary repair (WAVE-03B)

Code-truth for protected internal document flows after tenant hardening.

## Tenant scope contract (protected paths)

All protected document operations require:

1. **`TenantOwnedDataScopeGuard::requireResolvedTenantScope()`** — positive organization id, current branch id, and `OrganizationContext::MODE_BRANCH_DERIVED`. Otherwise throws `DomainException` with message **`Tenant data scope is unresolved.`** (maps to HTTP **403** via `HttpErrorHandler` when not caught locally).

2. **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()`** on `documents` / joined `documents` — used in repository SQL so rows must belong to an **active branch in the resolved organization**. If context is not tenant-protected, this throws **`OrganizationRepositoryScope::EXCEPTION_DATA_PLANE_*`** (403).

3. **Current branch pin** — document and link reads/updates additionally require `documents.branch_id` (and, where applicable, the same branch on owner resolution) to equal the resolved request branch.

There is **no** id-only metadata read, list, link lookup, detach, update, or soft-delete on protected paths.

## Operations now tenant-scoped (SQL + guards)

| Surface | Enforcement |
|--------|-------------|
| Metadata read (`showMetadata` / `loadDocumentForScopedRead`) | `findDocumentInTenant` |
| Authenticated download + audit link context | `findDocumentInTenant` + `findFirstActiveLinkForDocumentInTenant` |
| `listByOwner` | `resolveOwner` (org + branch) + `listByOwnerInTenant` |
| `relink` | `findDocumentInTenant` + `resolveOwner` + `findActiveLinkInTenant` |
| `detach` | `resolveOwner` + `findActiveLinkInTenant` + `detachLinkInTenant` |
| `archive` / `deleteSoft` | `loadDocumentForScopedRead` + `updateDocumentInTenant` / `softDeleteLinksByDocumentInTenant` / `softDeleteDocumentInTenant` |
| Owner resolution (`client`, `appointment`, `staff`) | `fetchBranchScopedTableOwner` — id + `branch_id =` current branch + org EXISTS |
| Owner resolution (`invoice`) | `TenantOwnedDataScopeGuard::requireInvoiceBranchForDocumentOwner` — org via invoice / client / appointment branch, then branch must match current branch |

## Raw id / global behaviors removed

- **`DocumentRepository::findDocument` / `findActiveLink` / `listByOwner` / `findFirstActiveLinkForDocument`** (unscoped `WHERE id = ?` or owner-only predicates) — replaced by **`*InTenant` methods** with org EXISTS + branch pin.
- **`BranchContext::assertBranchMatch`-only** protection after loading any row by raw id — removed from document service; scope is enforced **in the read/update SQL** (and via `requireResolvedTenantScope`).
- **Owner `resolveRow` (`SELECT * FROM … WHERE id = ?`)** without organization — replaced by tenant-scoped selects (and invoice-specific resolver).

## Intentionally unchanged

- **`DocumentRepository::updateLink`** — still id-targeted; **no current call sites** in the application tree (internal-only dead path left as-is this wave).
- **Public/anonymous document delivery** — not present; authenticated download behavior unchanged aside from scoped link lookup.
- **Consent / definition repositories** — out of scope for WAVE-03B.

## Call sites / DI

- **`system/modules/bootstrap/register_appointments_documents_notifications.php`** — `DocumentRepository` receives `OrganizationRepositoryScope`; `DocumentService` receives `TenantOwnedDataScopeGuard` and `OrganizationRepositoryScope` (no longer `BranchContext` for documents).

## HTTP note

`DocumentController` may still map some `DomainException`s to **422** when caught before the global handler; routes use **`TenantProtectedRouteMiddleware`**, so unresolved org is normally blocked at the edge. Service-layer throws remain defense in depth.
