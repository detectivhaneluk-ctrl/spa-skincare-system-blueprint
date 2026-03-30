# Organization-scoped client repository — minimal R1 (FOUNDATION-16)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-16 — ORGANIZATION-SCOPED-CLIENT-REPOSITORY-MINIMAL-ENFORCEMENT-R1  
**Source of truth:** FOUNDATION-06 through FOUNDATION-15 (especially F-09 `OrganizationContext`, F-11 choke points, F-13/F-14 `OrganizationRepositoryScope`, F-15 client read audit and accepted perimeter).

---

## 1) What changed

| Artifact | Role |
|----------|------|
| `Modules\Clients\Repositories\ClientRepository` | Injects `Core\Organization\OrganizationRepositoryScope`. **`find`** and **`findForUpdate`** append `branchColumnOwnedByResolvedOrganizationExistsClause('c')` on alias `c` (`clients c`). |
| `system/modules/bootstrap/register_clients.php` | Passes `OrganizationRepositoryScope` into `ClientRepository` (singleton already registered in `bootstrap.php`). |
| `system/scripts/verify_client_repository_org_scope_foundation_16_readonly.php` | Read-only proof for the two methods + DI wiring. |

No changes to `PublicClientResolutionService`, list/count/search, duplicates, notes, audit, issue flags, field repos, providers, invoices, payments, UI, or F-11 service asserts.

---

## 2) Behavior

- **`OrganizationRepositoryScope::resolvedOrganizationId()`** mirrors **`OrganizationContext::getCurrentOrganizationId()`** (positive id only). No request-derived org ids.
- When resolved organization id is **non-null**, **`find`** / **`findForUpdate`** require `clients.branch_id` to reference an active branch whose `organization_id` matches that id (EXISTS + join to active `organizations`), same pattern as F-13/F-14.
- When context is **unresolved** (null), the scope fragment is empty — queries match pre–FOUNDATION-16 SQL semantics (legacy unscoped ID load).
- **`branch_id IS NULL`:** the shared clause requires a non-null branch; when org is resolved, such rows **do not match** (fail-closed: methods return no row). Document any legacy NULL-`branch_id` data separately if it still exists in a deployment.

---

## 3) Proof commands

From `system/`:

```bash
php scripts/verify_client_repository_org_scope_foundation_16_readonly.php
php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php
```

F-11 remains the service-layer mutate guard; F-16 adds repository-layer defense in depth for the same ID loads.

---

## 4) Explicit non-goals (this wave)

- Org predicates on `list`, `count`, `findDuplicates`, `searchDuplicates`, or any method other than **`find`** / **`findForUpdate`**.
- Changes to **`ClientListProvider`**, invoice dropdowns, merge preview, or public lock-by-branch methods.

---

## 5) ZIP checkpoint

Canonical upload artifact (excludes `.env`, logs, backups, `*.log`, nested `*.zip` per project handoff rules):  
`distribution/spa-skincare-system-blueprint-FOUNDATION-16-CLIENT-REPO-R1-CHECKPOINT.zip` (produced via `handoff/build-final-zip.ps1`).
