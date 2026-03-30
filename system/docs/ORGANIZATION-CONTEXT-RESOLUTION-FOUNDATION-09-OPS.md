# Organization context resolution — FOUNDATION-09 ops

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-09  
**Depends on:** FOUNDATION-08 (`organizations`, `branches.organization_id`), FOUNDATION-07 (canonical boundary), FOUNDATION-06 (truth audit).  
**Scope:** Runtime organization context + resolver + global middleware only — **no** repository scoping, RBAC, settings, storage, subscriptions, UI.

---

## What shipped

| Piece | Location |
|--------|-----------|
| Request-scoped context | `Core\Organization\OrganizationContext` |
| Canonical resolver | `Core\Organization\OrganizationContextResolver` |
| HTTP wiring | `Core\Middleware\OrganizationContextMiddleware` immediately after `BranchContextMiddleware` in `Dispatcher` |
| DI | `system/bootstrap.php` — `OrganizationContext`, `OrganizationContextResolver` |
| Autoload | `Core\Organization\` → `system/core/Organization/` |
| Proof | `system/scripts/verify_organization_context_resolution_readonly.php` (`--json`) |

---

## Resolution rules (normative)

1. **Branch context set (`BranchContext::getCurrentBranchId()` non-null)**  
   Load `branches.organization_id` with `INNER JOIN organizations` where **both** branch and organization are active (`deleted_at IS NULL`).  
   - Success → `OrganizationContext` = that id, mode **`branch_derived`**.  
   - No row → **`DomainException`**: *Branch is not linked to an active organization.* (fail closed.)

2. **Branch context null** (guest, or HQ user with no resolved branch)  
   Count organizations with `deleted_at IS NULL`.  
   - **Exactly one** → set organization id to that row, mode **`single_active_org_fallback`**.  
   - **Zero** → organization id null, mode **`unresolved_no_active_org`**.  
   - **More than one** → organization id null, mode **`unresolved_ambiguous_orgs`** (no guess).

3. **No** `organization_id` request/session override in this wave. Organization is **derived**, not user-toggled.

---

## Branch ↔ organization safety

- When organization is **`branch_derived`**, it is **by definition** the owning org of the resolved active branch row.  
- **`OrganizationContext::assertBranchBelongsToCurrentOrganization(?int $branchOrganizationId)`** is available for defense in depth when code loads a branch row and needs to assert it matches context (optional call sites in later waves).

---

## Read-only verifier

From `system/`:

```bash
php scripts/verify_organization_context_resolution_readonly.php
php scripts/verify_organization_context_resolution_readonly.php --json
```

Reports DB counts: active organizations, whether single-org fallback is possible, branches with invalid/deleted org references, **active** branches that would fail branch-derived resolution if chosen as context, plus a static **`runtime_resolution_mode_summary`**.

Exit **0** on success (informational). Exit **1** if DB unavailable.

---

## CLI / non-HTTP

`OrganizationContextMiddleware` does not run; context remains default-unset unless a future CLI explicitly resolves (not in this wave).

---

## Related docs

- `ORGANIZATION-SCHEMA-BRANCH-OWNERSHIP-FOUNDATION-08-OPS.md`  
- `ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md`  
- `ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`
